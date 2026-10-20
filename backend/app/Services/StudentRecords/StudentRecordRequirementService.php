<?php

namespace App\Services\StudentRecords;

use App\Models\DocumentType;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentDocumentSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StudentRecordRequirementService
{
    private const DISK = 'local';

    public function __construct(private readonly StudentRecordAuditWriter $audit) {}

    /** @return Collection<int, DocumentType> */
    public function types(): Collection
    {
        return DocumentType::query()->where('status', 'active')->where('student_uploadable', true)
            ->orderBy('document_name')
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function requirements(Student $student): Collection
    {
        $documents = $student->documents()->with(['documentType', 'verifiedBy.profile'])->get()->keyBy('document_type_id');

        return $this->types()->map(fn (DocumentType $type): array => $this->item($type, $documents->get($type->id)));
    }

    /** @return array<string, mixed> */
    public function item(DocumentType $type, ?StudentDocument $document): array
    {
        $digitalStatus = $this->digitalStatus($document);

        return [
            'id' => $document?->id,
            'document_type_id' => $type->id,
            'name' => $type->document_name,
            'digital_status' => $digitalStatus,
            'has_file' => filled($document?->file_path),
            'verification_status' => $document?->verification_status ?? 'pending',
            'version' => $document?->verification_version ?? 0,
            'submitted_date' => $document?->submitted_date?->toDateString(),
            'verified_at' => $document?->verified_at?->toISOString(),
            'verified_by' => $document?->verifiedBy
                ? (trim(($document->verifiedBy->profile?->first_name ?? '').' '.($document->verifiedBy->profile?->last_name ?? '')) ?: $document->verifiedBy->username)
                : null,
            'remark' => $document?->remarks,
            'physical_status' => $document?->physical_copy_status ?? 'not_required',
            'physical_copy_required_at' => $document?->physical_copy_required_at?->toISOString(),
            'physical_copy_received_at' => $document?->physical_copy_received_at?->toISOString(),
            'physical_copy_instruction' => $document?->physical_copy_instruction,
            'can_upload' => in_array($digitalStatus, ['missing', 'needs_resubmission'], true),
            'unresolved' => in_array($digitalStatus, ['missing', 'submitted', 'under_review', 'needs_resubmission'], true)
                || $document?->physical_copy_status === 'required',
        ];
    }

    public function digitalStatus(?StudentDocument $document): string
    {
        if (! $document || ! $document->file_path || $document->availability_status === 'missing') {
            return 'missing';
        }

        return match ($document->verification_status) {
            'verified' => 'verified',
            'rejected' => 'needs_resubmission',
            default => 'under_review',
        };
    }

    public function upload(User $actor, Student $student, DocumentType $type, UploadedFile $file): StudentDocument
    {
        abort_unless($type->status === 'active' && $type->workflow_category === 'supporting_record' && $type->student_uploadable, 404);
        $path = $this->store($student, $type, $file);
        $oldPath = null;

        try {
            $document = DB::transaction(function () use ($actor, $student, $type, $path, &$oldPath): StudentDocument {
                Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
                $document = StudentDocument::query()->where('student_id', $student->id)
                    ->where('document_type_id', $type->id)->lockForUpdate()->first();
                $status = $this->digitalStatus($document);
                abort_unless(in_array($status, ['missing', 'needs_resubmission'], true), 409, 'This document is already awaiting review or verified.');

                if (! $document) {
                    $document = new StudentDocument(['student_id' => $student->id, 'document_type_id' => $type->id]);
                }
                $oldPath = $document->file_path;
                $version = $document->exists && $oldPath ? $document->verification_version + 1 : max(1, $document->verification_version ?? 1);
                if ($oldPath) {
                    StudentDocumentSubmission::query()->firstOrCreate(
                        ['student_document_id' => $document->id, 'version' => $document->verification_version],
                        [
                            'file_path' => $oldPath,
                            'submitted_by_user_id' => null,
                            'submitted_at' => $document->submitted_date ?? $document->created_at ?? now(),
                            'verification_status' => $document->verification_status,
                            'reviewed_at' => $document->verification_status === 'pending' ? null : $document->updated_at,
                            'review_remark' => $document->remarks,
                        ],
                    );
                }
                $document->fill([
                    'file_path' => $path,
                    'availability_status' => 'available',
                    'verification_status' => 'pending',
                    'verification_version' => $version,
                    'submitted_date' => today(),
                    'verified_at' => null,
                    'verified_by_user_id' => null,
                    'remarks' => null,
                ])->save();

                StudentDocumentSubmission::query()->create([
                    'student_document_id' => $document->id,
                    'version' => $version,
                    'file_path' => $path,
                    'submitted_by_user_id' => $actor->id,
                    'submitted_at' => now(),
                    'verification_status' => 'pending',
                ]);
                $this->audit->record($actor, $student->id, $oldPath ? 'student_document_resubmitted' : 'student_document_submitted', [
                    'document_id' => $document->id,
                    'document_type_id' => $type->id,
                    'version' => $version,
                    'changed_fields' => ['file', 'verification_status', 'submitted_date'],
                ]);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        }

        return $document->fresh(['documentType', 'verifiedBy.profile']);
    }

    public function review(User $actor, StudentDocument $document, string $action, int $version, ?string $reason): StudentDocument
    {
        return DB::transaction(function () use ($actor, $document, $action, $version, $reason): StudentDocument {
            $locked = StudentDocument::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless($locked->verification_version === $version, 409, 'This document has a newer submission. Reload before reviewing.');
            abort_unless($locked->file_path && $locked->availability_status === 'available', 409, 'No submitted digital copy is available for review.');
            abort_unless(in_array($action, ['verify', 'needs-resubmission'], true), 404);
            abort_if($action === 'needs-resubmission' && blank($reason), 422, 'A resubmission reason is required.');

            $verified = $action === 'verify';
            $locked->update([
                'verification_status' => $verified ? 'verified' : 'rejected',
                'verified_at' => $verified ? now() : null,
                'verified_by_user_id' => $verified ? $actor->id : null,
                'remarks' => $verified ? null : trim((string) $reason),
            ]);
            $locked->submissions()->where('version', $version)->update([
                'verification_status' => $verified ? 'verified' : 'rejected',
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_remark' => $verified ? null : trim((string) $reason),
            ]);
            $this->audit->record($actor, $locked->student_id, $verified ? 'student_document_verified' : 'student_document_resubmission_requested', [
                'document_id' => $locked->id,
                'document_type_id' => $locked->document_type_id,
                'version' => $version,
                'changed_fields' => ['verification_status', 'verified_at', 'verified_by_user_id', 'remarks'],
                ...($verified ? [] : ['reason' => trim((string) $reason)]),
            ]);

            return $locked->fresh(['documentType', 'verifiedBy.profile']);
        });
    }

    public function physical(User $actor, Student $student, DocumentType $type, string $action, ?string $instruction, ?string $reason, ?string $remark = null): StudentDocument
    {
        abort_unless(
            $type->student_uploadable || StudentDocument::query()
                ->where('student_id', $student->id)
                ->where('document_type_id', $type->id)
                ->exists(),
            404,
        );

        return DB::transaction(function () use ($actor, $student, $type, $action, $instruction, $reason, $remark): StudentDocument {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $document = StudentDocument::query()->where('student_id', $student->id)
                ->where('document_type_id', $type->id)->lockForUpdate()->firstOrCreate([
                    'student_id' => $student->id,
                    'document_type_id' => $type->id,
                ]);

            if ($action === 'require') {
                abort_unless($document->physical_copy_status !== 'submitted', 409, 'Use the reopen action for a previously received physical copy.');
                abort_if($document->physical_copy_status === 'required', 409, 'This physical copy is already required.');
                $document->update([
                    'physical_copy_status' => 'required',
                    'physical_copy_required_at' => now(),
                    'physical_copy_required_by_user_id' => $actor->id,
                    'physical_copy_received_at' => null,
                    'physical_copy_received_by_user_id' => null,
                    'physical_copy_instruction' => $instruction ?: 'Please submit the physical/original copy to the Registrar Office.',
                ]);
                $event = 'student_document_hard_copy_required';
            } elseif ($action === 'receive') {
                abort_unless($document->physical_copy_status === 'required', 409, 'This physical copy is not currently required.');
                $document->update([
                    'physical_copy_status' => 'submitted',
                    'physical_copy_received_at' => now(),
                    'physical_copy_received_by_user_id' => $actor->id,
                ]);
                $event = 'student_document_hard_copy_received';
            } else {
                abort_unless($action === 'reopen', 404);
                abort_unless($document->physical_copy_status === 'submitted', 409, 'Only a received physical copy can be required again.');
                abort_if(blank($reason), 422, 'A reason is required when reopening a physical-copy requirement.');
                $document->update([
                    'physical_copy_status' => 'required',
                    'physical_copy_required_at' => now(),
                    'physical_copy_required_by_user_id' => $actor->id,
                    'physical_copy_instruction' => trim((string) $reason),
                ]);
                $event = 'student_document_hard_copy_reopened';
            }

            $this->audit->record($actor, $student->id, $event, [
                'document_id' => $document->id,
                'document_type_id' => $type->id,
                'changed_fields' => ['physical_copy_status', 'physical_copy_required_at', 'physical_copy_received_at'],
                ...($action === 'reopen' ? ['reason' => trim((string) $reason)] : []),
                ...($action === 'require' && filled($instruction) ? ['instruction' => trim((string) $instruction)] : []),
                ...($action === 'receive' && filled($remark) ? ['remark' => trim((string) $remark)] : []),
            ]);

            return $document->fresh(['documentType', 'verifiedBy.profile']);
        });
    }

    private function store(Student $student, DocumentType $type, UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf',
            default => throw new RuntimeException('The validated document type could not be stored.'),
        };
        $path = $file->storeAs(
            sprintf('student-documents/%d/%d-%s', $student->id, $type->id, Str::slug($type->document_name)),
            Str::uuid().'.'.$extension,
            self::DISK,
        );
        if (! is_string($path)) {
            throw new RuntimeException('The document could not be stored.');
        }

        return $path;
    }
}
