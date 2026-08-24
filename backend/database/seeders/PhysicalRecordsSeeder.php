<?php

namespace Database\Seeders;

use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhysicalRecordsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $johnDoe = Student::query()
                ->where('student_number', '26-00001')
                ->firstOrFail();
            $cabinetId = DB::table('cabinets')
                ->where('cabinet_code', 'A')
                ->value('id');

            if (! $cabinetId) {
                $cabinetId = DB::table('cabinets')->insertGetId([
                    'cabinet_code' => 'A',
                    'description' => 'Starter cabinet for physical student records.',
                    'rows' => 2,
                    'columns' => 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $slotIds = [];

            for ($position = 1; $position <= 6; $position++) {
                $slotCode = 'A'.$position;
                $slotId = DB::table('cabinet_slots')
                    ->where('cabinet_id', $cabinetId)
                    ->where('slot_code', $slotCode)
                    ->value('id');

                if (! $slotId) {
                    $slotId = DB::table('cabinet_slots')->insertGetId([
                        'cabinet_id' => $cabinetId,
                        'slot_code' => $slotCode,
                        'capacity' => 10,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $slotIds[$slotCode] = $slotId;
            }

            if (! DB::table('student_record_locations')->where('student_id', $johnDoe->id)->exists()) {
                DB::table('student_record_locations')->insert([
                    'student_id' => $johnDoe->id,
                    'cabinet_slot_id' => $slotIds['A2'],
                    'assigned_at' => $now,
                    'remarks' => 'Starter physical record location for manual testing.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
