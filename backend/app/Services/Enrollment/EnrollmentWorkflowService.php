<?php

namespace App\Services\Enrollment;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Enrollment\EnrollmentDocument;
use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Notifications\EnrollmentNotice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EnrollmentWorkflowService
{
    public function __construct(private EnrollmentEligibilityService $eligibility, private EnrollmentAuditWriter $audit) {}

    public function period(User $user, array $input, ?int $id = null): EnrollmentPeriod
    {
        return DB::transaction(function () use ($user, $input, $id) {
            $actor = EnrollmentAccess::require($user, true, true);
            // Lock the academic year as a stable parent even before a period exists.
            AcademicYear::whereKey($input['academic_year_id'])->lockForUpdate()->firstOrFail();
            $p = $id ? EnrollmentPeriod::lockForUpdate()->findOrFail($id) : new EnrollmentPeriod;
            if ($id) {
                abort_unless($p->version === $input['version'], 409, 'This period changed. Reload before saving.');
                abort_unless($p->academic_year_id === $input['academic_year_id'] && $p->semester_id === $input['semester_id'], 422, 'A period cannot be moved to another term.');
                if (EnrollmentApplication::where('period_id', $id)->lockForUpdate()->first(['id'])) {
                    abort_unless($p->document_requirements == $input['document_requirements'], 409, 'Document requirements cannot change after an application is created.');
                }
            }
            abort_if(EnrollmentPeriod::where('academic_year_id', $input['academic_year_id'])->where('semester_id', $input['semester_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first(['id']), 409, 'This term already has a period. Edit that period.');
            $p->fill(collect($input)->only(['academic_year_id', 'semester_id', 'opens_at', 'closes_at', 'enabled', 'document_requirements'])->all());
            $p->updated_by = $actor->id;
            if (! $id) {
                $p->created_by = $actor->id;
            } else {
                $p->version++;
            }
            $p->save();
            $this->audit->record($actor, $id ? 'period_updated' : 'period_created', 'period', $p->id, ['version' => $p->version]);

            return $p->fresh(['academicYear', 'semester']);
        }, 3);
    }

    public function create(User $user, array $input): EnrollmentApplication
    {
        return DB::transaction(function () use ($user, $input) {
            $actor = EnrollmentAccess::require($user, false, true);
            $student = Student::where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $p = EnrollmentPeriod::lockForUpdate()->findOrFail($input['period_id']);
            $this->eligible($actor, $student, $p);
            $existing = EnrollmentApplication::where('student_id', $student->id)->where('academic_year_id', $p->academic_year_id)->where('semester_id', $p->semester_id)->lockForUpdate()->first();
            abort_if($existing, 409, 'An application already exists for this term. Open your existing application.');
            $a = EnrollmentApplication::create(['student_id' => $student->id, 'period_id' => $p->id, 'academic_year_id' => $p->academic_year_id, 'semester_id' => $p->semester_id, 'course_id' => $student->course_id, 'curriculum_id' => $student->curriculum_id, 'year_level' => $student->year_level, 'classification' => $input['classification'], 'status' => 'draft']);
            $this->audit->record($actor, 'draft_created', 'application', $a->id, ['status' => $a->status, 'version' => 1]);

            return $a->fresh();
        }, 3);
    }

    private function eligible(User $actor, Student $student, EnrollmentPeriod $p): void
    {
        // Hold academic configuration stable through submission.
        Course::whereKey($student->course_id)->lockForUpdate()->first();
        Curriculum::whereKey($student->curriculum_id)->lockForUpdate()->first();
        AcademicYear::whereKey($p->academic_year_id)->lockForUpdate()->first();
        Semester::whereKey($p->semester_id)->lockForUpdate()->first();
        $state = $this->eligibility->status($actor, true);
        abort_unless($p->state === 'open' && $state['eligible'] && ($state['period']['id'] ?? null) === $p->id, 409, 'Enrollment is not currently available. Reload your eligibility.');
    }

    public function mutate(User $user, int $id, string $action, array $input): EnrollmentApplication
    {
        return DB::transaction(function () use ($user, $id, $action, $input) {
            $staff = in_array($action, ['review', 'approve', 'reject'], true);
            $actor = EnrollmentAccess::require($user, $staff, true);
            $ref = EnrollmentApplication::findOrFail($id);
            if (! $staff) {
                abort_unless(Student::whereKey($ref->student_id)->where('user_id', $actor->id)->exists(), 404, 'Application not found.');
            }
            // Serialize all actions for this Student; period edits and submission share a period lock.
            $student = Student::lockForUpdate()->findOrFail($ref->student_id);
            abort_unless($staff || $student->user_id === $actor->id, 404, 'Application not found.');
            $p = EnrollmentPeriod::lockForUpdate()->findOrFail($ref->period_id);
            $a = EnrollmentApplication::lockForUpdate()->findOrFail($id);
            if ($action === 'submit' && $a->status === 'submitted' && $a->version === $input['version'] + 1) {
                return $a;
            }
            abort_unless($a->version === $input['version'], 409, 'This application changed. Reload before continuing.');
            $from = ['save' => ['draft'], 'submit' => ['draft'], 'cancel' => ['draft'], 'review' => ['submitted'], 'approve' => ['under_review'], 'reject' => ['submitted', 'under_review']];
            abort_unless(in_array($a->status, $from[$action] ?? [], true), 409, 'This action is not allowed for the current application status.');
            if ($action === 'save') {
                $a->classification = $input['classification'];
            }
            if ($action === 'submit') {
                $this->eligible($actor, $student, $p);
                $this->documentsComplete($a, $p);
                $a->course_id = $student->course_id;
                $a->curriculum_id = $student->curriculum_id;
                $a->year_level = $student->year_level;
                $a->submitted_at = now();
            }
            if ($staff) {
                // Approval is an application decision only, never an academic enrollment.
                if ($action === 'approve') {
                    $this->documentsComplete($a, $p);
                }
                $a->reviewed_by = $actor->id;
                $a->reviewed_at = now();
                $a->review_notes = $input['notes'] ?? null;
            }
            $a->status = ['save' => 'draft', 'submit' => 'submitted', 'cancel' => 'cancelled', 'review' => 'under_review', 'approve' => 'approved', 'reject' => 'rejected'][$action];
            $a->version++;
            $a->save();
            if ($action === 'reject') {
                $student->user->notify(new EnrollmentNotice('rejected', 'Your enrollment application was rejected. Review the staff notes.', $a->id));
            }
            $event = ['save' => 'draft_saved', 'submit' => 'submitted', 'cancel' => 'cancelled', 'review' => 'review_started', 'approve' => 'approved', 'reject' => 'rejected'][$action];
            $this->audit->record($actor, $event, 'application', $a->id, ['status' => $a->status, 'version' => $a->version]);

            return $a;
        }, 3);
    }

    public function requirements(EnrollmentApplication $a): array
    {
        $ids = $a->period->document_requirements[$a->classification] ?? [];
        $attached = $a->documents->keyBy('document_type_id');
        $existing = StudentDocument::where('student_id', $a->student_id)->whereIn('document_type_id', $ids)->where('verification_status', 'verified')->get()->keyBy('document_type_id');

        return DocumentType::whereIn('id', $ids)->get()->map(function ($type) use ($attached, $existing) {
            $doc = $attached->get($type->id);
            $reuse = $existing->get($type->id);
            $reusable = $reuse && $reuse->file_path && Storage::disk('local')->exists($reuse->file_path);

            return ['id' => $type->id, 'name' => $type->document_name, 'attached' => $doc !== null, 'document_id' => $doc?->id, 'reusable' => $reusable, 'student_document_id' => $reusable ? $reuse->id : null];
        })->all();
    }

    private function documentsComplete(EnrollmentApplication $a, EnrollmentPeriod $p): void
    {
        foreach ($p->document_requirements[$a->classification] ?? [] as $type) {
            $doc = $a->documents()->where('document_type_id', $type)->lockForUpdate()->first();
            // Reuse verified evidence automatically; no repeat Student upload.
            if (! $doc) {
                $existing = StudentDocument::where('student_id', $a->student_id)->where('document_type_id', $type)->where('verification_status', 'verified')->lockForUpdate()->first();
                if ($existing && $existing->file_path && Storage::disk('local')->exists($existing->file_path)) {
                    $doc = $a->documents()->create(['document_type_id' => $type, 'student_document_id' => $existing->id]);
                }
            }
            $path = $this->documentPath($a, $doc, true);
            abort_unless($path && Storage::disk('local')->exists($path), 422, 'Required documents are missing or no longer available.');
        }
    }

    public function documentPath(EnrollmentApplication $a, ?EnrollmentDocument $doc, bool $lock = false): ?string
    {
        if (! $doc) {
            return null;
        }
        if ($doc->student_document_id) {
            return StudentDocument::whereKey($doc->student_document_id)->where('student_id', $a->student_id)->where('document_type_id', $doc->document_type_id)->where('verification_status', 'verified')->when($lock, fn ($q) => $q->lockForUpdate())->value('file_path');
        }

        return $doc->file_path;
    }

    public function upload(User $user, int $id, array $input, UploadedFile $file): EnrollmentDocument
    {
        $path = null;
        try {
            return DB::transaction(function () use ($user, $id, $input, $file, &$path) {
                $actor = EnrollmentAccess::require($user, false, true);
                $ref = EnrollmentApplication::findOrFail($id);
                $student = Student::whereKey($ref->student_id)->lockForUpdate()->firstOrFail();
                abort_unless($student->user_id === $actor->id, 404, 'Application not found.');
                $a = EnrollmentApplication::lockForUpdate()->findOrFail($id);
                abort_unless($a->status === 'draft' && $a->version === $input['version'], 409, 'Reload your draft before uploading.');
                abort_unless(in_array($input['document_type_id'], $a->period->document_requirements[$a->classification] ?? [], true), 422, 'This document is not required for your classification.');
                abort_if($a->documents()->where('document_type_id', $input['document_type_id'])->lockForUpdate()->first(['id']), 409, 'This requirement already has a document.');
                $existing = StudentDocument::where('student_id', $a->student_id)->where('document_type_id', $input['document_type_id'])->where('verification_status', 'verified')->lockForUpdate()->first();
                abort_if($existing && $existing->file_path && Storage::disk('local')->exists($existing->file_path), 409, 'A verified document is already available and will be reused when submitting.');
                $path = $file->store('enrollment/'.$a->id, 'local');
                abort_unless($path, 503, 'The document could not be stored.');
                $doc = $a->documents()->create(['document_type_id' => $input['document_type_id'], 'file_path' => $path]);
                $a->version++;
                $a->save();
                $this->audit->record($actor, 'document_attached', 'application', $a->id, ['version' => $a->version]);

                return $doc;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
    }
}
