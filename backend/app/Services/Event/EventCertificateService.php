<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventCertificate;
use App\Models\Student;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EventCertificateService
{
    public function __construct(
        private readonly EventReportService $reports,
        private readonly EventAudienceResolver $audiences,
        private readonly EventAuditWriter $audit,
        private readonly EventNotificationService $notifications,
    ) {}

    public function configure(Event $event, array $data, User $actor): Event
    {
        return DB::transaction(function () use ($event, $data, $actor): Event {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);
            if ((int) $data['version'] !== $locked->version) {
                throw ValidationException::withMessages(['version' => 'This Event changed after you opened it. Reload and try again.']);
            }
            $required = collect($data['required_event_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            if ($locked->parent_event_id && $required->isNotEmpty()) {
                throw ValidationException::withMessages(['required_event_ids' => 'Only a parent Event may require child activities.']);
            }
            $valid = Event::query()->where('parent_event_id', $locked->id)->whereIn('id', $required)->count();
            if ($valid !== $required->count()) {
                throw ValidationException::withMessages(['required_event_ids' => 'Every certificate requirement must be a direct sub-event.']);
            }
            $locked->forceFill([
                'certificates_enabled' => (bool) $data['certificates_enabled'],
                'certificate_minimum_minutes' => $data['certificate_minimum_minutes'] ?? null,
                'updated_by' => $actor->id,
                'version' => $locked->version + 1,
            ])->save();
            $locked->certificateRequirements()->delete();
            foreach ($required as $requiredEventId) {
                $locked->certificateRequirements()->create(['required_event_id' => $requiredEventId]);
            }
            $this->audit->write($locked, $actor, 'certificate.configuration_changed', [
                'enabled' => $locked->certificates_enabled,
                'minimum_minutes' => $locked->certificate_minimum_minutes,
                'required_event_ids' => $required->all(),
                'version' => $locked->version,
            ]);

            return $locked->load('certificateRequirements.requiredEvent');
        }, 3);
    }

    public function status(Event $event, Student $student): array
    {
        $event->loadMissing('certificateRequirements.requiredEvent');
        $requirements = $event->certificateRequirements->map(function ($requirement) use ($student): array {
            $activity = $requirement->requiredEvent;
            $metrics = $this->reports->participant($activity, $student);

            return ['event_id' => $activity->id, 'title' => $activity->title, 'completed' => $metrics['completed']];
        })->values();
        $own = $this->reports->participant($event, $student);
        $requiredCompleted = $requirements->every(fn (array $item) => $item['completed']);
        $attendanceIds = $requirements->pluck('event_id');
        if ($attendanceIds->isEmpty()) {
            $attendanceIds = collect([$event->id]);
        }
        $duration = (int) EventAttendance::query()->where('student_id', $student->id)->whereIn('event_id', $attendanceIds)->sum('participation_minutes');
        $attendanceSatisfied = $requirements->isNotEmpty() ? $requiredCompleted : $own['completed'];
        $ended = $event->status === 'published' && now()->gte($event->ends_at);
        $durationSatisfied = $event->certificate_minimum_minutes === null || $duration >= $event->certificate_minimum_minutes;
        $eligible = $event->certificates_enabled && $ended && $own['eligible_participant'] && $attendanceSatisfied && $durationSatisfied;
        $certificate = EventCertificate::query()->where('event_id', $event->id)->where('student_id', $student->id)->latest('version')->first();
        $reasons = [];
        if (! $event->certificates_enabled) {
            $reasons[] = 'Certificates are not enabled for this Event.';
        }
        if (! $ended) {
            $reasons[] = 'The Event must be completed first.';
        }
        if (! $attendanceSatisfied) {
            $reasons[] = $requirements->isNotEmpty() ? 'Complete every required activity.' : 'Complete the required attendance.';
        }
        if (! $durationSatisfied) {
            $reasons[] = 'Complete at least '.$event->certificate_minimum_minutes.' participation minutes.';
        }

        return [
            'eligible' => $eligible,
            'reasons' => $reasons,
            'participation_minutes' => $duration,
            'minimum_minutes' => $event->certificate_minimum_minutes,
            'requirements' => $requirements,
            'certificate' => $certificate ? $this->serialize($certificate) : null,
        ];
    }

    public function issue(Event $event, Student $student, User $actor): EventCertificate
    {
        return DB::transaction(function () use ($event, $student, $actor): EventCertificate {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            Student::query()->lockForUpdate()->findOrFail($student->id);
            $active = EventCertificate::query()->where('event_id', $event->id)->where('student_id', $student->id)->where('status', 'issued')->lockForUpdate()->first();
            if ($active) {
                return $active;
            }
            $eligibility = $this->status($lockedEvent, $student);
            abort_unless($eligibility['eligible'], 409, $eligibility['reasons'][0] ?? 'Certificate requirements are not complete.');
            $latest = EventCertificate::query()->where('event_id', $event->id)->where('student_id', $student->id)->lockForUpdate()->max('version');
            try {
                $certificate = EventCertificate::query()->create([
                    'event_id' => $event->id, 'student_id' => $student->id, 'version' => ((int) $latest) + 1,
                    'verification_code' => hash('sha256', Str::random(64)), 'status' => 'issued',
                    'active_issue_key' => $event->id.':'.$student->id, 'issued_at' => now(), 'issued_by_user_id' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                $certificate = EventCertificate::query()->where('active_issue_key', $event->id.':'.$student->id)->first();
                if (! $certificate) {
                    throw $exception;
                }
            }
            $this->audit->write($event, $actor, $latest ? 'certificate.reissued' : 'certificate.issued', ['certificate_version' => $certificate->version, 'student_id' => $student->id]);
            $student->loadMissing('user');
            DB::afterCommit(fn () => $this->notifications->user($student->user, $event, 'certificate_available', 'Your participation certificate for '.$event->title.' is available.', $certificate->id, true));

            return $certificate;
        }, 3);
    }

    public function reconcile(Event $changedEvent, Student $student, User $actor): void
    {
        $events = collect([$changedEvent]);
        if ($changedEvent->parent_event_id) {
            $events->push(Event::query()->find($changedEvent->parent_event_id));
        }
        foreach ($events->filter() as $event) {
            $certificate = EventCertificate::query()->where('event_id', $event->id)->where('student_id', $student->id)->where('status', 'issued')->first();
            if ($certificate && ! $this->status($event, $student)['eligible']) {
                DB::transaction(function () use ($event, $certificate, $student, $actor): void {
                    $locked = EventCertificate::query()->lockForUpdate()->findOrFail($certificate->id);
                    if ($locked->status !== 'issued') {
                        return;
                    }
                    $locked->forceFill(['status' => 'revoked', 'active_issue_key' => null, 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id, 'revocation_reason' => 'Attendance correction changed certificate eligibility.'])->save();
                    $this->audit->write($event, $actor, 'certificate.revoked', ['certificate_version' => $locked->version, 'student_id' => $student->id, 'reason' => $locked->revocation_reason]);
                    $this->audit->write($event, $actor, 'attendance.certificate_changed', ['student_id' => $student->id, 'eligible' => false]);
                }, 3);
            }
        }
    }

    public function download(EventCertificate $certificate): Response
    {
        $certificate->loadMissing(['event', 'student.userProfile']);
        abort_unless($certificate->status === 'issued', 410, 'This certificate has been revoked.');
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('events.certificate', ['certificate' => $certificate])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="event-certificate-'.$certificate->verification_code.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function verify(string $code): ?array
    {
        $certificate = EventCertificate::query()->where('verification_code', $code)->with(['event:id,title', 'student.userProfile:id,first_name,last_name'])->first();
        if (! $certificate) {
            return null;
        }

        return ['status' => $certificate->status === 'issued' ? 'Valid' : 'Revoked', 'participant_name' => trim($certificate->student->userProfile->first_name.' '.$certificate->student->userProfile->last_name), 'event_title' => $certificate->event->title, 'issue_date' => $certificate->issued_at->toDateString()];
    }

    public function serialize(EventCertificate $certificate): array
    {
        return ['status' => $certificate->status, 'version' => $certificate->version, 'identifier' => $certificate->verification_code, 'issued_at' => $certificate->issued_at?->toIso8601String(), 'revoked_at' => $certificate->revoked_at?->toIso8601String()];
    }
}
