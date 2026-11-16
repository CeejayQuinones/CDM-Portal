<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // National non-working dates in Proclamations 1006 (2026) and 1427 (2027).
        // Eid dates and local/school closures require separate confirmation.
        $holidays = [
            '2026-11-30' => 'Bonifacio Day',
            '2026-12-08' => 'Feast of the Immaculate Conception',
            '2026-12-24' => 'Christmas Eve',
            '2026-12-25' => 'Christmas Day',
            '2026-12-30' => 'Rizal Day',
            '2026-12-31' => 'Last Day of the Year',
            '2027-01-01' => "New Year's Day",
            '2027-02-06' => 'Chinese New Year',
            '2027-03-25' => 'Maundy Thursday',
            '2027-03-26' => 'Good Friday',
            '2027-03-27' => 'Black Saturday',
            '2027-04-09' => 'Araw ng Kagitingan',
            '2027-05-01' => 'Labor Day',
            '2027-06-12' => 'Independence Day',
            '2027-08-21' => 'Ninoy Aquino Day',
            '2027-08-30' => 'National Heroes Day',
            '2027-11-01' => "All Saints' Day",
            '2027-11-02' => "All Souls' Day",
            '2027-11-30' => 'Bonifacio Day',
            '2027-12-08' => 'Feast of the Immaculate Conception',
            '2027-12-24' => 'Christmas Eve',
            '2027-12-25' => 'Christmas Day',
            '2027-12-30' => 'Rizal Day',
            '2027-12-31' => 'Last Day of the Year',
        ];

        foreach ($holidays as $date => $reason) {
            // Preserve any registrar decision, including a previously unblocked date.
            if (DB::table('appointment_blocked_dates')->where('blocked_date', $date)->where('type', 'holiday')->exists()) {
                continue;
            }
            $hasBookedAppointments = DB::table('appointments')
                ->whereDate('appointment_date', $date)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();
            DB::table('appointment_blocked_dates')->insert([
                'blocked_date' => $date,
                'type' => 'holiday',
                'reason' => $reason,
                // A new preset must not strand an appointment made before deployment.
                'is_active' => ! $hasBookedAppointments,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep registrar changes and appointment availability decisions on rollback.
    }
};
