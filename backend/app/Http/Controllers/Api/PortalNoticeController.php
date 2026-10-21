<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission\AdmissionApplicant;
use App\Models\RiskNotification;
use App\Models\Role;
use App\Models\Student;
use App\Notifications\EnrollmentNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalNoticeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('role');
        abort_unless(in_array($user->role?->role_name, [Role::GUEST, Role::STUDENT], true), 403, 'CDM Buddy is available to Guest and Student accounts.');

        $notices = collect();

        $user->notifications()
            ->where('type', EnrollmentNotice::class)
            ->latest()
            ->limit(10)
            ->get()
            ->each(function ($notification) use ($notices): void {
                $data = $notification->data;
                $notices->push([
                    'id' => 'enrollment-'.$notification->id,
                    'kind' => 'official',
                    'source' => 'enrollment',
                    'source_label' => 'Enrollment record',
                    'title' => $this->enrollmentTitle($data['event'] ?? null),
                    'message' => (string) ($data['message'] ?? 'Your Enrollment record was updated.'),
                    'issued_at' => $notification->created_at?->toIso8601String(),
                    'link' => '/enrollment/status',
                ]);
            });

        if ($user->role?->role_name === Role::STUDENT) {
            $student = Student::query()->where('user_id', $user->id)->first();
            if ($student) {
                RiskNotification::query()
                    ->where('student_id', $student->id)
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->each(fn (RiskNotification $notice) => $notices->push([
                        'id' => 'monitoring-'.$notice->id,
                        'kind' => 'official',
                        'source' => 'academic_monitoring',
                        'source_label' => 'Academic Monitoring',
                        'title' => $notice->title,
                        'message' => $notice->message,
                        'issued_at' => $notice->created_at?->toIso8601String(),
                        'link' => '/monitoring',
                    ]));
            }
        }

        if ($user->role?->role_name === Role::GUEST) {
            $application = AdmissionApplicant::query()
                ->where('user_id', $user->id)
                ->latest('created_at')
                ->latest('id')
                ->first(['id', 'status', 'updated_at']);
            if ($application) {
                $notices->push([
                    'id' => 'admission-'.$application->id.'-'.$application->updated_at?->timestamp,
                    'kind' => 'official',
                    'source' => 'admission',
                    'source_label' => 'Admission record',
                    'title' => 'Admission application update',
                    'message' => 'Your current application status is '.$this->statusLabel($application->status).'.',
                    'issued_at' => $application->updated_at?->toIso8601String(),
                    'link' => '/admission',
                ]);
            }
        }

        return response()->json(['success' => true, 'data' => [
            'notices' => $notices->sortByDesc('issued_at')->take(12)->values()->all(),
            'official_sources' => ['admission', 'enrollment', 'academic_monitoring'],
        ]])->header('Cache-Control', 'private, no-store');
    }

    private function enrollmentTitle(?string $event): string
    {
        return match ($event) {
            'schedule_changed' => 'Class schedule updated',
            'section_assigned' => 'Section assigned',
            'finalized' => 'Enrollment completed',
            'rejected' => 'Enrollment application update',
            default => 'Enrollment update',
        };
    }

    private function statusLabel(string $status): string
    {
        return str_replace('_', ' ', $status);
    }
}
