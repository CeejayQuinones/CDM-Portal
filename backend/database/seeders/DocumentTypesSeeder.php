<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['document_name' => 'Certificate of Enrollment', 'description' => 'Official proof of current enrollment.', 'processing_fee' => 50, 'processing_days' => 1, 'requires_appointment' => true, 'status' => 'active'],
            ['document_name' => 'Transcript of Records', 'description' => 'Official academic record.', 'processing_fee' => 150, 'processing_days' => 3, 'requires_appointment' => true, 'status' => 'active'],
            ['document_name' => 'Good Moral Certificate', 'description' => 'Certificate of good moral character.', 'processing_fee' => 25, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'active'],
            ['document_name' => 'Birth Certificate', 'description' => 'Birth certificate held in the student record.', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'inactive'],
            ['document_name' => 'Form 137', 'description' => 'Permanent secondary school record held in the student file.', 'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false, 'status' => 'inactive'],
        ] as $documentType) {
            DB::table('document_types')->updateOrInsert(
                ['document_name' => $documentType['document_name']],
                [...$documentType, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
