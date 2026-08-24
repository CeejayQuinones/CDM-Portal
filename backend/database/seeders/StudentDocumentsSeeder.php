<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Database\Seeder;

class StudentDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        $student = Student::query()->where('student_number', '26-00001')->firstOrFail();
        $records = [
            ['name' => 'Birth Certificate', 'availability_status' => 'available'],
            ['name' => 'Form 137', 'availability_status' => 'available'],
            ['name' => 'Good Moral Certificate', 'availability_status' => 'missing'],
            ['name' => 'Certificate of Enrollment', 'availability_status' => 'missing'],
            ['name' => 'Transcript of Records', 'availability_status' => 'missing'],
        ];

        foreach ($records as $record) {
            $documentType = DocumentType::query()->firstOrCreate(
                ['document_name' => $record['name']],
                [
                    'description' => 'Student record document.',
                    'processing_fee' => 0,
                    'processing_days' => 1,
                    'requires_appointment' => false,
                    'status' => in_array($record['name'], ['Birth Certificate', 'Form 137'], true) ? 'inactive' : 'active',
                ],
            );

            $available = $record['availability_status'] === 'available';
            StudentDocument::query()->updateOrCreate(
                ['student_id' => $student->id, 'document_type_id' => $documentType->id],
                [
                    'availability_status' => $record['availability_status'],
                    'verification_status' => $available ? 'verified' : 'pending',
                    'submitted_date' => $available ? $student->admission_date : null,
                    'remarks' => $available ? 'Starter record: document is on file.' : 'Starter record: document is not yet on file.',
                ],
            );
        }
    }
}
