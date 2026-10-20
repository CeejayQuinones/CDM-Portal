<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['document_name' => 'Certificate of Enrollment', 'description' => 'Official proof of current enrollment.', 'workflow_category' => 'official_issuance', 'student_requestable' => true, 'student_uploadable' => false, 'processing_fee' => 50, 'processing_days' => 1, 'requires_appointment' => true, 'status' => 'active'],
            ['document_name' => 'Transcript of Records', 'description' => 'Official academic record.', 'workflow_category' => 'official_issuance', 'student_requestable' => true, 'student_uploadable' => false, 'processing_fee' => 150, 'processing_days' => 3, 'requires_appointment' => true, 'status' => 'active'],
            ['document_name' => 'Good Moral Certificate', 'description' => 'Certificate of good moral character.', 'workflow_category' => 'supporting_record', 'student_requestable' => true, 'student_uploadable' => true, 'processing_fee' => 25, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active'],
            ['document_name' => 'Birth Certificate', 'description' => 'Birth certificate held in the student record.', 'workflow_category' => 'supporting_record', 'student_requestable' => false, 'student_uploadable' => true, 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'inactive'],
            ['document_name' => 'Form 137', 'description' => 'Permanent secondary school record held in the student file.', 'workflow_category' => 'supporting_record', 'student_requestable' => false, 'student_uploadable' => true, 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active'],
            ['document_name' => 'Form 138', 'description' => 'Student report card submitted as a supporting academic record.', 'workflow_category' => 'supporting_record', 'student_requestable' => false, 'student_uploadable' => true, 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active'],
            ['document_name' => 'Statement of Grades', 'description' => 'Official grade statement issued by the Registrar.', 'workflow_category' => 'official_issuance', 'student_requestable' => true, 'student_uploadable' => false, 'processing_fee' => 50, 'processing_days' => 2, 'requires_appointment' => false, 'status' => 'active'],
        ] as $documentType) {
            DB::table('document_types')->updateOrInsert(
                ['document_name' => $documentType['document_name']],
                [...$documentType, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
