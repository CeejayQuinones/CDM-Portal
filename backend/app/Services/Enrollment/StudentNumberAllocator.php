<?php

namespace App\Services\Enrollment;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

class StudentNumberAllocator
{
    public function next(int $year): string
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Student number allocation requires a transaction.');
        $prefix = str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT);
        $maximum = $this->existingMaximum($prefix);

        DB::table('student_number_sequences')->insertOrIgnore([
            'year_prefix' => $prefix, 'last_number' => $maximum, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DB::table('student_number_sequences')->where('year_prefix', $prefix)->lockForUpdate()->first();
        $next = max((int) $sequence->last_number, $this->existingMaximum($prefix)) + 1;
        while ($next <= 99999 && Student::where('student_number', $this->format($prefix, $next))->lockForUpdate()->exists()) {
            $next++;
        }
        abort_if($next > 99999, 409, "The {$prefix} Student number sequence is exhausted.");

        DB::table('student_number_sequences')->where('year_prefix', $prefix)->update([
            'last_number' => $next, 'updated_at' => now(),
        ]);

        return $this->format($prefix, $next);
    }

    private function existingMaximum(string $prefix): int
    {
        return Student::where('student_number', 'like', $prefix.'-%')->lockForUpdate()->pluck('student_number')
            ->reduce(function (int $maximum, ?string $number) use ($prefix): int {
                return preg_match('/^'.preg_quote($prefix, '/').'-(\d{5})$/', (string) $number, $match)
                    ? max($maximum, (int) $match[1]) : $maximum;
            }, 0);
    }

    private function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
