<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventAttendanceScan;
use App\Models\EventAttendanceSession;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventAttendanceService
{
    public const TOKEN_LIFETIME_SECONDS = 45;

    public const TOKEN_REFRESH_SECONDS = 30;

    public const LATE_AFTER_MINUTES = 15;

    public const PARTICIPANT_DYNAMIC_LIFETIME_SECONDS = 45;

    public function __construct(private readonly EventAudienceResolver $audiences, private readonly EventAuditWriter $audit) {}

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
            $session->forceFill([
                'status' => 'closed', 'closed_at' => now(), 'qr_token_fingerprint' => null,
                'qr_expires_at' => null, 'version' => $session->version + 1,
            ])->save();
            $this->audit->write($event, $actor, 'attendance.session_closed', ['session_id' => $session->id]);

            return $session;
        }, 3);
    }

    public function token(Event $event): array
    {
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

            return ['token' => $token, 'expires_at' => $expiresAt->toIso8601String(), 'refresh_after_seconds' => self::TOKEN_REFRESH_SECONDS];
        }, 3);
    }

    public function scan(Event $event, Student $student, string $token, string $client): array
    {
        $fingerprint = hash('sha256', $token);
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
            return $this->rejected($event, $student, $session, 'expired_token', $fingerprint, $client, 'QR expired. Scan the current code displayed by the Registrar.', 410);
        }
        if (! hash_equals((string) $session->qr_token_fingerprint, $fingerprint) || (int) ($payload['version'] ?? -1) !== $session->token_version) {
            return $this->rejected($event, $student, $session, 'invalid_token', $fingerprint, $client, 'This QR code has rotated. Scan the current code.', 422);
        }
        if ($event->status !== 'published' || now()->lt($event->starts_at) || ! now()->lt($event->ends_at)) {
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
                return $this->rejected($event, $student, $locked, 'expired_token', $fingerprint, $client, 'QR expired. Scan the current code displayed by the Registrar.', 410);
            }
            $existing = EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->lockForUpdate()->first();
            if ($existing) {
                $this->recordScan($event, $student, $locked, 'duplicate', $fingerprint, $client);

                return ['http' => 200, 'code' => 'ALREADY_RECORDED', 'message' => 'Attendance already recorded.', 'attendance' => $existing];
            }
            $status = now()->lte($event->starts_at->copy()->addMinutes(self::LATE_AFTER_MINUTES)) ? 'present' : 'late';
            $attendance = EventAttendance::query()->create([
                'event_id' => $event->id, 'attendance_session_id' => $locked->id, 'student_id' => $student->id,
                'status' => $status, 'checked_in_at' => now(), 'source' => 'qr', 'recorded_by' => $student->user_id,
                ...$this->academicSnapshot($student),
            ]);
            $this->recordScan($event, $student, $locked, 'accepted', $fingerprint, $client);
            $this->audit->write($event, $student->user, 'attendance.recorded', ['attendance_id' => $attendance->id, 'student_id' => $student->id, 'status' => $status, 'source' => 'qr']);

            return ['http' => 201, 'code' => 'ATTENDANCE_RECORDED', 'message' => 'Attendance recorded.', 'attendance' => $attendance];
        }, 3);
    }

    public function studentIsEligible(Event $event, Student $student): bool
    {
        return $this->audiences->isStudentEligible($event, $student);
    }

    /** @return array{token:string,mode:string,expires_at:?string} */
    public function participantQr(Event $event, Student $student, string $mode): array
    {
        $this->assertActiveWindow($event);
        $session = EventAttendanceSession::query()->where('event_id', $event->id)->first();
        abort_unless($session?->status === 'open', 409, 'Attendance is closed.');
        abort_unless($this->audiences->isStudentEligible($event, $student), 403, 'You are not included in this Event audience.');

        $expiresAt = $mode === 'dynamic' ? now()->addSeconds(self::PARTICIPANT_DYNAMIC_LIFETIME_SECONDS) : null;
        $payload = [
            'v' => 1,
            'purpose' => 'event_participant',
            'mode' => $mode,
            'event' => $event->id,
            'session' => $session->id,
            'student' => $student->id,
        ];
        if ($expiresAt) {
            $payload['issued_at'] = now()->timestamp;
            $payload['expires_at'] = $expiresAt->timestamp;
            $payload['nonce'] = bin2hex(random_bytes(16));
        }

        return [
            'token' => $this->signParticipantPayload($payload),
            'mode' => $mode,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    public function operatorScan(Event $event, User $actor, string $token, string $workflow, string $client): array
    {
        $fingerprint = hash('sha256', $token);
        $payload = $this->verifyParticipantPayload($token);
        if (! $payload || ($payload['purpose'] ?? null) !== 'event_participant' || ($payload['mode'] ?? null) !== $workflow) {
            return ['http' => 422, 'code' => 'INVALID_PARTICIPANT_QR', 'message' => 'This participant QR is invalid for the selected workflow.', 'attendance' => null];
        }
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
        if ($event->status !== 'published' || now()->lt($event->starts_at) || ! now()->lt($event->ends_at)) {
            return ['http' => 409, 'code' => 'EVENT_NOT_ACTIVE', 'message' => 'This Event is not accepting attendance now.', 'attendance' => null];
        }

        return DB::transaction(function () use ($event, $actor, $student, $session, $fingerprint, $client, $workflow): array {
            $locked = EventAttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== 'open') {
                return ['http' => 409, 'code' => 'SESSION_CLOSED', 'message' => 'Attendance is already closed.', 'attendance' => null];
            }
            $existing = EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->lockForUpdate()->first();
            if ($existing) {
                $this->recordScan($event, $student, $locked, 'duplicate', $fingerprint, $client, ['workflow' => $workflow, 'operator_user_id' => $actor->id]);

                return ['http' => 200, 'code' => 'ALREADY_RECORDED', 'message' => 'Attendance already recorded.', 'attendance' => $existing];
            }
            $status = now()->lte($event->starts_at->copy()->addMinutes(self::LATE_AFTER_MINUTES)) ? 'present' : 'late';
            $attendance = EventAttendance::query()->create([
                'event_id' => $event->id,
                'attendance_session_id' => $locked->id,
                'student_id' => $student->id,
                'status' => $status,
                'checked_in_at' => now(),
                'source' => 'qr',
                'recorded_by' => $actor->id,
                'remarks' => ucfirst($workflow).' participant QR scanned by assigned Event personnel.',
                ...$this->academicSnapshot($student),
            ]);
            $this->recordScan($event, $student, $locked, 'accepted', $fingerprint, $client, ['workflow' => $workflow, 'operator_user_id' => $actor->id]);
            $this->audit->write($event, $actor, 'attendance.operator_qr_recorded', [
                'attendance_id' => $attendance->id,
                'student_id' => $student->id,
                'status' => $status,
                'workflow' => $workflow,
            ]);

            return ['http' => 201, 'code' => 'ATTENDANCE_RECORDED', 'message' => 'Attendance recorded.', 'attendance' => $attendance];
        }, 3);
    }

    public function manual(Event $event, Student $student, string $status, string $reason, User $actor): EventAttendance
    {
        return DB::transaction(function () use ($event, $student, $status, $reason, $actor): EventAttendance {
            $session = EventAttendanceSession::query()->where('event_id', $event->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'open', 409, 'Attendance is closed.');
            $this->assertActiveWindow($event);
            abort_unless($this->audiences->isStudentEligible($event, $student), 422, 'The Student is not eligible for this Event.');
            abort_if(EventAttendance::query()->where('event_id', $event->id)->where('student_id', $student->id)->exists(), 409, 'Attendance already exists. Use correction instead.');
            $attendance = EventAttendance::query()->create([
                'event_id' => $event->id, 'attendance_session_id' => $session->id, 'student_id' => $student->id,
                'status' => $status, 'checked_in_at' => in_array($status, ['present', 'late'], true) ? now() : null,
                'source' => 'manual', 'recorded_by' => $actor->id, 'remarks' => $reason,
                ...$this->academicSnapshot($student),
            ]);
            $this->audit->write($event, $actor, 'attendance.manual_created', ['attendance_id' => $attendance->id, 'student_id' => $student->id, 'status' => $status, 'source' => 'manual', 'reason' => $reason]);

            return $attendance;
        }, 3);
    }

    public function correct(Event $event, EventAttendance $attendance, string $status, string $reason, int $version, User $actor): EventAttendance
    {
        return DB::transaction(function () use ($event, $attendance, $status, $reason, $version, $actor): EventAttendance {
            $locked = EventAttendance::query()->lockForUpdate()->findOrFail($attendance->id);
            abort_unless($locked->event_id === $event->id, 404);
            if ($locked->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Attendance changed after you opened it. Reload and try again.']);
            }
            $old = $locked->status;
            $locked->forceFill(['status' => $status, 'source' => 'manual', 'recorded_by' => $actor->id, 'remarks' => $reason, 'version' => $locked->version + 1])->save();
            $this->audit->write($event, $actor, 'attendance.corrected', ['attendance_id' => $locked->id, 'student_id' => $locked->student_id, 'old_status' => $old, 'new_status' => $status, 'reason' => $reason]);

            return $locked;
        }, 3);
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
            'summary' => ['eligible' => $eligibleCount, 'present' => (int) ($attendanceCounts['present'] ?? 0), 'late' => (int) ($attendanceCounts['late'] ?? 0), 'not_checked_in' => max(0, $eligibleCount - $attendanceCounts->sum())],
            'students' => $students,
        ];
    }

    public function serializeSession(EventAttendanceSession $session): array
    {
        return ['id' => $session->id, 'status' => $session->status, 'opened_at' => $session->opened_at?->toIso8601String(), 'closed_at' => $session->closed_at?->toIso8601String(), 'version' => $session->version];
    }

    public function serializeAttendance(EventAttendance $attendance): array
    {
        return ['id' => $attendance->id, 'status' => $attendance->status, 'checked_in_at' => $attendance->checked_in_at?->toIso8601String(), 'source' => $attendance->source, 'remarks' => $attendance->remarks, 'version' => $attendance->version];
    }

    private function assertActiveWindow(Event $event): void
    {
        abort_unless($event->status === 'published', 409, 'Only published Events can collect attendance.');
        abort_if(now()->lt($event->starts_at), 409, 'Attendance cannot open before the Event starts.');
        abort_unless(now()->lt($event->ends_at), 409, 'Attendance cannot open after the Event ends.');
    }

    private function academicSnapshot(Student $student): array
    {
        $enrollment = $student->enrollments()->whereIn('status', ['enrolled', 'completed'])
            ->whereHas('academicYear', fn ($query) => $query->where('status', 'active'))
            ->whereHas('semester', fn ($query) => $query->where('status', 'active'))
            ->with('section:id,year_level')->latest('id')->first();

        return [
            'course_id_at_attendance' => $student->course_id,
            'year_level_at_attendance' => $enrollment?->section?->year_level ?? $student->year_level,
            'section_id_at_attendance' => $enrollment?->section_id,
        ];
    }

    private function rejected(Event $event, Student $student, ?EventAttendanceSession $session, string $result, string $fingerprint, string $client, string $message, int $http): array
    {
        $this->recordScan($event, $student, $session, $result, $fingerprint, $client);

        return ['http' => $http, 'code' => strtoupper($result), 'message' => $message, 'attendance' => null];
    }

    /** @param array<string, mixed> $metadata */
    private function recordScan(Event $event, Student $student, ?EventAttendanceSession $session, string $result, string $fingerprint, string $client, array $metadata = []): void
    {
        EventAttendanceScan::query()->create([
            'event_id' => $event->id, 'attendance_session_id' => $session?->id, 'student_id' => $student->id,
            'scanned_at' => now(), 'result' => $result, 'token_fingerprint' => $fingerprint,
            'client_platform' => $client, 'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function signParticipantPayload(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, (string) config('app.key'));

        return $encoded.'.'.$signature;
    }

    /** @return array<string, mixed>|null */
    private function verifyParticipantPayload(string $token): ?array
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
}
