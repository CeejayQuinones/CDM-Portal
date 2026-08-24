<?php

namespace Database\Seeders;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class LargeDatasetCleanupSeeder extends Seeder
{
    private const DELETE_CHUNK_SIZE = 500;

    private const STRESS_USERNAME_PATTERN = 'stress\\_student\\_%';

    private const STRESS_EMAIL_PATTERN = 'stress%@example.test';

    private const STRESS_STUDENT_NUMBER_PATTERN = 'STRESS-%';

    private const LEGACY_STRESS_CABINET_CODES = [
        'STRESS-CAB-01',
        'STRESS-CAB-02',
        'STRESS-CAB-03',
        'STRESS-CAB-04',
        'STRESS-CAB-05',
        'STRESS-CAB-06',
        'STRESS-CAB-07',
        'STRESS-CAB-08',
    ];

    public function run(): void
    {
        $userIds = $this->stressUserIds();
        $studentIds = $this->stressStudentIds($userIds);
        $cabinetIds = $this->stressCabinetIds();
        $slotIds = $this->stressCabinetSlotIds($cabinetIds);
        $recordLocationIds = $this->stressRecordLocationIds($studentIds, $slotIds);

        $counts = [
            'appointments' => $this->countForStudents('appointments', $studentIds),
            'document_requests' => $this->countForStudents('document_requests', $studentIds),
            'student_documents' => $this->countForStudents('student_documents', $studentIds),
            'students' => count($studentIds),
            'user_profiles' => $this->countWhereIn('user_profiles', 'user_id', $userIds),
            'personal_access_tokens' => $this->countPersonalAccessTokens($userIds),
            'users' => count($userIds),
            'student_record_locations' => count($recordLocationIds),
            'cabinet_slots' => count($slotIds),
            'cabinets' => count($cabinetIds),
        ];

        $this->command?->info('Found LargeDatasetSeeder records:');
        $this->command?->line(sprintf('  %s stress users', number_format($counts['users'])));
        $this->command?->line(sprintf('  %s user profiles', number_format($counts['user_profiles'])));
        $this->command?->line(sprintf('  %s students', number_format($counts['students'])));
        $this->command?->line(sprintf('  %s student documents', number_format($counts['student_documents'])));
        $this->command?->line(sprintf('  %s document requests', number_format($counts['document_requests'])));
        $this->command?->line(sprintf('  %s appointments', number_format($counts['appointments'])));
        $this->command?->line(sprintf('  %s personal access tokens', number_format($counts['personal_access_tokens'])));
        $this->command?->line(sprintf('  %s student record locations', number_format($counts['student_record_locations'])));
        $this->command?->line(sprintf('  %s stress cabinet slots', number_format($counts['cabinet_slots'])));
        $this->command?->line(sprintf('  %s stress cabinets', number_format($counts['cabinets'])));

        if ($userIds === [] && $studentIds === [] && $cabinetIds === [] && $recordLocationIds === []) {
            $this->verifyCleanup();
            $this->command?->info('No LargeDatasetSeeder records were found.');

            return;
        }

        $hasPersonalAccessTokens = Schema::hasTable('personal_access_tokens');
        DB::transaction(function () use ($studentIds, $userIds, $hasPersonalAccessTokens, $recordLocationIds, $slotIds, $cabinetIds): void {
            foreach (array_chunk($recordLocationIds, self::DELETE_CHUNK_SIZE) as $recordLocationIdChunk) {
                DB::table('student_record_locations')->whereIn('id', $recordLocationIdChunk)->delete();
            }

            foreach (array_chunk($studentIds, self::DELETE_CHUNK_SIZE) as $studentIdChunk) {
                DB::table('appointments')->whereIn('student_id', $studentIdChunk)->delete();
                DB::table('document_requests')->whereIn('student_id', $studentIdChunk)->delete();
                DB::table('student_documents')->whereIn('student_id', $studentIdChunk)->delete();
                DB::table('students')->whereIn('id', $studentIdChunk)->delete();
            }

            foreach (array_chunk($userIds, self::DELETE_CHUNK_SIZE) as $userIdChunk) {
                if ($hasPersonalAccessTokens) {
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', 'App\\Models\\User')
                        ->whereIn('tokenable_id', $userIdChunk)
                        ->delete();
                }

                DB::table('user_profiles')->whereIn('user_id', $userIdChunk)->delete();
                DB::table('users')->whereIn('id', $userIdChunk)->delete();
            }

            foreach (array_chunk($slotIds, self::DELETE_CHUNK_SIZE) as $slotIdChunk) {
                DB::table('cabinet_slots')->whereIn('id', $slotIdChunk)->delete();
            }

            foreach (array_chunk($cabinetIds, self::DELETE_CHUNK_SIZE) as $cabinetIdChunk) {
                DB::table('cabinets')->whereIn('id', $cabinetIdChunk)->delete();
            }
        });

        $this->verifyCleanup();

        $this->command?->info(sprintf(
            'Removed stress data only: %d users, %d profiles, %d students, %d documents, %d requests, %d appointments, %d personal access tokens, %d record locations, %d cabinet slots, %d cabinets.',
            $counts['users'],
            $counts['user_profiles'],
            $counts['students'],
            $counts['student_documents'],
            $counts['document_requests'],
            $counts['appointments'],
            $counts['personal_access_tokens'],
            $counts['student_record_locations'],
            $counts['cabinet_slots'],
            $counts['cabinets'],
        ));
    }

    /** @return array<int, int> */
    private function stressUserIds(): array
    {
        $userIds = $this->stressUsernameQuery()->pluck('id');

        if (Schema::hasTable('user_profiles')) {
            $userIds = $userIds->merge(
                DB::table('user_profiles')
                    ->where('email', 'like', self::STRESS_EMAIL_PATTERN)
                    ->pluck('user_id')
            );
        }

        if (Schema::hasTable('students')) {
            $userIds = $userIds->merge(
                DB::table('students')
                    ->where('student_number', 'like', self::STRESS_STUDENT_NUMBER_PATTERN)
                    ->pluck('user_id')
            );
        }

        return $this->normalizeIds($userIds);
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function stressStudentIds(array $userIds): array
    {
        if (! Schema::hasTable('students')) {
            return [];
        }

        $studentIds = DB::table('students')
            ->where('student_number', 'like', self::STRESS_STUDENT_NUMBER_PATTERN)
            ->pluck('id');

        foreach (array_chunk($userIds, self::DELETE_CHUNK_SIZE) as $userIdChunk) {
            $studentIds = $studentIds->merge(
                DB::table('students')->whereIn('user_id', $userIdChunk)->pluck('id')
            );
        }

        return $this->normalizeIds($studentIds);
    }

    private function stressUsernameQuery(): Builder
    {
        return DB::table('users')
            ->where('username', 'like', self::STRESS_USERNAME_PATTERN);
    }

    /** @return array<int, int> */
    private function stressCabinetIds(): array
    {
        if (! Schema::hasTable('cabinets')) {
            return [];
        }

        return $this->normalizeIds(
            $this->stressCabinetQuery()->pluck('id')
        );
    }

    private function stressCabinetQuery(): Builder
    {
        return DB::table('cabinets')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $generatedCabinets): void {
                    $generatedCabinets
                        ->whereIn('cabinet_code', LargeDatasetSeeder::PHYSICAL_RECORD_CABINET_CODES)
                        ->where(
                            'description',
                            'like',
                            '%'.LargeDatasetSeeder::PHYSICAL_RECORD_CABINET_MARKER.'%',
                        );
                })->orWhereIn('cabinet_code', self::LEGACY_STRESS_CABINET_CODES);
            });
    }

    /**
     * @param  array<int, int>  $cabinetIds
     * @return array<int, int>
     */
    private function stressCabinetSlotIds(array $cabinetIds): array
    {
        if (! Schema::hasTable('cabinet_slots')) {
            return [];
        }

        $slotIds = collect();

        foreach (array_chunk($cabinetIds, self::DELETE_CHUNK_SIZE) as $cabinetIdChunk) {
            $slotIds = $slotIds->merge(
                DB::table('cabinet_slots')->whereIn('cabinet_id', $cabinetIdChunk)->pluck('id')
            );
        }

        return $this->normalizeIds($slotIds);
    }

    /**
     * @param  array<int, int>  $studentIds
     * @param  array<int, int>  $slotIds
     * @return array<int, int>
     */
    private function stressRecordLocationIds(array $studentIds, array $slotIds): array
    {
        if (! Schema::hasTable('student_record_locations')) {
            return [];
        }

        $locationIds = collect();

        foreach (array_chunk($studentIds, self::DELETE_CHUNK_SIZE) as $studentIdChunk) {
            $locationIds = $locationIds->merge(
                DB::table('student_record_locations')->whereIn('student_id', $studentIdChunk)->pluck('id')
            );
        }

        foreach (array_chunk($slotIds, self::DELETE_CHUNK_SIZE) as $slotIdChunk) {
            $locationIds = $locationIds->merge(
                DB::table('student_record_locations')->whereIn('cabinet_slot_id', $slotIdChunk)->pluck('id')
            );
        }

        return $this->normalizeIds($locationIds);
    }

    private function verifyCleanup(): void
    {
        $remainingUsers = $this->stressUsernameQuery()->count();
        $remainingStudents = Schema::hasTable('students')
            ? DB::table('students')->where('student_number', 'like', self::STRESS_STUDENT_NUMBER_PATTERN)->count()
            : 0;
        $remainingCabinets = Schema::hasTable('cabinets')
            ? $this->stressCabinetQuery()->count()
            : 0;

        if ($remainingUsers !== 0 || $remainingStudents !== 0 || $remainingCabinets !== 0) {
            throw new LogicException(sprintf(
                'Large dataset cleanup verification failed: %d stress usernames, %d STRESS-* students, and %d stress cabinets remain.',
                $remainingUsers,
                $remainingStudents,
                $remainingCabinets,
            ));
        }

        $this->command?->info('Verified: 0 stress usernames, 0 STRESS-* student records, and 0 stress cabinets remain.');
    }

    /** @param Collection<int, mixed> $ids */
    private function normalizeIds(Collection $ids): array
    {
        return $ids
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int, int> $studentIds */
    private function countForStudents(string $table, array $studentIds): int
    {
        return $this->countWhereIn($table, 'student_id', $studentIds);
    }

    /** @param array<int, int> $ids */
    private function countWhereIn(string $table, string $column, array $ids): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $count = 0;

        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $idChunk) {
            $count += DB::table($table)->whereIn($column, $idChunk)->count();
        }

        return $count;
    }

    /** @param array<int, int> $userIds */
    private function countPersonalAccessTokens(array $userIds): int
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return 0;
        }

        $count = 0;

        foreach (array_chunk($userIds, self::DELETE_CHUNK_SIZE) as $userIdChunk) {
            $count += DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $userIdChunk)
                ->count();
        }

        return $count;
    }
}
