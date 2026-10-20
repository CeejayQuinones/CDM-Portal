<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment\EnrollmentApplication;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\StudentRecords\StudentRecordAuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EnrollmentDocumentHandoffService
{
    private const SUPPORTED_DOCUMENT_TYPES = [
        'form 137',
        'good moral certificate',
        'certificate of enrollment',
    ];

    public function __construct(private StudentRecordAuditWriter $audit) {}

    public function link(User $actor, EnrollmentApplication $application, Student $student): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Enrollment document handoff requires a transaction.');
        }

        $documents = $application->documents()
            ->with('documentType:id,document_name,status')
            ->lockForUpdate()
            ->get();

        foreach ($documents as $source) {
            if ($source->student_document_id) {
                $linked = StudentDocument::query()->lockForUpdate()->findOrFail($source->student_document_id);
                if ($linked->student_id !== $student->id || $linked->document_type_id !== $source->document_type_id) {
                    throw new \LogicException('Enrollment document linkage does not match the Student identity.');
                }

                continue;
            }

            $type = $source->documentType;
            $supported = $type?->status === 'active'
                && in_array(mb_strtolower(trim($type->document_name)), self::SUPPORTED_DOCUMENT_TYPES, true);
            if (! $supported || ! $source->file_path || ! Storage::disk('local')->exists($source->file_path)) {
                continue;
            }

            $destination = StudentDocument::query()
                ->where('student_id', $student->id)
                ->where('document_type_id', $source->document_type_id)
                ->lockForUpdate()
                ->first();

            if ($destination && ($destination->verification_status === 'verified'
                || ($destination->file_path && Storage::disk('local')->exists($destination->file_path)))) {
                continue;
            }

            $values = [
                'file_path' => $source->file_path,
                'availability_status' => 'available',
                'verification_status' => 'pending',
                'submitted_date' => $source->created_at?->toDateString() ?? now()->toDateString(),
                'verification_version' => max(1, (int) ($destination?->verification_version ?? 1)),
            ];
            if ($destination) {
                $destination->fill($values)->save();
            } else {
                $destination = StudentDocument::query()->create($values + [
                    'student_id' => $student->id,
                    'document_type_id' => $source->document_type_id,
                ]);
            }

            $source->student_document_id = $destination->id;
            $source->save();
            $this->audit->record($actor, $student->id, 'enrollment_document_linked', [
                'student_id' => $student->id,
                'enrollment_application_id' => $application->id,
                'enrollment_document_id' => $source->id,
                'document_type_id' => $source->document_type_id,
            ]);
        }
    }
}
