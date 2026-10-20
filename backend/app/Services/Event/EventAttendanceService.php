<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventAttendanceScan;
use App\Models\EventAttendanceSession;
use App\Models\EventAttendanceVerification;
use App\Models\Student;
use App\Models\User;
use App\Notifications\EventNotice;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventAttendanceService
{
    public const TOKEN_LIFETIME_SECONDS = 45;

    public const TOKEN_REFRESH_SECONDS = 30;

    public const LATE_AFTER_MINUTES = 15;

    public const PARTICIPANT_DYNAMIC_LIFETIME_SECONDS = 45;

    public function __construct(private readonly EventAudienceResolver $audiences, private readonly EventAuditWriter $audit, private readonly EventCertificateService $certificates) {}

    public function open(Event $event, User $actor): EventAttendanceSession
    {
        return DB::transaction(function () use ($event, $actor): EventAttendanceSession {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $this->assertActiveWindow($lockedEvent);
            $existing = EventAttendanceSession::query()->where('event_id', $lockedEvent->id)->lockForUpdate()->first();
            if ($existing) {
                abort_if($existing->status === 'closed', 409, 'Attendance for this Event has already been closed.');

                return $existing;
            }
            $session = EventAttendanceSession::query()->create([
                'event_id' => $lockedEvent->id, 'opened_by' => $actor->id, 'opened_at' => now(), 'status' => 'open',
            ]);
            $this->audit->write($lockedEvent, $actor, 'attendance.session_opened', ['session_id' => $session->id]);

            return $session;
        }, 3);
    }

    public function close(Event $event, int $version, User $actor): EventAttendanceSession
    {
        return DB::transaction(function () use ($event, $version, $actor): EventAttendanceSession {
            $session = EventAttendanceSession::query()->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'closed') {
                return $session;
            }
            if ($session->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Attendance changed after you opened it. Reload and try again.']);
            }
            $incomplete = EventAttendance::query()->where('attendance_session_id', $session->id)
                ->where('completion_status', 'in_progress')->update(['completion_status' => 'incomplete', 'version' => DB::raw('version + 1')]);
            EventAttendanceVerification::query()->where('attendance_session_id', $session->id)->where('status', 'pending')->update([
                'status' => 'rejected', 'active_request_key' => null, 'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(), 'review_reason' => 'Attendance session closed before confirmation.',
            ]);
            $session->forceFill([
                'status' => 'closed', 'closed_at' => now(), 'qr_token_fingerprint' => null,
                'qr_expires_at' => null, 'static_qr_token_fingerprint' => null, 'version' => $session->version + 1,
            ])->save();
            $this->audit->write($event, $actor, 'attendance.session_closed', ['session_id' => $session->id, 'incomplete_records' => $incomplete]);

            return $session;
        }, 3);
    }

    public function token(Event $event): array
    {
        abort_unless($event->supportsAttendanceMethod('dynamic_event'), 409, 'Dynamic Event QR is disabled for this Event.');

        return DB::transaction(function () use ($event): array {
            $session = EventAttendanceSession::query()->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'open', 409, 'Attendance is closed.');
            $this->assertActiveWindow($event);
            $issuedAt = now();
            $expiresAt = $issuedAt->copy()->addSeconds(self::TOKEN_LIFETIME_SECONDS);
            $version = $session->token_version + 1;
            $payload = json_encode([
                'v' => 1, 'event' => $event->id, 'session' => $session->id, 'version' => $version,
                'issued_at' => $issuedAt->timestamp, 'expires_at' => $expiresAt->timestamp,
                'nonce' => bin2hex(random_bytes(16)),
            ], JSON_THROW_ON_ERROR);
            $token = Crypt::encryptString($payload);
            $session->forceFill([
                'qr_token_fingerprint' => hash('sha256', $token), 'qr_expires_at' => $expiresAt,
                'token_version' => $version,
            ])->save();

            return ['token' => $token, 'mode' => 'dynamic', 'expires_at' => $expiresAt->toIso8601String(), 'refresh_after_seconds' => self::TOKEN_REFRESH_SECONDS];
        }, 3);
    }

    public function staticToken(Event $event): array
    {
        abort_unless($event->supportsAttendanceMethod('static_event'), 409, 'Static Event QR is disabled for this Event.');

        return DB::transaction(function () use ($event): array {
            $session = EventAttendanceSession::query()->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'open', 409, 'Attendance is closed.');
            $this->assertActiveWindow($event);
            $token = $this->signPayload([
                'v' => 1, 'purpose' => 'event_static', 'event' => $event->id,
                'session' => $session->id, 'version' => $session->static_token_version,
            ]);
            $session->forceFill(['static_qr_token_fingerprint' => hash('sha256', $token)])->save();

            return ['token' => $token, 'mode' => 'static', 'expires_at' => null, 'requires_staff_confirmation' => true];
        }, 3);
    }

    public function scan(Event $event, Student $student, string $token, string $client): array
    {
        $fingerprint = hash('sha256', $token);
        $signed = $this->verifySignedPayload($token);
        if (($signed['purpose'] ?? null) === 'event_static') {
            return $this->requestStaticVerification($event, $student, $signed, $fingerprint, $client);
        }
        if (($signed['purpose'] ?? null) === 'event_participant') {
            return $this->rejected($event, $student, null, 'wrong_qr_type', $fingerprint, $client, 'This is your participant QR. Show it to authorized Event personnel; do not scan it with the Student Event QR scanner.', 422);
        }
        abort_unless($event->supportsAttendanceMethod('dynamic_event'), 409, 'Dynamic Event QR is disabled for this Event.');
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return $this->rejected($event, $student, null, 'invalid_token', $fingerprint, $client, 'This QR code is invalid.', 422);
        }
        $session = isset($payload['session']) ? EventAttendanceSession::query()->find($payload['session']) : null;
        if (! $session || (int) ($payload['event'] ?? 0) !== $event->id || $session->event_id !== $event->id) {
            return $this->rejected($event, $student, $session, 'wrong_session', $fingerprint, $client, 'This QR code belongs to another Event or session.', 422);
        }
        if ($session->status !== 'open') {
            return $this->rejected($event, $student, $session, 'session_closed', $fingerprint, $client, 'Attendance is already closed.', 409);
        }
        if ((int) ($payload['expires_at'] ?? 0) < now()->timestamp || ! $session->qr_expires_at || now()->greaterThan($session->qr_expires_at)) {
            return $this->rejected($event, $student, $session, 'expired_token', $fingerprint, $client, 'QR expired. Scan the current code displayed by Event personnel.', 410);
        }
        if (! hash_equals((string) $session->qr_token_fingerprint, $fingerprint) || (int) ($payload['version'] ?? -1) !== $session->token_version) {
            return $this->rejected($event, $student, $session, 'invalid_token', $fingerprint, $client, 'This QR code has rotated. Scan the current code.', 422);
        }
        if (! $this->eventAcceptsAttendance($event)) {
            return $this->rejected($event, $student, $session, 'event_not_active', $fingerprint, $client, 'This Event is not accepting attendance now.', 409);
        }
        if (! $this->audiences->isStudentEligible($event, $student)) {
            return $this->rejected($event, $student, $session, 'not_eligible', $fingerprint, $client, 'You are not included in this Event audience.', 403);
        }

        return DB::transaction(function () use ($event, $student, $session, $fingerprint, $client, $payload): array {
            $locked = EventAttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== 'open') {
                return $this->rejected($event, $student, $locked, 'session_closed', $fingerprint, $client, 'Attendance is already closed.', 409);
            }
            if (! hash_equals((string) $locked->qr_token_fingerprint, $fingerprint) || (int) ($payload['version'] ?? -1) !== $locked->token_version || ! $locked->qr_expires_at || now()->greaterThan($locked->qr_expires_at)) {
                return $this->rejected($event, $student, $locked, 'expired_token', $fingerprint, $client, 'QR expired. Scan the current code displayed by Event personnel.', 410);
            }
            if (EventAttendanceScan::query()->where('event_id', $event->id)->where('student_id', $student->id)->where('token_fingerprint', $fingerprint)->where('result', 'accepted')->exists()) {
                $existing = EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->first();
                $this->recordScan($event, $student, $locked, 'duplicate', $fingerprint, $client);

                return ['http' => 200, 'code' => 'ALREADY_RECORDED', 'message' => 'This QR scan was already processed.', 'attendance' => $existing];
            }
            $student->loadMissing('user');
            $result = $this->applyAttendanceAction($event, $student, $locked, 'qr', 'dynamic_event', $student->user, 'auto', 'attendance.recorded');
            $this->recordScan($event, $student, $locked, str_starts_with($result['code'], 'ALREADY_') ? 'duplicate' : 'accepted', $fingerprint, $client, ['action' => $result['action'] ?? null]);

            return $result;
        }, 3);
    }

    public function studentIsEligible(Event $event, Student $student): bool
    {
        return $this->audiences->isStudentEligible($event, $student);
    }

    public function participantQr(Event $event, Student $student, string $mode): array
    {
        $method = $mode === 'dynamic' ? 'participant_dynamic' : 'participant_static';
        abort_unless($event->supportsAttendanceMethod($method), 409, ucfirst($mode).' participant QR is disabled for this Event.');
        $this->assertActiveWindow($event);
        $session = EventAttendanceSession::query()->where('event_id', $event->id)->first();
        abort_if($session === null, 409, 'Attendance has not been started by an Event Coordinator.');
        abort_unless($session->status === 'open', 409, 'Attendance was closed by an Event Coordinator.');
        abort_unless($this->audiences->isStudentEligible($event, $student), 403, 'You are not included in this Event audience.');
        $expiresAt = $mode === 'dynamic' ? now()->addSeconds(self::PARTICIPANT_DYNAMIC_LIFETIME_SECONDS) : null;
        $payload = ['v' => 1, 'purpose' => 'event_participant', 'mode' => $mode, 'event' => $event->id, 'session' => $session->id, 'student' => $student->id];
        if ($expiresAt) {
            $payload += ['issued_at' => now()->timestamp, 'expires_at' => $expiresAt->timestamp, 'nonce' => bin2hex(random_bytes(16))];
        }

        return ['token' => $this->signPayload($payload), 'mode' => $mode, 'expires_at' => $expiresAt?->toIso8601String()];
    }

    public function participantQrMode(string $token): ?string
    {
        $payload = $this->verifySignedPayload($token);
        $mode = $payload['mode'] ?? null;

        return ($payload['purpose'] ?? null) === 'event_participant' && in_array($mode, ['static', 'dynamic'], true)
            ? $mode
            : null;
    }

    public function operatorScan(Event $event, User $actor, string $token, string $workflow, string $client, string $action = 'auto'): array
    {
        $fingerprint = hash('sha256', $token);
        $payload = $this->verifySignedPayload($token);
        $tokenWorkflow = $this->participantQrMode($token);
        if (! $payload || ! $tokenWorkflow) {
            if ($this->isEventQrToken($token)) {
                return ['http' => 422, 'code' => 'WRONG_QR_TYPE', 'message' => 'This is an Event QR for Students to scan. The operator scanner accepts participant QRs only.', 'attendance' => null];
            }

            return ['http' => 422, 'code' => 'INVALID_PARTICIPANT_QR', 'message' => 'This participant QR is malformed or has an invalid signature.', 'attendance' => null];
        }
        if ($workflow !== 'auto' && $workflow !== $tokenWorkflow) {
            return ['http' => 422, 'code' => 'QR_WORKFLOW_MISMATCH', 'message' => 'This is a '.ucfirst($tokenWorkflow).' participant QR. Use the matching scanner workflow.', 'attendance' => null];
        }
        $workflow = $tokenWorkflow;
        $method = $workflow === 'dynamic' ? 'participant_dynamic' : 'participant_static';
        abort_unless($event->supportsAttendanceMethod($method), 409, ucfirst($workflow).' participant QR is disabled for this Event.');
        if ((int) ($payload['event'] ?? 0) !== $event->id) {
            return ['http' => 422, 'code' => 'WRONG_EVENT', 'message' => 'This participant QR belongs to another Event.', 'attendance' => null];
        }
        if ($workflow === 'dynamic' && (int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            return ['http' => 410, 'code' => 'EXPIRED_PARTICIPANT_QR', 'message' => 'This dynamic participant QR has expired.', 'attendance' => null];
        }
        $student = Student::query()->with('user')->find($payload['student'] ?? 0);
        $session = EventAttendanceSession::query()->find($payload['session'] ?? 0);
        if (! $student || ! $session || $session->event_id !== $event->id || $session->status !== 'open') {
            return ['http' => 409, 'code' => 'SESSION_CLOSED', 'message' => 'Attendance is closed or the participant QR is no longer valid.', 'attendance' => null];
        }
        if (! $this->audiences->isStudentEligible($event, $student)) {
            return ['http' => 403, 'code' => 'NOT_ELIGIBLE', 'message' => 'This Student is not included in the Event audience.', 'attendance' => null];
        }
        if (! $this->eventAcceptsAttendance($event)) {
            return ['http' => 409, 'code' => 'EVENT_NOT_ACTIVE', 'message' => 'This Event is not accepting attendance now.', 'attendance' => null];
        }

        return DB::transaction(function () use ($event, $actor, $student, $session, $fingerprint, $client, $workflow, $action): array {
            $locked = EventAttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== 'open') {
                return ['http' => 409, 'code' => 'SESSION_CLOSED', 'message' => 'Attendance is already closed.', 'attendance' => null];
            }
            if ($workflow === 'dynamic' && EventAttendanceScan::query()->where('event_id', $event->id)->where('student_id', $student->id)->where('token_fingerprint', $fingerprint)->where('result', 'accepted')->exists()) {
                $existing = EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->first();
                $this->recordScan($event, $student, $locked, 'duplicate', $fingerprint, $client, ['workflow' => $workflow, 'operator_user_id' => $actor->id]);

                return ['http' => 200, 'code' => 'ALREADY_PROCESSED', 'message' => 'This participant QR was already processed.', 'attendance' => $existing];
            }
            $result = $this->applyAttendanceAction($event, $student, $locked, 'qr', 'participant_'.$workflow, $actor, $action, 'attendance.operator_qr_recorded', ucfirst($workflow).' participant QR scanned by assigned Event personnel.');
            $this->recordScan($event, $student, $locked, str_starts_with($result['code'], 'ALREADY_') ? 'duplicate' : 'accepted', $fingerprint, $client, ['workflow' => $workflow, 'operator_user_id' => $actor->id, 'action' => $result['action'] ?? null]);

            return $result;
        }, 3);
    }

    public function manual(Event $event, Student $student, string $status, string $reason, User $actor): EventAttendance
    {
        abort_unless($event->supportsAttendanceMethod('manual'), 409, 'Manual attendance is disabled for this Event.');

        return DB::transaction(function () use ($event, $student, $status, $reason, $actor): EventAttendance {
            $session = EventAttendanceSession::query()->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'open', 409, 'Attendance is closed.');
            $this->assertActiveWindow($event);
            abort_unless($this->audiences->isStudentEligible($event, $student), 422, 'The Student is not eligible for this Event.');
            abort_if(EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->exists(), 409, 'Attendance already exists. Use correction instead.');
            $checkedIn = in_array($status, ['present', 'late'], true) ? now() : null;
            $attendance = EventAttendance::query()->create([
                'event_id' => $event->id, 'attendance_session_id' => $session->id, 'student_id' => $student->id,
                'status' => $status, 'checked_in_at' => $checkedIn, 'source' => 'manual', 'check_in_method' => $checkedIn ? 'manual' : null, 'recorded_by' => $actor->id,
                'remarks' => $reason, 'completion_status' => $checkedIn && $event->attendance_mode === 'check_in_and_out' ? 'in_progress' : 'completed',
                ...$this->academicSnapshot($student),
            ]);
            $this->audit->write($event, $actor, 'attendance.manual_created', ['attendance_id' => $attendance->id, 'student_id' => $student->id, 'status' => $status, 'source' => 'manual', 'reason' => $reason]);
            $student->loadMissing('user');
            $student->user->notify(new EventNotice('attendance_recorded', 'Your attendance for '.$event->title.' was recorded manually.', $attendance->id, $event->id));

            return $attendance;
        }, 3);
    }

    public function checkout(Event $event, EventAttendance $attendance, User $actor): array
    {
        return DB::transaction(function () use ($event, $attendance, $actor): array {
            abort_unless($attendance->event_id === $event->id, 404);
            $session = EventAttendanceSession::query()->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'open', 409, 'Attendance is closed.');
            $student = Student::query()->with('user')->findOrFail($attendance->student_id);

            return $this->applyAttendanceAction($event, $student, $session, 'manual', 'manual', $actor, 'check_out', 'attendance.checked_out', 'Checked out by assigned Event personnel.');
        }, 3);
    }

    public function reviewVerification(Event $event, EventAttendanceVerification $verification, string $decision, ?string $reason, User $actor): array
    {
        return DB::transaction(function () use ($event, $verification, $decision, $reason, $actor): array {
            $locked = EventAttendanceVerification::query()->lockForUpdate()->findOrFail($verification->id);
            abort_unless($locked->event_id === $event->id, 404);
            abort_unless($locked->status === 'pending', 409, 'This verification request has already been reviewed.');
            $locked->forceFill([
                'status' => $decision === 'approve' ? 'approved' : 'rejected', 'active_request_key' => null,
                'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason,
            ])->save();
            if ($decision === 'reject') {
                $this->audit->write($event, $actor, 'attendance.static_qr_rejected', ['verification_id' => $locked->id, 'student_id' => $locked->student_id, 'reason' => $reason]);

                return ['http' => 200, 'code' => 'VERIFICATION_REJECTED', 'message' => 'Attendance verification rejected.', 'attendance' => null, 'verification' => $locked];
            }
            $session = EventAttendanceSession::query()->lockForUpdate()->findOrFail($locked->attendance_session_id);
            abort_unless($session->status === 'open', 409, 'Attendance is closed.');
            $student = Student::query()->with('user')->findOrFail($locked->student_id);
            $result = $this->applyAttendanceAction($event, $student, $session, 'qr', 'static_event', $actor, $locked->action, 'attendance.static_qr_confirmed', 'Static Event QR confirmed by assigned Event personnel.');
            $result['verification'] = $locked;

            return $result;
        }, 3);
    }

    public function verifications(Event $event): array
    {
        return EventAttendanceVerification::query()->where('event_id', $event->id)->where('status', 'pending')
            ->with('student.userProfile')->oldest('requested_at')->get()
            ->map(fn (EventAttendanceVerification $verification) => $this->serializeVerification($verification))->all();
    }

    public function correct(Event $event, EventAttendance $attendance, string $status, string $reason, int $version, User $actor): EventAttendance
    {
        $corrected = DB::transaction(function () use ($event, $attendance, $status, $reason, $version, $actor): EventAttendance {
            $locked = EventAttendance::query()->lockForUpdate()->findOrFail($attendance->id);
            abort_unless($locked->event_id === $event->id, 404);
            if ($locked->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Attendance changed after you opened it. Reload and try again.']);
            }
            $old = $locked->status;
            $locked->forceFill(['status' => $status, 'source' => 'manual', 'check_in_method' => $locked->checked_in_at ? 'manual' : null, 'recorded_by' => $actor->id, 'remarks' => $reason, 'version' => $locked->version + 1])->save();
            $this->audit->write($event, $actor, 'attendance.corrected', ['attendance_id' => $locked->id, 'student_id' => $locked->student_id, 'old_status' => $old, 'new_status' => $status, 'reason' => $reason]);

            return $locked;
        }, 3);
        $student = Student::query()->findOrFail($corrected->student_id);
        $this->certificates->reconcile($event, $student, $actor);

        return $corrected;
    }

    public function state(Event $event, ?string $search = null, int $perPage = 50): array
    {
        $eligible = $this->audiences->eligibleStudents($event);
        $eligibleCount = (clone $eligible)->count();
        $attendanceCounts = EventAttendance::query()->where('event_id', $event->id)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $students = $eligible->when($search, fn ($query, $value) => $query->where(function ($inner) use ($value): void {
            $inner->where('student_number', 'like', "%$value%")->orWhereHas('userProfile', fn ($profile) => $profile->where('first_name', 'like', "%$value%")->orWhere('last_name', 'like', "%$value%"));
        }))->with(['userProfile', 'course:id,course_code', 'enrollments' => fn ($query) => $query->whereIn('status', ['enrolled', 'completed'])->whereHas('academicYear', fn ($year) => $year->where('status', 'active'))->whereHas('semester', fn ($semester) => $semester->where('status', 'active'))->with('section:id,section_name,year_level')])
            ->orderBy('student_number')->paginate($perPage);
        $attendance = EventAttendance::query()->where('event_id', $event->id)->whereIn('student_id', $students->getCollection()->pluck('id'))->get()->keyBy('student_id');
        $students->getCollection()->transform(function (Student $student) use ($attendance): array {
            $record = $attendance->get($student->id);
            $enrollment = $student->enrollments->first();

            return [
                'student_id' => $student->id, 'student_number' => $student->student_number,
                'name' => trim($student->userProfile->first_name.' '.$student->userProfile->last_name),
                'profile_photo_url' => $student->userProfile->profile_photo_url, 'course' => $student->course?->course_code,
                'year_level' => $enrollment?->section?->year_level ?? $student->year_level, 'section' => $enrollment?->section?->section_name,
                'attendance' => $record ? $this->serializeAttendance($record) : null,
            ];
        });
        $session = EventAttendanceSession::query()->where('event_id', $event->id)->first();

        return [
            'session' => $session ? $this->serializeSession($session) : null,
            'availability' => $this->availability($event, $session),
            'policy' => ['mode' => $event->attendance_mode, 'late_threshold_minutes' => $event->late_threshold_minutes, 'minimum_participation_minutes' => $event->minimum_participation_minutes, 'methods' => $event->attendance_methods ?: Event::DEFAULT_ATTENDANCE_METHODS],
            'summary' => ['eligible' => $eligibleCount, 'present' => (int) ($attendanceCounts['present'] ?? 0), 'late' => (int) ($attendanceCounts['late'] ?? 0), 'not_checked_in' => max(0, $eligibleCount - $attendanceCounts->sum())],
            'pending_verifications' => $this->verifications($event), 'students' => $students,
        ];
    }

    public function serializeSession(EventAttendanceSession $session): array
    {
        return ['id' => $session->id, 'status' => $session->status, 'opened_at' => $session->opened_at?->toIso8601String(), 'closed_at' => $session->closed_at?->toIso8601String(), 'version' => $session->version];
    }

    public function availability(Event $event, ?EventAttendanceSession $session): array
    {
        if ($event->status !== 'published') {
            return ['code' => 'event_unpublished', 'event_window' => 'unavailable', 'session_status' => $session?->status ?? 'not_started', 'can_open' => false, 'message' => 'Publish this Event before starting attendance.'];
        }
        if (now()->lt($event->starts_at)) {
            return ['code' => 'event_not_started', 'event_window' => 'scheduled', 'session_status' => $session?->status ?? 'not_started', 'can_open' => false, 'message' => 'Attendance can start when the scheduled Event begins.'];
        }
        if (now()->gte($event->ends_at)) {
            return ['code' => 'event_ended', 'event_window' => 'ended', 'session_status' => $session?->status ?? 'not_started', 'can_open' => false, 'message' => 'The Event schedule has ended, so attendance cannot be opened.'];
        }
        if ($session === null) {
            return ['code' => 'session_not_started', 'event_window' => 'ongoing', 'session_status' => 'not_started', 'can_open' => true, 'message' => 'The Event is ongoing. Start Attendance to accept QR and manual records.'];
        }
        if ($session->status === 'closed') {
            return ['code' => 'session_closed', 'event_window' => 'ongoing', 'session_status' => 'closed', 'can_open' => false, 'message' => 'Attendance was closed by an Event Coordinator and cannot be reopened.'];
        }

        return ['code' => 'open', 'event_window' => 'ongoing', 'session_status' => 'open', 'can_open' => false, 'message' => 'Attendance is open and accepting configured attendance methods.'];
    }

    public function serializeAttendance(EventAttendance $attendance): array
    {
        return [
            'id' => $attendance->id, 'status' => $attendance->status,
            'checked_in_at' => $attendance->checked_in_at?->toIso8601String(), 'checked_out_at' => $attendance->checked_out_at?->toIso8601String(),
            'participation_minutes' => $attendance->participation_minutes, 'completion_status' => $attendance->completion_status,
            'source' => $attendance->source, 'check_in_method' => $attendance->check_in_method ?? $attendance->source,
            'check_out_method' => $attendance->check_out_method, 'remarks' => $attendance->remarks, 'version' => $attendance->version,
        ];
    }

    public function serializeVerification(EventAttendanceVerification $verification): array
    {
        $verification->loadMissing('student.userProfile');

        return [
            'id' => $verification->id, 'student_id' => $verification->student_id, 'student_number' => $verification->student?->student_number,
            'student_name' => trim(($verification->student?->userProfile?->first_name ?? '').' '.($verification->student?->userProfile?->last_name ?? '')),
            'action' => $verification->action, 'status' => $verification->status, 'requested_at' => $verification->requested_at?->toIso8601String(),
        ];
    }

    private function requestStaticVerification(Event $event, Student $student, array $payload, string $fingerprint, string $client): array
    {
        abort_unless($event->supportsAttendanceMethod('static_event'), 409, 'Static Event QR is disabled for this Event.');
        $session = EventAttendanceSession::query()->find($payload['session'] ?? 0);
        if (! $session || (int) ($payload['event'] ?? 0) !== $event->id || $session->event_id !== $event->id) {
            return $this->rejected($event, $student, $session, 'wrong_session', $fingerprint, $client, 'This QR code belongs to another Event or session.', 422);
        }
        if ($session->status !== 'open' || ! hash_equals((string) $session->static_qr_token_fingerprint, $fingerprint) || (int) ($payload['version'] ?? -1) !== $session->static_token_version) {
            return $this->rejected($event, $student, $session, 'invalid_token', $fingerprint, $client, 'This static Event QR is no longer active.', 409);
        }
        if (! $this->eventAcceptsAttendance($event)) {
            return $this->rejected($event, $student, $session, 'event_not_active', $fingerprint, $client, 'This Event is not accepting attendance now.', 409);
        }
        if (! $this->audiences->isStudentEligible($event, $student)) {
            return $this->rejected($event, $student, $session, 'not_eligible', $fingerprint, $client, 'You are not included in this Event audience.', 403);
        }

        return DB::transaction(function () use ($event, $student, $session, $fingerprint, $client): array {
            $locked = EventAttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            abort_unless($locked->status === 'open', 409, 'Attendance is closed.');
            $attendance = EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->lockForUpdate()->first();
            $action = $attendance && $event->attendance_mode === 'check_in_and_out' && ! $attendance->checked_out_at ? 'check_out' : 'check_in';
            if ($attendance && ($event->attendance_mode === 'check_in_only' || $attendance->checked_out_at)) {
                $this->recordScan($event, $student, $locked, 'duplicate', $fingerprint, $client, ['workflow' => 'static_event']);

                return ['http' => 200, 'code' => 'ALREADY_RECORDED', 'message' => 'Attendance is already complete.', 'attendance' => $attendance];
            }
            $activeKey = EventAttendanceVerification::activeKey($locked->id, $student->id, $action);
            $verification = EventAttendanceVerification::query()->where('active_request_key', $activeKey)->first();
            if (! $verification) {
                try {
                    $student->loadMissing('user');
                    $verification = EventAttendanceVerification::query()->create([
                        'event_id' => $event->id, 'attendance_session_id' => $locked->id, 'student_id' => $student->id,
                        'action' => $action, 'status' => 'pending', 'active_request_key' => $activeKey,
                        'token_fingerprint' => $fingerprint, 'requested_at' => now(),
                    ]);
                    $this->audit->write($event, $student->user, 'attendance.static_qr_requested', ['verification_id' => $verification->id, 'student_id' => $student->id, 'action' => $action]);
                } catch (QueryException $exception) {
                    $verification = EventAttendanceVerification::query()->where('active_request_key', $activeKey)->first();
                    if (! $verification) {
                        throw $exception;
                    }
                }
            }
            $this->recordScan($event, $student, $locked, 'verification_pending', $fingerprint, $client, ['workflow' => 'static_event', 'action' => $action]);

            return ['http' => 202, 'code' => 'VERIFICATION_PENDING', 'message' => 'Waiting for Event personnel to confirm your attendance.', 'attendance' => null, 'verification' => $verification];
        }, 3);
    }

    private function applyAttendanceAction(Event $event, Student $student, EventAttendanceSession $session, string $source, string $method, User $actor, string $requestedAction, string $auditAction, ?string $remarks = null): array
    {
        $attendance = EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->lockForUpdate()->first();
        $action = $requestedAction === 'auto'
            ? ($attendance && $event->attendance_mode === 'check_in_and_out' && ! $attendance->checked_out_at ? 'check_out' : 'check_in')
            : $requestedAction;
        if ($action === 'check_out') {
            abort_unless($event->attendance_mode === 'check_in_and_out', 409, 'This Event uses check-in only attendance.');
            abort_unless($attendance?->checked_in_at, 409, 'Check-in must be recorded before check-out.');
            if ($attendance->checked_out_at) {
                return ['http' => 200, 'code' => 'ALREADY_CHECKED_OUT', 'message' => 'Check-out is already recorded.', 'attendance' => $attendance, 'action' => 'check_out'];
            }
            $checkedOut = now();
            $minutes = (int) floor($attendance->checked_in_at->diffInSeconds($checkedOut) / 60);
            $completion = $event->minimum_participation_minutes === null || $minutes >= $event->minimum_participation_minutes ? 'completed' : 'incomplete';
            $attendance->forceFill([
                'checked_out_at' => $checkedOut, 'participation_minutes' => $minutes, 'completion_status' => $completion,
                'source' => $source, 'check_out_method' => $method, 'recorded_by' => $actor->id, 'remarks' => $remarks ?: $attendance->remarks, 'version' => $attendance->version + 1,
            ])->save();
            $this->audit->write($event, $actor, 'attendance.checked_out', ['attendance_id' => $attendance->id, 'student_id' => $student->id, 'participation_minutes' => $minutes, 'completion_status' => $completion, 'source' => $source]);
            $student->user->notify(new EventNotice('attendance_checked_out', 'Your check-out for '.$event->title.' was recorded.', $attendance->id, $event->id));

            return ['http' => 200, 'code' => 'ATTENDANCE_CHECKED_OUT', 'message' => 'Check-out recorded.', 'attendance' => $attendance, 'action' => 'check_out'];
        }
        if ($attendance) {
            return ['http' => 200, 'code' => 'ALREADY_RECORDED', 'message' => 'Attendance already recorded.', 'attendance' => $attendance, 'action' => 'check_in'];
        }
        $checkedIn = now();
        $status = $checkedIn->lte($event->starts_at->copy()->addMinutes($event->late_threshold_minutes ?? self::LATE_AFTER_MINUTES)) ? 'present' : 'late';
        $attendance = EventAttendance::query()->create([
            'event_id' => $event->id, 'attendance_session_id' => $session->id, 'student_id' => $student->id,
            'status' => $status, 'checked_in_at' => $checkedIn, 'source' => $source, 'check_in_method' => $method, 'recorded_by' => $actor->id,
            'remarks' => $remarks, 'completion_status' => $event->attendance_mode === 'check_in_and_out' ? 'in_progress' : 'completed',
            ...$this->academicSnapshot($student),
        ]);
        $this->audit->write($event, $actor, $auditAction, ['attendance_id' => $attendance->id, 'student_id' => $student->id, 'status' => $status, 'source' => $source, 'action' => 'check_in']);
        $student->user->notify(new EventNotice('attendance_recorded', 'Your attendance for '.$event->title.' was recorded.', $attendance->id, $event->id));

        return ['http' => 201, 'code' => 'ATTENDANCE_RECORDED', 'message' => 'Check-in recorded.', 'attendance' => $attendance, 'action' => 'check_in'];
    }

    private function assertActiveWindow(Event $event): void
    {
        abort_unless($event->status === 'published', 409, 'Only published Events can collect attendance.');
        abort_if(now()->lt($event->starts_at), 409, 'Attendance cannot open before the Event starts.');
        abort_unless(now()->lt($event->ends_at), 409, 'Attendance cannot open after the Event ends.');
    }

    private function eventAcceptsAttendance(Event $event): bool
    {
        return $event->status === 'published' && now()->gte($event->starts_at) && now()->lt($event->ends_at);
    }

    private function academicSnapshot(Student $student): array
    {
        $enrollment = $student->enrollments()->whereIn('status', ['enrolled', 'completed'])
            ->whereHas('academicYear', fn ($query) => $query->where('status', 'active'))
            ->whereHas('semester', fn ($query) => $query->where('status', 'active'))
            ->with('section:id,year_level')->latest('id')->first();

        return ['course_id_at_attendance' => $student->course_id, 'year_level_at_attendance' => $enrollment?->section?->year_level ?? $student->year_level, 'section_id_at_attendance' => $enrollment?->section_id];
    }

    private function rejected(Event $event, Student $student, ?EventAttendanceSession $session, string $result, string $fingerprint, string $client, string $message, int $http): array
    {
        $this->recordScan($event, $student, $session, $result, $fingerprint, $client);

        return ['http' => $http, 'code' => strtoupper($result), 'message' => $message, 'attendance' => null];
    }

    private function recordScan(Event $event, Student $student, ?EventAttendanceSession $session, string $result, string $fingerprint, string $client, array $metadata = []): void
    {
        EventAttendanceScan::query()->create([
            'event_id' => $event->id, 'attendance_session_id' => $session?->id, 'student_id' => $student->id,
            'scanned_at' => now(), 'result' => $result, 'token_fingerprint' => $fingerprint, 'client_platform' => $client, 'metadata' => $metadata,
        ]);
    }

    private function signPayload(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private function verifySignedPayload(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', $parts[0], (string) config('app.key')), $parts[1])) {
            return null;
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function isEventQrToken(string $token): bool
    {
        if (($this->verifySignedPayload($token)['purpose'] ?? null) === 'event_static') {
            return true;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return false;
        }

        return isset($payload['event'], $payload['session'], $payload['version'], $payload['expires_at']);
    }
}
