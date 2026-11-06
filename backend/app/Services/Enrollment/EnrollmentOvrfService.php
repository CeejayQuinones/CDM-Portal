<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\Response;

class EnrollmentOvrfService
{
    public function render(User $user, int $enrollmentId, string $disposition = 'inline'): Response
    {
        $actor = User::with('role')->whereKey($user->id)->firstOrFail();
        abort_unless($actor->status === 'active', 403, 'Active account access is required.');

        $role = $actor->role?->role_name;
        $staff = in_array($role, [Role::ADMIN, Role::REGISTRAR_STAFF], true);
        abort_unless($staff || $role === Role::STUDENT, 403, 'OVRF access is available only to authorized Students and Registrar staff.');

        $query = Enrollment::query();
        if (! $staff) {
            $query->whereHas('student', fn ($student) => $student->where('user_id', $actor->id));
        }

        $enrollment = $query->with([
            'student.userProfile',
            'student.course',
            'section.course',
            'academicYear',
            'semester',
            'application.course',
            'enrollmentSubjects.subject',
        ])->find($enrollmentId);

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(Enrollment::class, [$enrollmentId]);
        }

        abort_unless(
            in_array($enrollment->status, ['enrolled', 'completed'], true),
            409,
            'OVRF is available only for finalized academic enrollment.',
        );

        $schedules = SectionSubject::with(['meetings', 'professor.user.profile'])
            ->where('section_id', $enrollment->section_id)
            ->whereIn('subject_id', $enrollment->enrollmentSubjects->pluck('subject_id'))
            ->get()
            ->keyBy('subject_id');

        $profile = $enrollment->student->userProfile;
        $name = collect([$profile?->first_name, $profile?->middle_name, $profile?->last_name, $profile?->suffix])
            ->filter(fn ($part) => filled($part))
            ->implode(' ');
        $course = $enrollment->application?->course ?? $enrollment->section?->course ?? $enrollment->student->course;
        $classification = $enrollment->application?->classification;
        $subjects = $enrollment->enrollmentSubjects->map(function ($enrollmentSubject) use ($schedules, $enrollment): array {
            $schedule = $schedules->get($enrollmentSubject->subject_id);
            $meetings = $schedule?->meetings?->map(fn ($meeting): array => [
                'day' => $meeting->day,
                'start_time' => $this->time($meeting->start_time),
                'end_time' => $this->time($meeting->end_time),
                'room' => $meeting->room,
            ])->all() ?? [];
            if ($meetings === [] && $schedule?->day && $schedule?->start_time && $schedule?->end_time) {
                $meetings[] = [
                    'day' => $schedule->day,
                    'start_time' => $this->time($schedule->start_time),
                    'end_time' => $this->time($schedule->end_time),
                    'room' => $schedule->room,
                ];
            }

            $professorProfile = $schedule?->professor?->user?->profile;

            return [
                'code' => $enrollmentSubject->subject->subject_code,
                'title' => $enrollmentSubject->subject->subject_name,
                'section' => $enrollment->section->section_name,
                'units' => (float) $enrollmentSubject->subject->units,
                'meetings' => $meetings,
                'professor' => $professorProfile
                    ? trim($professorProfile->first_name.' '.$professorProfile->last_name)
                    : null,
            ];
        })->values();

        $seal = $this->sealDataUri();
        $html = view('enrollment.ovrf', [
            'document' => [
                'record_id' => $enrollment->id,
                'student_number' => $enrollment->student->student_number,
                'student_name' => $name,
                'program' => $course?->course_name,
                'program_code' => $course?->course_code,
                'year_level' => $enrollment->application?->year_level ?? $enrollment->section?->year_level ?? $enrollment->student->year_level,
                'classification' => $classification,
                'academic_year' => $enrollment->academicYear->school_year,
                'semester' => $enrollment->semester->semester_name,
                'section' => $enrollment->section->section_name,
                'enrollment_date' => $enrollment->enrollment_date,
                'subjects' => $subjects,
                'total_units' => $subjects->sum('units'),
                'generated_at' => now(),
                'financial_status' => 'Unavailable — no verified fee assessment or payment record is connected to this enrollment.',
            ],
            'seal' => $seal,
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 105, $canvas->get_height() - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.25, 0.31, 0.28]);

        $filename = 'OVRF-'.$this->filenamePart($enrollment->student->student_number).'-'.$this->filenamePart($enrollment->academicYear->school_year).'.pdf';
        $contentDisposition = $disposition === 'attachment' ? 'attachment' : 'inline';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $contentDisposition.'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-OVRF-Validation-Status' => 'physical-verification-required',
        ]);
    }

    private function time(mixed $value): string
    {
        return substr((string) $value, 0, 5);
    }

    private function filenamePart(mixed $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $value), '-');
    }

    private function sealDataUri(): ?string
    {
        $path = realpath(base_path('../CDM_Frontend/src/assets/styles/images/cdm-seal.png'));
        if (! $path || ! is_readable($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }
}
