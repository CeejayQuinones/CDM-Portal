<?php

namespace Database\Seeders;

use App\Models\Role;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class LargeDatasetSeeder extends Seeder
{
    public const USERNAME_PREFIX = 'stress_student_';

    public const PHYSICAL_RECORD_CABINET_MARKER = '[CDM:LARGE_DATASET_SEEDER:PHYSICAL_RECORDS:v1]';

    public const PHYSICAL_RECORD_CABINET_CODES = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];

    private const USERNAME_LIKE_PATTERN = 'stress\\_student\\_%';

    private const STUDENT_NUMBER_PREFIX = 'STRESS-';

    private const STUDENT_COUNT = 5_000;

    private const STUDENT_DOCUMENT_COUNT = 15_000;

    private const DOCUMENT_REQUEST_COUNT = 25_000;

    private const APPOINTMENT_COUNT = 10_000;

    private const BATCH_SIZE = 500;

    private const STRESS_CABINET_COUNT = 8;

    private const STRESS_CABINET_ROWS = 5;

    private const STRESS_CABINET_COLUMNS = 10;

    private const STRESS_SLOT_CAPACITY = 20;

    private const STRESS_OCCUPANCY_SHUFFLE_SEED = 260_825;

    private const STRESS_STUDENT_SHUFFLE_SEED = 260_826;

    private const DOCUMENT_NAMES = [
        'Birth Certificate',
        'Form 137',
        'Good Moral Certificate',
        'Certificate of Enrollment',
        'Transcript of Records',
    ];

    private const PROFILE_PHOTO_PATHS = [
        './profile-images/prf1.jpg',
        './profile-images/prf2.jpg',
        './profile-images/prf3.jpg',
        './profile-images/prf4.jpg',
        './profile-images/prf5.jpg',
        './profile-images/prf6.jpg',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('LargeDatasetSeeder is disabled in the production environment.');
        }

        if (
            ! Schema::hasColumn('student_documents', 'availability_status')
            || ! Schema::hasColumns('document_requests', ['approved_at', 'processed_at', 'ready_for_release_at', 'released_at', 'rejected_at'])
            || ! Schema::hasColumn('appointments', 'active_slot_key')
            || ! Schema::hasColumns('cabinets', ['cabinet_code', 'rows', 'columns'])
            || ! Schema::hasColumns('cabinet_slots', ['cabinet_id', 'slot_code', 'capacity'])
            || ! Schema::hasColumns('student_record_locations', ['student_id', 'cabinet_slot_id', 'assigned_at'])
        ) {
            throw new LogicException('Run php artisan migrate before LargeDatasetSeeder.');
        }

        $roleId = DB::table('roles')->where('role_name', Role::STUDENT)->value('id');
        $registrarIds = DB::table('registrar_staff')->pluck('id')->map(fn ($id) => (int) $id)->values();
        $coursePairs = DB::table('courses')
            ->join('curriculums', 'curriculums.course_id', '=', 'courses.id')
            ->where('courses.status', 'active')
            ->where('curriculums.status', 'active')
            ->orderBy('courses.id')
            ->orderBy('curriculums.id')
            ->get(['courses.id as course_id', 'curriculums.id as curriculum_id']);
        $studentDocumentTypes = DB::table('document_types')
            ->whereIn('document_name', self::DOCUMENT_NAMES)
            ->get(['id', 'document_name', 'processing_fee', 'requires_appointment'])
            ->keyBy('document_name');
        $activeDocumentTypes = DB::table('document_types')
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'document_name', 'processing_fee', 'requires_appointment'])
            ->values();
        $appointmentDocumentTypes = $activeDocumentTypes
            ->filter(fn (object $documentType): bool => (bool) $documentType->requires_appointment)
            ->values();

        if (! $roleId) {
            throw new LogicException('The existing Student role is required. Run the normal seeders first.');
        }
        if ($registrarIds->isEmpty()) {
            throw new LogicException('At least one existing Registrar Staff record is required.');
        }
        if ($coursePairs->isEmpty()) {
            throw new LogicException('At least one active course with an active curriculum is required.');
        }

        if ($activeDocumentTypes->isEmpty()) {
            throw new LogicException('At least one active document type is required before generating the large dataset.');
        }
        if ($appointmentDocumentTypes->isEmpty()) {
            throw new LogicException('At least one active document type with requires_appointment = true is required before generating the large dataset.');
        }

        $missingDocumentTypes = collect(self::DOCUMENT_NAMES)->diff($studentDocumentTypes->keys());
        if ($missingDocumentTypes->isNotEmpty()) {
            throw new LogicException('Missing existing document types: '.$missingDocumentTypes->join(', ').'. Run DocumentTypesSeeder first.');
        }

        $startedAt = microtime(true);
        $this->command?->warn('Rebuilding only data identified by the '.self::USERNAME_PREFIX.' prefix.');
        $this->call(LargeDatasetCleanupSeeder::class);

        $conflictingCabinetCodes = DB::table('cabinets')
            ->whereIn('cabinet_code', self::PHYSICAL_RECORD_CABINET_CODES)
            ->orderBy('cabinet_code')
            ->pluck('cabinet_code');

        if ($conflictingCabinetCodes->isNotEmpty()) {
            throw new LogicException(
                'Cannot generate physical-record cabinets because these normal cabinet codes already exist: '
                .$conflictingCabinetCodes->join(', ').'.',
            );
        }

        $faker = FakerFactory::create('en_PH');
        $faker->seed(260824);
        $now = CarbonImmutable::now()->startOfSecond();

        [$userIds, $studentIds] = DB::transaction(fn () => $this->seedStudentAccounts($faker, (int) $roleId, $coursePairs, $now));
        DB::transaction(fn () => $this->seedPhysicalRecordLocations($studentIds, $now));
        DB::transaction(fn () => $this->seedStudentDocuments($studentIds, $studentDocumentTypes, $now));
        DB::transaction(fn () => $this->seedDocumentRequests(
            $studentIds,
            $activeDocumentTypes,
            $appointmentDocumentTypes,
            $registrarIds,
            $now,
        ));
        DB::transaction(fn () => $this->seedAppointments($registrarIds, $now));
        $this->assertExpectedCounts($userIds, $studentIds);

        $this->command?->info(sprintf(
            'Large dataset ready in %.1f seconds: %s users/profiles/students, %s documents, %s requests, %s appointments.',
            microtime(true) - $startedAt,
            number_format(self::STUDENT_COUNT),
            number_format(self::STUDENT_DOCUMENT_COUNT),
            number_format(self::DOCUMENT_REQUEST_COUNT),
            number_format(self::APPOINTMENT_COUNT),
        ));
    }

    /**
     * @return array{
     *     cabinet_count: int,
     *     cabinet_codes: array<int, string>,
     *     rows: int,
     *     columns: int,
     *     slots_per_cabinet: int,
     *     slot_count: int,
     *     slot_capacity: int,
     *     student_count: int,
     *     average_occupancy: float,
     *     minimum_occupancy: int,
     *     maximum_occupancy: int,
     *     slot_occupancies: array<int, int>
     * }
     */
    public static function physicalRecordStressPlan(): array
    {
        $perCabinetOccupancies = [
            ...array_fill(0, 10, 5),
            ...array_fill(0, 30, 13),
            ...array_fill(0, 5, 18),
            ...array_fill(0, 5, 19),
        ];
        $randomizer = new Randomizer(new Mt19937(self::STRESS_OCCUPANCY_SHUFFLE_SEED));
        $slotOccupancies = [];

        for ($cabinet = 0; $cabinet < self::STRESS_CABINET_COUNT; $cabinet++) {
            array_push($slotOccupancies, ...$randomizer->shuffleArray($perCabinetOccupancies));
        }

        $studentCount = array_sum($slotOccupancies);
        $slotCount = count($slotOccupancies);

        return [
            'cabinet_count' => self::STRESS_CABINET_COUNT,
            'cabinet_codes' => self::PHYSICAL_RECORD_CABINET_CODES,
            'rows' => self::STRESS_CABINET_ROWS,
            'columns' => self::STRESS_CABINET_COLUMNS,
            'slots_per_cabinet' => self::STRESS_CABINET_ROWS * self::STRESS_CABINET_COLUMNS,
            'slot_count' => $slotCount,
            'slot_capacity' => self::STRESS_SLOT_CAPACITY,
            'student_count' => $studentCount,
            'average_occupancy' => $studentCount / $slotCount,
            'minimum_occupancy' => min($slotOccupancies),
            'maximum_occupancy' => max($slotOccupancies),
            'slot_occupancies' => $slotOccupancies,
        ];
    }

    /**
     * @param  Collection<int, object{course_id: int, curriculum_id: int}>  $coursePairs
     * @return array{0: Collection<int, int>, 1: Collection<int, int>}
     */
    private function seedStudentAccounts(Generator $faker, int $roleId, Collection $coursePairs, CarbonImmutable $now): array
    {
        $password = Hash::make('StressTest123!');
        $users = [];

        for ($sequence = 1; $sequence <= self::STUDENT_COUNT; $sequence++) {
            $users[] = [
                'username' => self::username($sequence),
                'password' => $password,
                'role_id' => $roleId,
                'status' => $sequence % 20 === 0 ? 'inactive' : 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertBatches('users', $users);

        $userIds = $this->stressUserQuery()->orderBy('username')->pluck('id')->map(fn ($id) => (int) $id)->values();
        $profiles = [];

        foreach ($userIds as $offset => $userId) {
            $sequence = $offset + 1;
            $gender = $sequence % 2 === 0 ? 'Female' : 'Male';
            $profiles[] = [
                'user_id' => $userId,
                'first_name' => $faker->firstName($gender === 'Male' ? 'male' : 'female'),
                'middle_name' => $sequence % 4 === 0 ? null : $faker->lastName(),
                'last_name' => $faker->lastName(),
                'suffix' => null,
                'gender' => $gender,
                'birth_date' => $faker->dateTimeBetween('-30 years', '-16 years')->format('Y-m-d'),
                'civil_status' => 'Single',
                'email' => sprintf('stress%05d@example.test', $sequence),
                'contact_number' => '09'.str_pad((string) (700_000_000 + $sequence), 9, '0', STR_PAD_LEFT),
                'address' => str_replace("\n", ', ', $faker->address()),
                'profile_photo' => $faker->randomElement(self::PROFILE_PHOTO_PATHS),
                'nationality' => 'Filipino',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertBatches('user_profiles', $profiles);

        $profileIdsByUser = DB::table('user_profiles')
            ->join('users', 'users.id', '=', 'user_profiles.user_id')
            ->where('users.username', 'like', self::USERNAME_LIKE_PATTERN)
            ->pluck('user_profiles.id', 'user_profiles.user_id');
        $students = [];

        foreach ($userIds as $offset => $userId) {
            $sequence = $offset + 1;
            $coursePair = $coursePairs[$offset % $coursePairs->count()];
            $students[] = [
                'user_id' => $userId,
                'user_profile_id' => $profileIdsByUser[$userId],
                'course_id' => $coursePair->course_id,
                'curriculum_id' => $coursePair->curriculum_id,
                'student_number' => self::studentNumber($sequence),
                'admission_date' => $now->subDays(30 + (($sequence * 13) % 1_400))->toDateString(),
                'year_level' => 1 + ($sequence % 4),
                'student_status' => $this->studentStatus($sequence),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertBatches('students', $students);

        $studentIds = DB::table('students')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->where('users.username', 'like', self::USERNAME_LIKE_PATTERN)
            ->orderBy('users.username')
            ->pluck('students.id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $this->command?->info('Generated 5,000 users, profiles, and students.');

        return [$userIds, $studentIds];
    }

    /** @param Collection<int, int> $studentIds */
    private function seedPhysicalRecordLocations(Collection $studentIds, CarbonImmutable $now): void
    {
        $plan = self::physicalRecordStressPlan();
        $cabinetCodes = collect($plan['cabinet_codes']);
        $cabinets = $cabinetCodes->map(fn (string $cabinetCode): array => [
            'cabinet_code' => $cabinetCode,
            'description' => 'Student paper records storage. '.self::PHYSICAL_RECORD_CABINET_MARKER.'.',
            'rows' => self::STRESS_CABINET_ROWS,
            'columns' => self::STRESS_CABINET_COLUMNS,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $this->insertBatches('cabinets', $cabinets);

        $cabinetIdsByCode = DB::table('cabinets')
            ->whereIn('cabinet_code', $cabinetCodes->all())
            ->pluck('id', 'cabinet_code');

        if ($cabinetIdsByCode->count() !== self::STRESS_CABINET_COUNT) {
            throw new LogicException('Unable to resolve all dedicated stress cabinets after insertion.');
        }

        $slots = [];
        $slotsPerCabinet = self::STRESS_CABINET_ROWS * self::STRESS_CABINET_COLUMNS;

        foreach ($cabinetCodes as $cabinetCode) {
            for ($position = 1; $position <= $slotsPerCabinet; $position++) {
                $slots[] = [
                    'cabinet_id' => $cabinetIdsByCode[$cabinetCode],
                    'slot_code' => $cabinetCode.$position,
                    'capacity' => self::STRESS_SLOT_CAPACITY,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->insertBatches('cabinet_slots', $slots);

        $slotIds = DB::table('cabinet_slots')
            ->join('cabinets', 'cabinets.id', '=', 'cabinet_slots.cabinet_id')
            ->whereIn('cabinets.cabinet_code', $cabinetCodes->all())
            ->orderBy('cabinets.cabinet_code')
            ->orderBy('cabinet_slots.id')
            ->pluck('cabinet_slots.id')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $expectedSlotCount = $plan['slot_count'];

        if ($slotIds->count() !== $expectedSlotCount) {
            throw new LogicException(sprintf(
                'Expected %d stress cabinet slots, but resolved %d.',
                $expectedSlotCount,
                $slotIds->count(),
            ));
        }

        if ($studentIds->count() !== $plan['student_count']) {
            throw new LogicException(sprintf(
                'Expected %d stress students for cabinet assignment, but received %d.',
                $plan['student_count'],
                $studentIds->count(),
            ));
        }

        $studentRandomizer = new Randomizer(new Mt19937(self::STRESS_STUDENT_SHUFFLE_SEED));
        $shuffledStudentIds = $studentRandomizer->shuffleArray($studentIds->all());
        $locations = [];
        $studentOffset = 0;

        foreach ($slotIds as $slotOffset => $slotId) {
            $occupancy = $plan['slot_occupancies'][$slotOffset];

            for ($position = 0; $position < $occupancy; $position++) {
                $locations[] = [
                    'student_id' => $shuffledStudentIds[$studentOffset++],
                    'cabinet_slot_id' => $slotId,
                    'assigned_at' => $now,
                    'remarks' => 'LargeDatasetSeeder physical record location.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($studentOffset !== $studentIds->count()) {
            throw new LogicException('Stress cabinet occupancy plan did not consume every generated student exactly once.');
        }

        $this->insertBatches('student_record_locations', $locations);

        $actualOccupancies = DB::table('cabinet_slots')
            ->join('cabinets', 'cabinets.id', '=', 'cabinet_slots.cabinet_id')
            ->leftJoin('student_record_locations', 'student_record_locations.cabinet_slot_id', '=', 'cabinet_slots.id')
            ->whereIn('cabinets.cabinet_code', $cabinetCodes->all())
            ->selectRaw('COUNT(student_record_locations.id) as occupancy')
            ->groupBy('cabinet_slots.id')
            ->get()
            ->pluck('occupancy')
            ->map(fn ($occupancy): int => (int) $occupancy)
            ->values();
        $actualStudentCount = $actualOccupancies->sum();
        $actualAverage = (float) $actualOccupancies->average();
        $actualMinimum = (int) $actualOccupancies->min();
        $actualMaximum = (int) $actualOccupancies->max();
        $expectedDistribution = collect($plan['slot_occupancies'])->countBy()->sortKeys()->all();
        $actualDistribution = $actualOccupancies->countBy()->sortKeys()->all();

        if (
            $actualOccupancies->count() !== $plan['slot_count']
            || $actualStudentCount !== $plan['student_count']
            || $actualMinimum !== $plan['minimum_occupancy']
            || $actualMaximum !== $plan['maximum_occupancy']
            || $actualMaximum > $plan['slot_capacity']
            || $actualDistribution !== $expectedDistribution
        ) {
            throw new LogicException(sprintf(
                'Stress cabinet verification failed: %d slots, %d students, %.2f average, %d minimum, %d maximum.',
                $actualOccupancies->count(),
                $actualStudentCount,
                $actualAverage,
                $actualMinimum,
                $actualMaximum,
            ));
        }

        $this->command?->info(sprintf(
            'Generated %s stress cabinets, %s slots, and %s student record locations (%.2f average, %d minimum, %d maximum per slot).',
            number_format(self::STRESS_CABINET_COUNT),
            number_format($expectedSlotCount),
            number_format($studentIds->count()),
            $actualAverage,
            $actualMinimum,
            $actualMaximum,
        ));
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @param  Collection<string, object>  $documentTypes
     */
    private function seedStudentDocuments(Collection $studentIds, Collection $documentTypes, CarbonImmutable $now): void
    {
        $types = collect(self::DOCUMENT_NAMES)->map(fn (string $name) => $documentTypes[$name])->values();
        $rows = [];

        foreach ($studentIds as $offset => $studentId) {
            $documentCount = $offset < 500 ? 5 : ($offset < 4_000 ? 3 : 2);

            for ($documentOffset = 0; $documentOffset < $documentCount; $documentOffset++) {
                $documentType = $types[($offset + $documentOffset) % $types->count()];
                $available = $offset < 250 || (($offset + $documentOffset) % 4 !== 0);
                $rows[] = [
                    'student_id' => $studentId,
                    'document_type_id' => $documentType->id,
                    'document_storage_location_id' => null,
                    'file_path' => null,
                    'verification_status' => $available ? 'verified' : 'pending',
                    'availability_status' => $available ? 'available' : 'missing',
                    'remarks' => $available ? 'Stress data: document on file.' : 'Stress data: document missing.',
                    'submitted_date' => $available ? $now->subDays(($offset * 7 + $documentOffset) % 1_000)->toDateString() : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) >= self::BATCH_SIZE) {
                    DB::table('student_documents')->insert($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table('student_documents')->insert($rows);
        }

        $this->command?->info('Generated 15,000 student document records.');
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @param  Collection<int, object>  $activeDocumentTypes
     * @param  Collection<int, object>  $appointmentDocumentTypes
     * @param  Collection<int, int>  $registrarIds
     */
    private function seedDocumentRequests(
        Collection $studentIds,
        Collection $activeDocumentTypes,
        Collection $appointmentDocumentTypes,
        Collection $registrarIds,
        CarbonImmutable $now,
    ): void {
        $requestsPerStudent = intdiv(self::DOCUMENT_REQUEST_COUNT, self::STUDENT_COUNT);
        $appointmentRequestsPerStudent = intdiv(self::APPOINTMENT_COUNT, self::STUDENT_COUNT);

        if ($requestsPerStudent * self::STUDENT_COUNT !== self::DOCUMENT_REQUEST_COUNT) {
            throw new LogicException('Document request count must be evenly distributed across stress students.');
        }
        if ($appointmentRequestsPerStudent * self::STUDENT_COUNT !== self::APPOINTMENT_COUNT) {
            throw new LogicException('Appointment count must be evenly distributed across stress students.');
        }
        if ($appointmentRequestsPerStudent > $requestsPerStudent) {
            throw new LogicException('Appointment target cannot exceed the generated document request capacity.');
        }

        $purposes = ['Employment', 'Scholarship', 'Board examination', 'Transfer', 'Personal record'];
        $rows = [];

        foreach ($studentIds as $studentOffset => $studentId) {
            foreach (range(0, $requestsPerStudent - 1) as $requestOffset) {
                if ($requestOffset < $appointmentRequestsPerStudent) {
                    $typeOffset = (($studentOffset * $appointmentRequestsPerStudent) + $requestOffset)
                        % $appointmentDocumentTypes->count();
                    $documentType = $appointmentDocumentTypes[$typeOffset];
                } else {
                    $remainingOffset = $requestOffset - $appointmentRequestsPerStudent;
                    $typeOffset = (($studentOffset * ($requestsPerStudent - $appointmentRequestsPerStudent)) + $remainingOffset)
                        % $activeDocumentTypes->count();
                    $documentType = $activeDocumentTypes[$typeOffset];
                }

                $sequence = ($studentOffset * $requestsPerStudent) + $requestOffset;
                $status = $this->requestStatus($sequence);
                $quantity = 1 + ($sequence % 3);
                $ageDays = $status === 'pending' ? $sequence % 30 : 7 + (($sequence * 17) % 720);
                $requestAt = $now->subDays($ageDays)->startOfDay()->addHours(9);
                $approvedAt = in_array($status, ['processing', 'ready_for_release', 'released'], true) ? $requestAt->addDay() : null;
                $processedAt = in_array($status, ['ready_for_release', 'released'], true) ? $requestAt->addDays(2) : null;
                $readyAt = in_array($status, ['ready_for_release', 'released'], true) ? $requestAt->addDays(3) : null;
                $releasedAt = $status === 'released' ? $requestAt->addDays(4) : null;
                $rejectedAt = $status === 'rejected' ? $requestAt->addDay() : null;
                $handled = $status !== 'pending';

                $rows[] = [
                    'student_id' => $studentId,
                    'document_type_id' => $documentType->id,
                    'registrar_staff_id' => $handled ? $registrarIds[$sequence % $registrarIds->count()] : null,
                    'quantity' => $quantity,
                    'total_fee' => (float) $documentType->processing_fee * $quantity,
                    'purpose' => $purposes[$sequence % count($purposes)],
                    'status' => $status,
                    'request_date' => $requestAt->toDateString(),
                    'release_date' => $releasedAt?->toDateString(),
                    'remarks' => $sequence % 10 === 0 ? 'Stress-test request record.' : null,
                    'approved_at' => $approvedAt,
                    'processed_at' => $processedAt,
                    'ready_for_release_at' => $readyAt,
                    'released_at' => $releasedAt,
                    'rejected_at' => $rejectedAt,
                    'created_at' => $requestAt,
                    'updated_at' => $releasedAt ?? $rejectedAt ?? $readyAt ?? $processedAt ?? $approvedAt ?? $requestAt,
                ];

                if (count($rows) >= self::BATCH_SIZE) {
                    DB::table('document_requests')->insert($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table('document_requests')->insert($rows);
        }

        $this->command?->info('Generated 25,000 document requests with consistent workflow timestamps.');
    }

    /** @param Collection<int, int> $registrarIds */
    private function seedAppointments(Collection $registrarIds, CarbonImmutable $now): void
    {
        $stressRequestQuery = DB::table('document_requests')
            ->join('students', 'students.id', '=', 'document_requests.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->where('users.username', 'like', self::USERNAME_LIKE_PATTERN);

        $totalStressRequests = (clone $stressRequestQuery)->count();
        $eligibleRequests = (clone $stressRequestQuery)
            ->join('document_types', 'document_types.id', '=', 'document_requests.document_type_id')
            ->where('document_types.requires_appointment', true)
            ->orderBy('document_requests.student_id')
            ->orderBy('document_requests.id')
            ->get([
                'document_requests.id',
                'document_requests.student_id',
                'document_requests.status as request_status',
                'document_requests.request_date',
                'document_types.document_name',
            ]);

        $appointmentRequests = $eligibleRequests
            ->groupBy('student_id')
            ->flatMap(fn (Collection $requests): Collection => $requests->take(
                intdiv(self::APPOINTMENT_COUNT, self::STUDENT_COUNT)
            ))
            ->take(self::APPOINTMENT_COUNT)
            ->values();

        $eligibleStudentCount = $eligibleRequests->pluck('student_id')->unique()->count();
        $selectedStudentCount = $appointmentRequests->pluck('student_id')->unique()->count();

        $this->command?->info(sprintf(
            'Appointment candidate diagnostics: %s total stress requests, %s appointment-required requests, %s eligible students, %s selected candidates across %s students.',
            number_format($totalStressRequests),
            number_format($eligibleRequests->count()),
            number_format($eligibleStudentCount),
            number_format($appointmentRequests->count()),
            number_format($selectedStudentCount),
        ));

        if (
            $appointmentRequests->count() !== self::APPOINTMENT_COUNT
            || $selectedStudentCount !== self::STUDENT_COUNT
        ) {
            throw new LogicException(sprintf(
                'Unable to select 10,000 eligible stress document requests across 5,000 students (total requests: %d, appointment-required: %d, eligible students: %d, selected: %d, selected students: %d).',
                $totalStressRequests,
                $eligibleRequests->count(),
                $eligibleStudentCount,
                $appointmentRequests->count(),
                $selectedStudentCount,
            ));
        }

        $usedActiveSlotKeys = DB::table('appointments')
            ->whereNotNull('active_slot_key')
            ->pluck('active_slot_key')
            ->flip()
            ->all();
        $times = ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00'];
        $activeSlotSequence = 0;
        $rows = [];

        foreach ($appointmentRequests as $offset => $request) {
            $status = $this->appointmentStatus($request->request_status, $offset);
            $active = in_array($status, ['pending', 'confirmed'], true);
            $activeSlotKey = null;

            if ($active) {
                do {
                    $appointmentDate = $now->addDays(1 + intdiv($activeSlotSequence, count($times)));
                    $appointmentTime = $times[$activeSlotSequence % count($times)];
                    $activeSlotKey = $appointmentDate->toDateString().' '.$appointmentTime;
                    $activeSlotSequence++;
                } while (isset($usedActiveSlotKeys[$activeSlotKey]));

                $usedActiveSlotKeys[$activeSlotKey] = true;
            } else {
                $appointmentDate = CarbonImmutable::parse($request->request_date)->addDays(1 + ($offset % 14));
                $appointmentTime = $times[$offset % count($times)];
            }

            $createdAt = CarbonImmutable::parse($request->request_date)->startOfDay();
            $rows[] = [
                'student_id' => $request->student_id,
                'document_request_id' => $request->id,
                'registrar_staff_id' => $status === 'pending' ? null : $registrarIds[$offset % $registrarIds->count()],
                'appointment_date' => $appointmentDate->toDateString(),
                'appointment_time' => $appointmentTime,
                'active_slot_key' => $activeSlotKey,
                'purpose' => 'Collection of '.$request->document_name,
                'status' => $status,
                'remarks' => in_array($status, ['cancelled', 'no_show'], true) ? 'Stress-test historical appointment.' : null,
                'created_at' => $createdAt,
                'updated_at' => $active ? $now : $appointmentDate->startOfDay(),
            ];

            if (count($rows) >= self::BATCH_SIZE) {
                DB::table('appointments')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('appointments')->insert($rows);
        }

        $this->command?->info('Generated 10,000 appointments without active slot collisions.');
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function insertBatches(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
            DB::table($table)->insert($batch);
        }
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @param  Collection<int, int>  $studentIds
     */
    private function assertExpectedCounts(Collection $userIds, Collection $studentIds): void
    {
        $counts = [
            'users' => $userIds->count(),
            'profiles' => DB::table('user_profiles')
                ->join('users', 'users.id', '=', 'user_profiles.user_id')
                ->where('users.username', 'like', self::USERNAME_LIKE_PATTERN)
                ->count(),
            'students' => $studentIds->count(),
            'documents' => $this->countForStudents('student_documents', $studentIds),
            'requests' => $this->countForStudents('document_requests', $studentIds),
            'appointments' => $this->countForStudents('appointments', $studentIds),
            'record_locations' => $this->countForStudents('student_record_locations', $studentIds),
        ];
        $expected = [
            'users' => self::STUDENT_COUNT,
            'profiles' => self::STUDENT_COUNT,
            'students' => self::STUDENT_COUNT,
            'documents' => self::STUDENT_DOCUMENT_COUNT,
            'requests' => self::DOCUMENT_REQUEST_COUNT,
            'appointments' => self::APPOINTMENT_COUNT,
            'record_locations' => self::STUDENT_COUNT,
        ];

        if ($counts !== $expected) {
            throw new LogicException('Large dataset count verification failed: '.json_encode($counts, JSON_THROW_ON_ERROR));
        }
    }

    /** @param Collection<int, int> $studentIds */
    private function countForStudents(string $table, Collection $studentIds): int
    {
        $count = 0;

        foreach ($studentIds->chunk(self::BATCH_SIZE) as $studentIdChunk) {
            $count += DB::table($table)->whereIn('student_id', $studentIdChunk->all())->count();
        }

        return $count;
    }

    private function studentStatus(int $sequence): string
    {
        return match (true) {
            $sequence % 100 < 72 => 'regular',
            $sequence % 100 < 90 => 'irregular',
            $sequence % 100 < 94 => 'leave_of_absence',
            $sequence % 100 < 97 => 'graduated',
            $sequence % 100 < 99 => 'transferred',
            default => 'dropped',
        };
    }

    private function requestStatus(int $sequence): string
    {
        return match (true) {
            $sequence % 100 < 15 => 'pending',
            $sequence % 100 < 35 => 'processing',
            $sequence % 100 < 50 => 'ready_for_release',
            $sequence % 100 < 75 => 'released',
            $sequence % 100 < 90 => 'rejected',
            default => 'cancelled',
        };
    }

    private function appointmentStatus(string $requestStatus, int $sequence): string
    {
        return match ($requestStatus) {
            'pending' => 'pending',
            'processing', 'ready_for_release' => $sequence % 3 === 0 ? 'completed' : 'confirmed',
            'released' => 'completed',
            'rejected' => 'no_show',
            'cancelled' => 'cancelled',
            default => 'cancelled',
        };
    }

    private function stressUserQuery(): Builder
    {
        return DB::table('users')
            ->where('username', 'like', self::USERNAME_LIKE_PATTERN);
    }

    private static function username(int $sequence): string
    {
        return self::USERNAME_PREFIX.sprintf('%05d', $sequence);
    }

    private static function studentNumber(int $sequence): string
    {
        return self::STUDENT_NUMBER_PREFIX.sprintf('%05d', $sequence);
    }
}
