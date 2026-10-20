<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppointmentBlockedDateSeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            ['reason' => "New Year's Day", 'blocked_date' => '2026-01-01'],
            ['reason' => 'Chinese New Year', 'blocked_date' => '2026-02-17'],
            ['reason' => "Eid'l Fitr (Feast of Ramadhan)", 'blocked_date' => '2026-03-20'],
            ['reason' => 'Maundy Thursday', 'blocked_date' => '2026-04-02'],
            ['reason' => 'Good Friday', 'blocked_date' => '2026-04-03'],
            ['reason' => 'Black Saturday', 'blocked_date' => '2026-04-04'],
            ['reason' => 'Araw ng Kagitingan', 'blocked_date' => '2026-04-09'],
            ['reason' => 'Labor Day', 'blocked_date' => '2026-05-01'],
            ['reason' => "Eid'l Adha (Feast of Sacrifice)", 'blocked_date' => '2026-05-27'],
            ['reason' => 'Independence Day', 'blocked_date' => '2026-06-12'],
            ['reason' => 'Ninoy Aquino Day', 'blocked_date' => '2026-08-21'],
            ['reason' => 'National Heroes Day', 'blocked_date' => '2026-08-31'],
            ['reason' => "All Saints' Day", 'blocked_date' => '2026-11-01'],
            ['reason' => "All Souls' Day", 'blocked_date' => '2026-11-02'],
            ['reason' => 'Bonifacio Day', 'blocked_date' => '2026-11-30'],
            ['reason' => 'Feast of the Immaculate Conception of Mary', 'blocked_date' => '2026-12-08'],
            ['reason' => 'Christmas Eve', 'blocked_date' => '2026-12-24'],
            ['reason' => 'Christmas Day', 'blocked_date' => '2026-12-25'],
            ['reason' => 'Rizal Day', 'blocked_date' => '2026-12-30'],
            ['reason' => 'Last Day of the Year', 'blocked_date' => '2026-12-31'],
        ];

        foreach ($holidays as $holiday) {
            DB::table('appointment_blocked_dates')->updateOrInsert(
                [
                    'blocked_date' => $holiday['blocked_date'],
                    'type' => 'holiday',
                    'reason' => $holiday['reason'],
                ],
                [
                    'is_active' => true,
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
