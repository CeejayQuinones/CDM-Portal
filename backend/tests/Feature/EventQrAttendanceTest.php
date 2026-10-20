<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventAttendanceSession;
use App\Models\EventRoleAssignment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventQrAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:05:00');
        $this->fixture = $this->fixture();
    }

    public function test_admin_opens_session_student_is_forbidden_and_second_open_is_idempotent(): void
    {
        foreach ([$this->fixture['admin']] as $staff) {
            $event = $this->fixture['event'];
            Sanctum::actingAs($staff);
            $first = $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
            $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated()->assertJsonPath('data.id', $first);
        }
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertForbidden();
        $this->assertDatabaseCount('event_attendance_sessions', 1);
        $this->assertDatabaseCount('event_audit_events', 1);
    }

    public function test_close_session_invalidates_qr_and_blocks_scans(): void
    {
        $token = $this->openAndToken();
        $session = EventAttendanceSession::query()->where('event_id', $this->fixture['event']->id)->firstOrFail();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session/close', ['version' => $session->version])->assertOk()->assertJsonPath('data.status', 'closed');
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => $token])->assertConflict()->assertJsonPath('code', 'SESSION_CLOSED');
        $this->assertDatabaseHas('event_attendance_scans', ['result' => 'session_closed']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.session_closed']);
    }

    public function test_attendance_cannot_open_outside_published_event_window(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $future = $this->event('Future Event', [['audience_type' => 'all_students']]);
        $future->forceFill(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)])->save();
        $this->getJson("/api/events/{$future->id}/attendance")->assertOk()
            ->assertJsonPath('data.availability.code', 'event_not_started')
            ->assertJsonPath('data.availability.can_open', false);
        $this->postJson("/api/events/{$future->id}/attendance/session")->assertConflict();
        $draft = $this->event('Draft Event', [['audience_type' => 'all_students']]);
        $draft->forceFill(['status' => 'draft'])->save();
        $this->getJson("/api/events/{$draft->id}/attendance")->assertOk()
            ->assertJsonPath('data.availability.code', 'event_unpublished');
        $this->postJson("/api/events/{$draft->id}/attendance/session")->assertConflict();
        $past = $this->event('Ended Event', [['audience_type' => 'all_students']]);
        $past->forceFill(['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()])->save();
        $this->getJson("/api/events/{$past->id}/attendance")->assertOk()
            ->assertJsonPath('data.availability.code', 'event_ended')
            ->assertJsonPath('data.availability.can_open', false);
        $this->postJson("/api/events/{$past->id}/attendance/session")->assertConflict();
    }

    public function test_ongoing_philippine_event_requires_explicit_session_then_records_moderator_scan(): void
    {
        $admin = $this->fixture['admin'];
        Sanctum::actingAs($admin);
        $event = $this->postJson('/api/events', [
            'title' => 'Controlled Manila Attendance',
            'description' => 'Isolated attendance lifecycle fixture.',
            'venue' => 'Timezone Test Hall',
            'starts_at' => '2026-10-01T16:30:00+08:00',
            'ends_at' => '2026-10-01T18:30:00+08:00',
            'intent' => 'publish',
            'attendance_mode' => 'check_in_only',
            'attendance_methods' => ['participant_static', 'manual'],
            'audiences' => [['audience_type' => 'section', 'section_id' => $this->fixture['section']]],
        ])->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-10-01T08:30:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-10-01T10:30:00+00:00')
            ->json('data');

        $state = $this->getJson("/api/events/{$event['id']}/attendance")->assertOk()
            ->assertJsonPath('data.session', null)
            ->assertJsonPath('data.availability.code', 'session_not_started')
            ->assertJsonPath('data.availability.event_window', 'ongoing')
            ->assertJsonPath('data.availability.can_open', true);

        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event['id']}/attendance/participant-qr", ['mode' => 'static'])
            ->assertConflict()->assertJsonPath('message', 'Attendance has not been started by an Event Coordinator.');

        Sanctum::actingAs($admin);
        $session = $this->withHeader('X-CDM-Client', 'desktop')->postJson("/api/events/{$event['id']}/attendance/session")
            ->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
        $updated = $this->withHeader('X-CDM-Client', 'desktop')->putJson("/api/events/{$event['id']}", [
            'title' => 'Renamed Controlled Manila Attendance',
            'description' => $event['description'],
            'venue' => $event['venue'],
            'starts_at' => '2026-10-01T16:30:00+08:00',
            'ends_at' => '2026-10-01T18:30:00+08:00',
            'attendance_mode' => 'check_in_only',
            'attendance_methods' => ['participant_static', 'manual'],
            'audiences' => [['audience_type' => 'section', 'section_id' => $this->fixture['section']]],
            'version' => $event['version'],
        ])->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-01T08:30:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-10-01T10:30:00+00:00')
            ->json('data');
        $this->assertSame('ongoing', Event::query()->findOrFail($updated['id'])->presentationStatus());
        $this->withHeader('X-CDM-Client', 'desktop')->getJson("/api/events/{$event['id']}/attendance")->assertOk()
            ->assertJsonPath('data.availability.code', 'open')
            ->assertJsonPath('data.session.id', $session['id']);

        $this->withHeader('X-CDM-Client', 'desktop')->postJson("/api/events/{$event['id']}/personnel", [
            'user_id' => $this->fixture['registeredModerator']->id,
            'responsibility' => EventRoleAssignment::REGISTERED_MODERATOR,
        ])->assertCreated();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $token = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event['id']}/attendance/participant-qr", ['mode' => 'static'])
            ->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['registeredModerator']);
        $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event['id']}/attendance/operator-scan", ['token' => $token])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');

        Sanctum::actingAs($admin);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson("/api/events/{$event['id']}/attendance/session/close", ['version' => $session['version']])->assertOk();
        $this->withHeader('X-CDM-Client', 'desktop')->getJson("/api/events/{$event['id']}/attendance")->assertOk()
            ->assertJsonPath('data.availability.code', 'session_closed')
            ->assertJsonPath('data.availability.can_open', false);
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event['id']}/attendance/participant-qr", ['mode' => 'static'])
            ->assertConflict()->assertJsonPath('message', 'Attendance was closed by an Event Coordinator.');
        $this->assertDatabaseHas('event_attendances', [
            'event_id' => $event['id'],
            'student_id' => $this->fixture['eligible']->id,
            'recorded_by' => $this->fixture['registeredModerator']->id,
            'check_in_method' => 'participant_static',
        ]);
    }

    public function test_valid_qr_records_student_and_repeat_is_idempotent(): void
    {
        $token = $this->openAndToken();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $endpoint = '/api/events/'.$this->fixture['event']->id.'/attendance/scan';
        $this->postJson($endpoint, ['token' => $token])->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED')->assertJsonPath('data.attendance.status', 'present');
        $this->postJson($endpoint, ['token' => $token])->assertOk()->assertJsonPath('code', 'ALREADY_RECORDED');
        $this->assertDatabaseCount('event_attendances', 1);
        $this->assertDatabaseHas('event_attendances', [
            'student_id' => $this->fixture['eligible']->id, 'course_id_at_attendance' => $this->fixture['course'],
            'year_level_at_attendance' => 1, 'section_id_at_attendance' => $this->fixture['section'],
        ]);
        $this->assertDatabaseHas('event_attendance_scans', ['result' => 'accepted']);
        $this->assertDatabaseHas('event_attendance_scans', ['result' => 'duplicate']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.recorded']);
    }

    public function test_check_in_and_out_uses_rotated_tokens_server_timestamps_and_minimum_participation(): void
    {
        $event = $this->fixture['event'];
        $event->forceFill(['attendance_mode' => 'check_in_and_out', 'late_threshold_minutes' => 10, 'minimum_participation_minutes' => 30])->save();
        $token = $this->openAndToken();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $endpoint = "/api/events/{$event->id}/attendance/scan";
        $this->postJson($endpoint, ['token' => $token])->assertCreated()
            ->assertJsonPath('data.attendance.completion_status', 'in_progress');
        $this->postJson($endpoint, ['token' => $token])->assertOk()->assertJsonPath('code', 'ALREADY_RECORDED')
            ->assertJsonPath('data.attendance.checked_out_at', null);

        $this->travel(35)->minutes();
        Sanctum::actingAs($this->fixture['admin']);
        $checkoutToken = $this->postJson("/api/events/{$event->id}/attendance/token")->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson($endpoint, ['token' => $checkoutToken])->assertOk()->assertJsonPath('code', 'ATTENDANCE_CHECKED_OUT')
            ->assertJsonPath('data.attendance.participation_minutes', 35)
            ->assertJsonPath('data.attendance.completion_status', 'completed');

        Sanctum::actingAs($this->fixture['admin']);
        $shortToken = $this->postJson("/api/events/{$event->id}/attendance/token")->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['requestedTarget']->user);
        $this->postJson($endpoint, ['token' => $shortToken])->assertCreated()->assertJsonPath('data.attendance.status', 'late');
        $this->travel(5)->minutes();
        Sanctum::actingAs($this->fixture['admin']);
        $shortCheckoutToken = $this->postJson("/api/events/{$event->id}/attendance/token")->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['requestedTarget']->user);
        $this->postJson($endpoint, ['token' => $shortCheckoutToken])->assertOk()
            ->assertJsonPath('data.attendance.participation_minutes', 5)
            ->assertJsonPath('data.attendance.completion_status', 'incomplete');
        $this->assertDatabaseCount('event_attendances', 2);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.checked_out']);
    }

    public function test_participant_qr_cannot_check_out_before_check_in(): void
    {
        $event = $this->fixture['event'];
        $event->forceFill(['attendance_mode' => 'check_in_and_out'])->save();
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $token = $this->postJson("/api/events/{$event->id}/attendance/participant-qr", ['mode' => 'dynamic'])->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/operator-scan", ['token' => $token, 'workflow' => 'dynamic', 'action' => 'check_out'])
            ->assertConflict()->assertJsonPath('message', 'Check-in must be recorded before check-out.');
        $this->assertDatabaseCount('event_attendances', 0);
    }

    public function test_operator_scanner_derives_static_and_dynamic_workflows_from_signed_participant_qr(): void
    {
        $event = $this->fixture['event'];
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$event->id.'/personnel', [
            'user_id' => $this->fixture['registeredModerator']->id,
            'responsibility' => EventRoleAssignment::EVENT_STAFF,
        ])->assertCreated();
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();

        Sanctum::actingAs($this->fixture['eligible']->user);
        $dynamic = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/participant-qr", ['mode' => 'dynamic'])
            ->assertOk()->assertJsonPath('data.mode', 'dynamic')->json('data.token');

        Sanctum::actingAs($this->fixture['registeredModerator']);
        $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/operator-scan", ['token' => $dynamic, 'action' => 'auto'])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');

        Sanctum::actingAs($this->fixture['requestedTarget']->user);
        $static = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/participant-qr", ['mode' => 'static'])
            ->assertOk()->assertJsonPath('data.mode', 'static')->json('data.token');

        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')
            ->postJson("/api/events/{$event->id}/attendance/operator-scan", ['token' => $static])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');
        $this->assertDatabaseCount('event_attendances', 2);
    }

    public function test_participant_qr_rejects_wrong_event_and_expiration_and_repeats_idempotently(): void
    {
        $event = $this->fixture['event'];
        $other = $this->event('Participant QR Other Event', [['audience_type' => 'all_students']]);
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();
        $this->postJson("/api/events/{$other->id}/attendance/session")->assertCreated();

        Sanctum::actingAs($this->fixture['eligible']->user);
        $wrongEventToken = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$other->id}/attendance/participant-qr", ['mode' => 'static'])
            ->assertOk()->json('data.token');
        $expiringToken = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/participant-qr", ['mode' => 'dynamic'])
            ->assertOk()->json('data.token');

        Sanctum::actingAs($this->fixture['admin']);
        $endpoint = "/api/events/{$event->id}/attendance/operator-scan";
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($endpoint, ['token' => $wrongEventToken])
            ->assertUnprocessable()->assertJsonPath('code', 'WRONG_EVENT');
        $this->travel(46)->seconds();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($endpoint, ['token' => $expiringToken])
            ->assertStatus(410)->assertJsonPath('code', 'EXPIRED_PARTICIPANT_QR');

        Sanctum::actingAs($this->fixture['requestedTarget']->user);
        $valid = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/participant-qr", ['mode' => 'dynamic'])
            ->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($endpoint, ['token' => $valid])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($endpoint, ['token' => $valid])
            ->assertOk()->assertJsonPath('code', 'ALREADY_PROCESSED');
        $this->assertDatabaseCount('event_attendances', 1);
    }

    public function test_operator_scanner_authorizes_the_signed_workflow_and_reports_contract_errors(): void
    {
        $event = $this->fixture['event'];
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$event->id.'/personnel', [
            'user_id' => $this->fixture['registeredModerator']->id,
            'responsibility' => EventRoleAssignment::REGISTERED_MODERATOR,
        ])->assertCreated();
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();

        Sanctum::actingAs($this->fixture['eligible']->user);
        $dynamic = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/participant-qr", ['mode' => 'dynamic'])
            ->assertOk()->json('data.token');

        Sanctum::actingAs($this->fixture['registeredModerator']);
        $endpoint = "/api/events/{$event->id}/attendance/operator-scan";
        $this->withHeader('X-CDM-Client', 'mobile')->postJson($endpoint, ['token' => $dynamic])
            ->assertForbidden()->assertJsonPath('message', 'This Event responsibility cannot use the selected QR workflow.');

        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($endpoint, ['token' => $dynamic, 'workflow' => 'static'])
            ->assertUnprocessable()->assertJsonPath('code', 'QR_WORKFLOW_MISMATCH')
            ->assertJsonPath('message', 'This is a Dynamic participant QR. Use the matching scanner workflow.');
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($endpoint, ['token' => 'malformed'])
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_PARTICIPANT_QR')
            ->assertJsonPath('message', 'This participant QR is malformed or has an invalid signature.');
        $this->assertDatabaseCount('event_attendances', 0);
    }

    public function test_each_scanner_rejects_the_other_qr_contract_with_a_specific_error(): void
    {
        $event = $this->fixture['event'];
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();
        $eventQr = $this->postJson("/api/events/{$event->id}/attendance/token")
            ->assertOk()->json('data.token');

        Sanctum::actingAs($this->fixture['eligible']->user);
        $participantQr = $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/participant-qr", ['mode' => 'dynamic'])
            ->assertOk()->json('data.token');
        $this->withHeader('X-CDM-Client', 'mobile')
            ->postJson("/api/events/{$event->id}/attendance/scan", ['token' => $participantQr])
            ->assertUnprocessable()->assertJsonPath('code', 'WRONG_QR_TYPE')
            ->assertJsonPath('message', 'This is your participant QR. Show it to authorized Event personnel; do not scan it with the Student Event QR scanner.');

        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')
            ->postJson("/api/events/{$event->id}/attendance/operator-scan", ['token' => $eventQr])
            ->assertUnprocessable()->assertJsonPath('code', 'WRONG_QR_TYPE')
            ->assertJsonPath('message', 'This is an Event QR for Students to scan. The operator scanner accepts participant QRs only.');
        $this->assertDatabaseCount('event_attendances', 0);
    }

    public function test_static_event_qr_requires_scoped_staff_confirmation_and_is_idempotent(): void
    {
        $event = $this->fixture['event'];
        $event->forceFill(['attendance_methods' => ['static_event', 'manual']])->save();
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();
        $token = $this->postJson("/api/events/{$event->id}/attendance/static-token")->assertOk()
            ->assertJsonPath('data.requires_staff_confirmation', true)->json('data.token');

        Sanctum::actingAs($this->fixture['eligible']->user);
        $endpoint = "/api/events/{$event->id}/attendance/scan";
        $verification = $this->postJson($endpoint, ['token' => $token])->assertStatus(202)
            ->assertJsonPath('code', 'VERIFICATION_PENDING')->json('data.verification');
        $this->postJson($endpoint, ['token' => $token])->assertStatus(202)->assertJsonPath('data.verification.id', $verification['id']);
        $this->assertDatabaseCount('event_attendance_verifications', 1);
        $this->assertDatabaseCount('event_attendances', 0);

        $this->postJson("/api/events/{$event->id}/attendance/verifications/{$verification['id']}/approve")->assertForbidden();
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/verifications/{$verification['id']}/approve")->assertCreated()
            ->assertJsonPath('data.attendance.status', 'present');
        $this->postJson("/api/events/{$event->id}/attendance/verifications/{$verification['id']}/approve")->assertConflict();
        $this->assertDatabaseCount('event_attendances', 1);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.static_qr_confirmed']);
    }

    public function test_closing_check_out_session_marks_open_participation_incomplete(): void
    {
        $event = $this->fixture['event'];
        $event->forceFill(['attendance_mode' => 'check_in_and_out', 'minimum_participation_minutes' => 60])->save();
        $token = $this->openAndToken();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson("/api/events/{$event->id}/attendance/scan", ['token' => $token])->assertCreated();
        Sanctum::actingAs($this->fixture['admin']);
        $session = EventAttendanceSession::query()->where('event_id', $event->id)->firstOrFail();
        $this->postJson("/api/events/{$event->id}/attendance/session/close", ['version' => $session->version])->assertOk();
        $this->assertDatabaseHas('event_attendances', ['student_id' => $this->fixture['eligible']->id, 'completion_status' => 'incomplete', 'checked_out_at' => null]);
    }

    public function test_expired_tampered_rotated_and_wrong_session_tokens_are_rejected(): void
    {
        $first = $this->openAndToken();
        Sanctum::actingAs($this->fixture['admin']);
        $second = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/token')->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['eligible']->user);
        $endpoint = '/api/events/'.$this->fixture['event']->id.'/attendance/scan';
        $this->postJson($endpoint, ['token' => $first])->assertUnprocessable()->assertJsonPath('code', 'INVALID_TOKEN');
        $this->postJson($endpoint, ['token' => $second.'tampered'])->assertUnprocessable()->assertJsonPath('code', 'INVALID_TOKEN');

        Sanctum::actingAs($this->fixture['admin']);
        $expiring = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/token')->assertOk()->json('data.token');
        $this->travel(46)->seconds();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson($endpoint, ['token' => $expiring])->assertStatus(410)->assertJsonPath('code', 'EXPIRED_TOKEN');

        Carbon::setTestNow('2026-10-01 09:05:00');
        $other = $this->event('Other Event', [['audience_type' => 'all_students']]);
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$other->id}/attendance/session")->assertCreated();
        $wrong = $this->postJson("/api/events/{$other->id}/attendance/token")->assertOk()->json('data.token');
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson($endpoint, ['token' => $wrong])->assertUnprocessable()->assertJsonPath('code', 'WRONG_SESSION');
    }

    public function test_course_year_and_section_eligibility_use_authoritative_current_academic_data(): void
    {
        $cases = [
            ['course', [['audience_type' => 'course', 'course_id' => $this->fixture['course']]], $this->fixture['wrong_course']],
            ['year', [['audience_type' => 'year_level', 'year_level' => 1]], $this->fixture['wrong_year']],
            ['section', [['audience_type' => 'section', 'section_id' => $this->fixture['section']]], $this->fixture['wrong_section']],
        ];
        foreach ($cases as [$name, $audiences, $wrongStudent]) {
            $event = $this->event(ucfirst($name).' Event', $audiences);
            $token = $this->openAndToken($event);
            Sanctum::actingAs($wrongStudent->user);
            $this->postJson("/api/events/{$event->id}/attendance/scan", ['token' => $token])->assertForbidden()->assertJsonPath('code', 'NOT_ELIGIBLE');
            Sanctum::actingAs($this->fixture['eligible']->user);
            $this->postJson("/api/events/{$event->id}/attendance/scan", ['token' => $token])->assertCreated();
        }
        $this->assertDatabaseCount('admission_applicants', 0);
    }

    public function test_late_rule_applies_after_fifteen_minute_grace_period(): void
    {
        Carbon::setTestNow('2026-10-01 09:16:00');
        $token = $this->openAndToken();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => $token])->assertCreated()->assertJsonPath('data.attendance.status', 'late');
    }

    public function test_platform_policy_is_enforced_for_scanning_and_management(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertForbidden();
        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->withHeader('X-CDM-Client', 'web')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => 'invalid'])->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => 'invalid'])->assertUnprocessable();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/scan', ['token' => 'invalid'])->assertForbidden();
    }

    public function test_manual_creation_and_correction_are_authorized_reasoned_and_audited(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();
        $record = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/manual', ['student_id' => $this->fixture['eligible']->id, 'status' => 'present', 'reason' => 'Camera was unavailable.'])->assertCreated()->json('data');
        $this->postJson('/api/step-up/verify', ['password' => 'password'])->assertOk();
        $this->patchJson('/api/events/'.$this->fixture['event']->id.'/attendance/'.$record['id'], ['status' => 'excused', 'version' => $record['version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/events/'.$this->fixture['event']->id.'/attendance/'.$record['id'], ['status' => 'excused', 'version' => $record['version'], 'reason' => 'Approved medical documentation.'])->assertOk()->assertJsonPath('data.status', 'excused')->assertJsonPath('data.source', 'manual');
        $this->getJson('/api/events/'.$this->fixture['event']->id.'/attendance')->assertOk()->assertJsonPath('data.summary.eligible', 3)->assertJsonPath('data.summary.not_checked_in', 2)->assertJsonPath('data.students.data.0.attendance.status', 'excused');
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.manual_created']);
        $this->assertDatabaseHas('event_audit_events', ['action' => 'attendance.corrected']);

        Sanctum::actingAs($this->fixture['eligible']->user);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/manual', ['student_id' => $this->fixture['eligible']->id, 'status' => 'late', 'reason' => 'Unauthorized attempt.'])->assertForbidden();
    }

    public function test_coordinator_correction_requires_existing_step_up(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();
        $record = $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/manual', ['student_id' => $this->fixture['eligible']->id, 'status' => 'present', 'reason' => 'Manual fallback used.'])->assertCreated()->json('data');
        Sanctum::actingAs($this->fixture['admin']);
        $this->patchJson('/api/events/'.$this->fixture['event']->id.'/attendance/'.$record['id'], ['status' => 'late', 'version' => 1, 'reason' => 'Correcting verified arrival time.'])->assertStatus(428)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    }

    public function test_pending_moderator_has_no_scanner_privilege_and_approval_grants_registered_scanner(): void
    {
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/personnel', [
            'user_id' => $this->fixture['registeredModerator']->id,
            'responsibility' => EventRoleAssignment::REGISTERED_MODERATOR,
        ])->assertCreated();
        $this->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/session')->assertCreated();

        Sanctum::actingAs($this->fixture['requestedModerator']->user);
        $request = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/moderator-requests')
            ->assertCreated()->json('data');

        Sanctum::actingAs($this->fixture['eligible']->user);
        $static = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'static'])
            ->assertOk()->assertJsonPath('data.mode', 'static')->assertJsonPath('data.expires_at', null)->json('data.token');
        $dynamic = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'dynamic'])
            ->assertOk()->assertJsonPath('data.mode', 'dynamic')->json('data.token');

        Sanctum::actingAs($this->fixture['registeredModerator']);
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $dynamic, 'workflow' => 'dynamic'])->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $static, 'workflow' => 'static'])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');

        Sanctum::actingAs($this->fixture['requestedTarget']->user);
        $requestedDynamic = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'dynamic'])
            ->assertOk()->json('data.token');
        $requestedStatic = $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/participant-qr', ['mode' => 'static'])
            ->assertOk()->json('data.token');

        Sanctum::actingAs($this->fixture['requestedModerator']->user);
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedStatic, 'workflow' => 'static'])->assertForbidden();
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedDynamic, 'workflow' => 'dynamic'])
            ->assertForbidden();

        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->fixture['event']->id.'/moderator-requests/'.$request['id'].'/approve')->assertOk();
        Sanctum::actingAs($this->fixture['requestedModerator']->user);
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedStatic, 'workflow' => 'static'])
            ->assertCreated()->assertJsonPath('code', 'ATTENDANCE_RECORDED');
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedStatic])
            ->assertOk()->assertJsonPath('code', 'ALREADY_RECORDED');
        $this->assertDatabaseHas('event_attendances', [
            'event_id' => $this->fixture['event']->id,
            'student_id' => $this->fixture['requestedTarget']->id,
            'recorded_by' => $this->fixture['requestedModerator']->user_id,
            'source' => 'qr',
            'check_in_method' => 'participant_static',
        ]);
        $this->assertSame(1, EventAttendance::query()->where('event_id', $this->fixture['event']->id)->where('student_id', $this->fixture['requestedTarget']->id)->count());
        $this->assertDatabaseHas('event_audit_events', [
            'event_id' => $this->fixture['event']->id,
            'actor_user_id' => $this->fixture['requestedModerator']->user_id,
            'action' => 'attendance.operator_qr_recorded',
        ]);

        Sanctum::actingAs($this->fixture['admin']);
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/events/'.$this->fixture['event']->id.'/moderator-requests/'.$request['id'].'/revoke', ['reason' => 'The validation assignment has ended.'])->assertOk();
        Sanctum::actingAs($this->fixture['requestedModerator']->user);
        $this->withHeader('X-CDM-Client', 'mobile')->postJson('/api/events/'.$this->fixture['event']->id.'/attendance/operator-scan', ['token' => $requestedDynamic, 'workflow' => 'dynamic'])->assertForbidden();
    }

    public function test_promotional_api_is_public_safe_and_contains_no_operational_data(): void
    {
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-promotions')->assertUnauthorized();
        Sanctum::actingAs($this->user(Role::GUEST));
        $response = $this->withHeader('X-CDM-Client', 'web')->getJson('/api/event-promotions')->assertOk()
            ->assertJsonPath('data.data.0.title', 'General Assembly')
            ->assertJsonMissingPath('data.data.0.audiences')
            ->assertJsonMissingPath('data.data.0.capabilities')
            ->assertJsonMissingPath('data.data.0.attendance')
            ->assertJsonMissingPath('data.data.0.role_assignments')
            ->assertJsonMissingPath('data.data.0.qr_token');
        $this->assertSame([
            'id', 'title', 'description', 'venue', 'starts_at', 'ends_at', 'status', 'organizer', 'public_audience', 'image_url',
        ], array_keys($response->json('data.data.0')));
        $this->withHeader('X-CDM-Client', 'mobile')->getJson('/api/event-promotions')->assertForbidden();
    }

    private function openAndToken(?Event $event = null): string
    {
        $event ??= $this->fixture['event'];
        Sanctum::actingAs($this->fixture['admin']);
        $this->postJson("/api/events/{$event->id}/attendance/session")->assertCreated();

        return $this->postJson("/api/events/{$event->id}/attendance/token")->assertOk()->assertJsonPath('data.refresh_after_seconds', 30)->json('data.token');
    }

    private function fixture(): array
    {
        $registrar = $this->user(Role::REGISTRAR_STAFF);
        $admin = $this->user(Role::ADMIN);
        $department = DB::table('departments')->insertGetId(['department_code' => 'ICS', 'department_name' => 'Computing', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $course = $this->course($department, 'BSIT');
        $otherCourse = $this->course($department, 'BSCS');
        $year = DB::table('academic_years')->insertGetId(['school_year' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $semester = DB::table('semesters')->insertGetId(['semester_name' => 'First Semester', 'semester_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $section = $this->section($course, $year, $semester, 'BSIT-1A', 1);
        $otherSection = $this->section($course, $year, $semester, 'BSIT-1B', 1);
        $yearTwoSection = $this->section($course, $year, $semester, 'BSIT-2A', 2);
        $otherCourseSection = $this->section($otherCourse, $year, $semester, 'BSCS-1A', 1);
        $eligible = $this->student($course, $section, $year, $semester, '0001');
        $requestedTarget = $this->student($course, $section, $year, $semester, '0005');
        $requestedModerator = $this->student($course, $section, $year, $semester, '0006');
        $registeredModerator = $this->user(Role::PROFESSOR);
        $wrongSection = $this->student($course, $otherSection, $year, $semester, '0002');
        $wrongYear = $this->student($course, $yearTwoSection, $year, $semester, '0003');
        $wrongCourse = $this->student($otherCourse, $otherCourseSection, $year, $semester, '0004');
        $event = $this->event('General Assembly', [['audience_type' => 'section', 'section_id' => $section]]);

        return ['registrar' => $registrar, 'admin' => $admin, 'course' => $course, 'section' => $section, 'eligible' => $eligible, 'requestedTarget' => $requestedTarget, 'requestedModerator' => $requestedModerator, 'registeredModerator' => $registeredModerator, 'wrong_section' => $wrongSection, 'wrong_year' => $wrongYear, 'wrong_course' => $wrongCourse, 'event' => $event];
    }

    private function user(string $role): User
    {
        $roleModel = Role::query()->firstOrCreate(['role_name' => $role], ['description' => $role]);

        return User::query()->create(['username' => 'event_user_'.(++$this->sequence), 'password' => Hash::make('password'), 'role_id' => $roleModel->id, 'status' => 'active', 'is_first_login' => false]);
    }

    private function course(int $department, string $code): int
    {
        return DB::table('courses')->insertGetId(['department_id' => $department, 'course_code' => $code, 'course_name' => $code, 'years' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function section(int $course, int $year, int $semester, string $name, int $level): int
    {
        return DB::table('sections')->insertGetId(['course_id' => $course, 'academic_year_id' => $year, 'semester_id' => $semester, 'section_name' => $name, 'year_level' => $level, 'capacity' => 40, 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function student(int $course, int $section, int $year, int $semester, string $number): Student
    {
        $user = $this->user(Role::STUDENT);
        $profile = DB::table('user_profiles')->insertGetId(['user_id' => $user->id, 'first_name' => 'Student', 'last_name' => $number, 'gender' => 'Prefer not to say', 'nationality' => 'Filipino', 'created_at' => now(), 'updated_at' => now()]);
        $curriculum = DB::table('curriculums')->insertGetId(['course_id' => $course, 'curriculum_code' => 'CUR-'.$number, 'curriculum_name' => 'Curriculum '.$number, 'effective_year' => 2026, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $student = Student::query()->create(['user_id' => $user->id, 'user_profile_id' => $profile, 'course_id' => $course, 'curriculum_id' => $curriculum, 'student_number' => '2026-'.$number, 'admission_date' => '2026-08-01', 'year_level' => DB::table('sections')->where('id', $section)->value('year_level'), 'student_status' => 'regular']);
        DB::table('enrollments')->insert(['student_id' => $student->id, 'section_id' => $section, 'academic_year_id' => $year, 'semester_id' => $semester, 'enrollment_date' => '2026-08-01', 'status' => 'enrolled', 'created_at' => now(), 'updated_at' => now()]);

        return $student;
    }

    private function event(string $title, array $audiences): Event
    {
        $adminId = User::query()->whereHas('role', fn ($query) => $query->where('role_name', Role::ADMIN))->value('id');
        $event = Event::query()->create(['title' => $title, 'venue' => $title.' Hall', 'venue_key' => strtolower($title).' hall', 'starts_at' => '2026-10-01 09:00:00', 'ends_at' => '2026-10-01 11:00:00', 'status' => 'published', 'created_by' => $adminId, 'updated_by' => $adminId]);
        $event->audiences()->createMany($audiences);

        return $event;
    }
}
