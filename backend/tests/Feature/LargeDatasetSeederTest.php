<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LargeDatasetCleanupSeeder;
use Database\Seeders\LargeDatasetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LargeDatasetSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_records_are_realistic_hidden_from_cleanup_markers_and_safely_removable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(LargeDatasetSeeder::class);

        $this->assertDatabaseCount('generated_data_records', 10_008);
        $this->assertSame(10_000, DB::table('generated_data_records')
            ->where('dataset_key', LargeDatasetSeeder::DATASET_KEY)
            ->where('record_type', LargeDatasetSeeder::GENERATED_USER_RECORD_TYPE)
            ->count());
        $this->assertSame(8, DB::table('generated_data_records')
            ->where('dataset_key', LargeDatasetSeeder::DATASET_KEY)
            ->where('record_type', LargeDatasetSeeder::GENERATED_CABINET_RECORD_TYPE)
            ->count());

        $generatedUsers = DB::table('users')
            ->join('generated_data_records', function ($join): void {
                $join
                    ->on('users.id', '=', 'generated_data_records.record_id')
                    ->on('users.created_at', '=', 'generated_data_records.record_created_at');
            })
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->join('students', 'students.user_id', '=', 'users.id')
            ->where('generated_data_records.dataset_key', LargeDatasetSeeder::DATASET_KEY)
            ->where('generated_data_records.record_type', LargeDatasetSeeder::GENERATED_USER_RECORD_TYPE)
            ->get([
                'users.username',
                'user_profiles.first_name',
                'user_profiles.last_name',
                'user_profiles.email',
                'students.student_number',
                'students.year_level',
            ]);

        $this->assertCount(10_000, $generatedUsers);
        $this->assertCount(10_000, $generatedUsers->pluck('username')->unique());
        $this->assertCount(10_000, $generatedUsers->pluck('email')->unique());

        $allStudentNumbersAreRealistic = true;
        $allUsernamesMatchNames = true;
        $allEmailsAreSchoolAddresses = true;

        foreach ($generatedUsers as $generatedUser) {
            $nameBase = Str::of($generatedUser->first_name.'.'.$generatedUser->last_name)
                ->ascii()
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '.')
                ->trim('.')
                ->value();

            $allStudentNumbersAreRealistic = $allStudentNumbersAreRealistic
                && preg_match('/^(22|23|24|25|26)-\d{5}$/', $generatedUser->student_number) === 1;
            $allUsernamesMatchNames = $allUsernamesMatchNames
                && str_starts_with($generatedUser->username, substr($nameBase, 0, 20));
            $allEmailsAreSchoolAddresses = $allEmailsAreSchoolAddresses
                && preg_match('/^[a-z0-9.]+@cdm\.edu\.ph$/', $generatedUser->email) === 1;
        }

        $this->assertTrue($allStudentNumbersAreRealistic);
        $this->assertTrue($allUsernamesMatchNames);
        $this->assertTrue($allEmailsAreSchoolAddresses);
        $this->assertSame(50_000, DB::table('document_requests')->count());
        $this->assertSame(20_000, DB::table('appointments')->count());
        $this->assertSame(0, DB::table('document_requests')->whereIn('status', ['processing', 'ready_for_release', 'released'])->count());
        $this->assertSame(LargeDatasetSeeder::PENDING_REQUEST_COUNT, DB::table('document_requests')->where('status', 'pending')->count());
        $this->assertSame(LargeDatasetSeeder::APPROVED_REQUEST_COUNT, DB::table('document_requests')->where('status', 'approved')->count());
        $this->assertSame(LargeDatasetSeeder::COMPLETED_REQUEST_COUNT, DB::table('document_requests')->where('status', 'completed')->count());

        foreach ($this->visibleTextColumns() as $table => $columns) {
            foreach ($columns as $column) {
                foreach (['stress', 'faker', 'dummy', 'test', 'largedatasetseeder'] as $forbiddenTerm) {
                    $this->assertSame(
                        0,
                        DB::table($table)->whereRaw('LOWER('.$column.') LIKE ?', ['%'.$forbiddenTerm.'%'])->count(),
                        "Unexpected visible generated marker in {$table}.{$column}.",
                    );
                }
            }
        }

        $this->seed(LargeDatasetCleanupSeeder::class);

        $this->assertDatabaseCount('generated_data_records', 0);
        $this->assertDatabaseHas('users', ['username' => 'student1']);
        $this->assertDatabaseHas('students', ['student_number' => '26-00001']);
        $this->assertDatabaseHas('cabinets', ['cabinet_code' => 'A']);
    }

    /** @return array<string, array<int, string>> */
    private function visibleTextColumns(): array
    {
        return [
            'users' => ['username'],
            'user_profiles' => ['first_name', 'middle_name', 'last_name', 'email', 'address'],
            'students' => ['student_number'],
            'cabinets' => ['description'],
            'student_record_locations' => ['remarks'],
            'student_documents' => ['remarks'],
            'document_requests' => ['purpose', 'remarks'],
            'appointments' => ['purpose', 'remarks'],
        ];
    }
}
