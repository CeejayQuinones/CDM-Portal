<?php

namespace Database\Seeders;

use App\Models\Role;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class LargeDatasetSeeder extends Seeder
{
    public const DATASET_KEY = 'school-demo-v2';

    public const LEGACY_DATASET_KEY = 'school-demo-v1';

    public const GENERATED_USER_RECORD_TYPE = 'user';

    public const GENERATED_CABINET_RECORD_TYPE = 'cabinet';

    public const GENERATED_CAPACITY_RECORD_TYPE = 'appointment_capacity';

    public const PHYSICAL_RECORD_CABINET_CODES = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];

    public const STUDENT_COUNT = 10_000;

    public const STUDENT_DOCUMENT_COUNT = 30_000;

    public const DOCUMENT_REQUEST_COUNT = 50_000;

    public const APPOINTMENT_COUNT = 20_000;

    public const PENDING_REQUEST_COUNT = 20_000;

    public const APPROVED_REQUEST_COUNT = 400;

    public const COMPLETED_REQUEST_COUNT = 10_000;

    public const REJECTED_REQUEST_COUNT = 10_000;

    public const CANCELLED_REQUEST_COUNT = 9_600;

    public const PENDING_APPOINTMENT_COUNT = 600;

    public const CONFIRMED_APPOINTMENT_COUNT = 400;

    public const COMPLETED_APPOINTMENT_COUNT = 10_000;

    public const CANCELLED_APPOINTMENT_COUNT = 9_000;

    private const BATCH_SIZE = 500;

    private const STRESS_CABINET_COUNT = 8;

    private const STRESS_CABINET_ROWS = 10;

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

    private const MALE_FIRST_NAMES = [
        'Juan', 'Jose', 'Carlo', 'Mark', 'Paolo', 'Miguel', 'Angelo', 'Christian', 'Gabriel', 'Rafael',
        'Daniel', 'Joshua', 'Adrian', 'Vincent', 'Francis', 'Luis', 'Nathaniel', 'Emmanuel', 'Jericho', 'Ramon',
    ];

    private const FEMALE_FIRST_NAMES = [
        'Maria', 'Angela', 'Camille', 'Patricia', 'Anne', 'Sofia', 'Bianca', 'Jasmine', 'Alyssa', 'Nicole',
        'Gabriela', 'Andrea', 'Katrina', 'Mikaela', 'Isabel', 'Clarisse', 'Daniela', 'Regina', 'Liza', 'Maricel',
    ];

    private const FILIPINO_SURNAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Ramos', 'Flores', 'Aquino', 'Castillo',
        'Navarro', 'Villanueva', 'Gonzales', 'Torres', 'Fernandez', 'Mercado', 'Castro', 'Rivera', 'Pascual',
        'Santiago', 'Valdez', 'Soriano', 'Agustin', 'Evangelista', 'Salazar', 'Manalo', 'Macapagal', 'Tolentino',
        'De Guzman', 'Lim', 'Tan',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('LargeDatasetSeeder is disabled in the production environment.');
        }

        if (
            ! Schema::hasColumn('student_documents', 'availability_status')
            || ! Schema::hasColumns('document_requests', ['approved_at', 'completed_at', 'rejected_at', 'cancelled_at', 'verification_code_lookup', 'verification_code_hash', 'code_verified_at'])
            || ! Schema::hasColumn('appointments', 'active_slot_key')
            || ! Schema::hasColumns('cabinets', ['cabinet_code', 'rows', 'columns'])
            || ! Schema::hasColumns('cabinet_slots', ['cabinet_id', 'slot_code', 'capacity'])
            || ! Schema::hasColumns('student_record_locations', ['student_id', 'cabinet_slot_id', 'assigned_at'])
            || ! Schema::hasTable('generated_data_records')
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

        $missingDocumentTypes = collect(self::DOCUMENT_NAMES)->diff($studentDocumentTypes->keys());
        if ($missingDocumentTypes->isNotEmpty()) {
            throw new LogicException('Missing existing document types: '.$missingDocumentTypes->join(', ').'. Run DocumentTypesSeeder first.');
        }

        $startedAt = microtime(true);
        $this->command?->warn('Rebuilding only records identified by the internal generated-data registry.');
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
            $registrarIds,
            $now,
        ));
        DB::transaction(fn () => $this->seedAppointments($studentIds, $registrarIds, $now));
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
            ...array_fill(0, 20, 5),
            ...array_fill(0, 60, 13),
            ...array_fill(0, 10, 18),
            ...array_fill(0, 10, 19),
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
        $password = Hash::make('CdmStudent123!');
        $identities = $this->studentIdentities($faker);
        $users = [];

        foreach ($identities as $identity) {
            $users[] = [
                'username' => $identity['username'],
                'password' => $password,
                'role_id' => $roleId,
                'status' => $identity['sequence'] % 20 === 0 ? 'inactive' : 'active',
                'last_login' => null,
                'is_first_login' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertBatches('users', $users);

        $userIdsByUsername = collect();
        foreach (array_chunk(array_column($identities, 'username'), self::BATCH_SIZE) as $usernameChunk) {
            $userIdsByUsername = $userIdsByUsername->merge(
                DB::table('users')->whereIn('username', $usernameChunk)->pluck('id', 'username')
            );
        }
        $userIds = collect($identities)
            ->map(fn (array $identity): int => (int) $userIdsByUsername[$identity['username']])
            ->values();
        $this->registerGeneratedRecords(self::GENERATED_USER_RECORD_TYPE, $userIds, $now);
        $profiles = [];

        foreach ($userIds as $offset => $userId) {
            $identity = $identities[$offset];
            $profiles[] = [
                'user_id' => $userId,
                'first_name' => $identity['first_name'],
                'middle_name' => $identity['middle_name'],
                'last_name' => $identity['last_name'],
                'suffix' => null,
                'gender' => $identity['gender'],
                'birth_date' => $identity['birth_date'],
                'civil_status' => 'Single',
                'email' => $identity['email'],
                'contact_number' => $identity['contact_number'],
                'address' => $identity['address'],
                'profile_photo' => $identity['profile_photo'],
                'nationality' => 'Filipino',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertBatches('user_profiles', $profiles);

        $profileIdsByUser = collect();
        foreach ($userIds->chunk(self::BATCH_SIZE) as $userIdChunk) {
            $profileIdsByUser = $profileIdsByUser->union(
                DB::table('user_profiles')->whereIn('user_id', $userIdChunk->all())->pluck('id', 'user_id')
            );
        }
        $students = [];

        foreach ($userIds as $offset => $userId) {
            $identity = $identities[$offset];
            $sequence = $identity['sequence'];
            $coursePair = $coursePairs[$offset % $coursePairs->count()];
            $students[] = [
                'user_id' => $userId,
                'user_profile_id' => $profileIdsByUser[$userId],
                'course_id' => $coursePair->course_id,
                'curriculum_id' => $coursePair->curriculum_id,
                'student_number' => $identity['student_number'],
                'admission_date' => '20'.$identity['batch'].'-08-01',
                'year_level' => $identity['year_level'],
                'student_status' => $identity['batch'] === '22' ? 'irregular' : $this->studentStatus($sequence),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertBatches('students', $students);

        $studentIds = collect();
        foreach ($userIds->chunk(self::BATCH_SIZE) as $userIdChunk) {
            $studentIds = $studentIds->merge(
                DB::table('students')
                    ->whereIn('user_id', $userIdChunk->all())
                    ->orderBy('id')
                    ->pluck('id')
            );
        }
        $studentIds = $studentIds->map(fn ($id): int => (int) $id)->values();

        $this->command?->info('Generated 10,000 users, profiles, and students.');

        return [$userIds, $studentIds];
    }

    /** @param Collection<int, int> $studentIds */
    private function seedPhysicalRecordLocations(Collection $studentIds, CarbonImmutable $now): void
    {
        $plan = self::physicalRecordStressPlan();
        $cabinetCodes = collect($plan['cabinet_codes']);
        $cabinetDescriptions = [
            'Student paper records storage',
            'Registrar physical records cabinet',
            'Archived student records',
        ];
        $cabinets = $cabinetCodes->map(fn (string $cabinetCode, int $offset): array => [
            'cabinet_code' => $cabinetCode,
            'description' => $cabinetDescriptions[$offset % count($cabinetDescriptions)],
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
            throw new LogicException('Unable to resolve all dedicated generated cabinets after insertion.');
        }
        $this->registerGeneratedRecords(
            self::GENERATED_CABINET_RECORD_TYPE,
            $cabinetIdsByCode->values()->map(fn ($id): int => (int) $id),
            $now,
        );

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
                'Expected %d generated cabinet slots, but resolved %d.',
                $expectedSlotCount,
                $slotIds->count(),
            ));
        }

        if ($studentIds->count() !== $plan['student_count']) {
            throw new LogicException(sprintf(
                'Expected %d generated students for cabinet assignment, but received %d.',
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
                    'remarks' => $position % 3 === 0
                        ? 'Filed with complete student records.'
                        : 'Assigned to registrar records storage.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($studentOffset !== $studentIds->count()) {
            throw new LogicException('Cabinet occupancy plan did not consume every generated student exactly once.');
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
                'Generated cabinet verification failed: %d slots, %d students, %.2f average, %d minimum, %d maximum.',
                $actualOccupancies->count(),
                $actualStudentCount,
                $actualAverage,
                $actualMinimum,
                $actualMaximum,
            ));
        }

        $this->command?->info(sprintf(
            'Generated %s cabinets, %s slots, and %s student record locations (%.2f average, %d minimum, %d maximum per slot).',
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
            $documentCount = 3;

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
                    'remarks' => $available ? 'Verified student record.' : 'Awaiting supporting document.',
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

        $this->command?->info('Generated 30,000 student document records.');
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @param  Collection<int, object>  $activeDocumentTypes
     * @param  Collection<int, int>  $registrarIds
     */
    private function seedDocumentRequests(
        Collection $studentIds,
        Collection $activeDocumentTypes,
        Collection $registrarIds,
        CarbonImmutable $now,
    ): void {
        $purposes = [
            'Scholarship requirement',
            'Employment requirement',
            'Transfer requirement',
            'Personal records',
            'Board examination requirement',
            'Government requirement',
            'School requirement',
        ];
        $rows = [];

        foreach ($studentIds as $studentOffset => $studentId) {
            foreach (range(0, 4) as $requestOffset) {
                $sequence = ($studentOffset * 5) + $requestOffset;
                $status = $this->requestStatus($sequence);
                $documentType = $activeDocumentTypes[$sequence % $activeDocumentTypes->count()];
                $quantity = 1 + ($sequence % 3);
                $ageDays = $status === 'pending' ? $sequence % 30 : 14 + (($sequence * 17) % 720);
                $requestAt = $now->subDays($ageDays)->startOfDay()->addHours(9);
                $approvedAt = in_array($status, ['approved', 'completed'], true) ? $requestAt->addDay() : null;
                $completedAt = $status === 'completed' ? $requestAt->addDays(4) : null;
                $rejectedAt = $status === 'rejected' ? $requestAt->addDay() : null;
                $cancelledAt = $status === 'cancelled' ? $requestAt->addDays(2) : null;
                $handled = $status !== 'pending';
                $claimCode = $status === 'approved' ? sprintf('%06d', 100000 + ($sequence % 800000)) : null;

                $rows[] = [
                    'student_id' => $studentId,
                    'document_type_id' => $documentType->id,
                    'registrar_staff_id' => $handled ? $registrarIds[$sequence % $registrarIds->count()] : null,
                    'quantity' => $quantity,
                    'total_fee' => (float) $documentType->processing_fee * $quantity,
                    'purpose' => $purposes[$sequence % count($purposes)],
                    'status' => $status,
                    'request_date' => $requestAt->toDateString(),
                    'release_date' => $completedAt?->toDateString(),
                    'remarks' => match ($status) {
                        'pending' => 'Awaiting registrar review.',
                        'rejected' => 'Awaiting supporting document.',
                        'cancelled' => $sequence % 3 === 0 ? 'Cancelled after a missed collection date.' : 'Request cancelled by registrar.',
                        default => null,
                    },
                    'approved_at' => $approvedAt,
                    'completed_at' => $completedAt,
                    'rejected_at' => $rejectedAt,
                    'cancelled_at' => $cancelledAt,
                    'verification_code_lookup' => $claimCode ? hash_hmac('sha256', $claimCode, (string) config('app.key')) : null,
                    'verification_code_hash' => $claimCode ? Hash::make($claimCode) : null,
                    'code_verified_at' => $status === 'completed' ? $requestAt->addDays(3) : null,
                    'created_at' => $requestAt,
                    'updated_at' => $completedAt ?? $cancelledAt ?? $rejectedAt ?? $approvedAt ?? $requestAt,
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

        $this->command?->info('Generated 50,000 document requests with current workflow statuses.');
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @param  Collection<int, int>  $registrarIds
     */
    private function seedAppointments(Collection $studentIds, Collection $registrarIds, CarbonImmutable $now): void
    {
        $requestIds = collect();
        foreach ($studentIds->chunk(self::BATCH_SIZE) as $chunk) {
            $requestIds = $requestIds->merge(DB::table('document_requests')->whereIn('student_id', $chunk->all())->orderBy('id')->pluck('id'));
        }
        $requests = collect();
        foreach ($requestIds->chunk(self::BATCH_SIZE) as $chunk) {
            $requests = $requests->merge(DB::table('document_requests')->whereIn('id', $chunk->all())->orderBy('id')->get(['id', 'student_id', 'status', 'request_date']));
        }
        $activeDates = $this->validActiveDates(self::PENDING_APPOINTMENT_COUNT + self::CONFIRMED_APPOINTMENT_COUNT, $now);
        $confirmedDates = array_slice($activeDates, 0, self::CONFIRMED_APPOINTMENT_COUNT);
        $pendingDates = array_slice($activeDates, self::CONFIRMED_APPOINTMENT_COUNT);
        $confirmedOffset = 0;
        $pendingOffset = 0;
        $rows = [];
        foreach ($requests as $offset => $request) {
            $status = $this->appointmentStatus($request->status, $offset);
            if (! $status) {
                continue;
            }
            $active = in_array($status, ['pending', 'confirmed'], true);
            $appointmentDate = $active
                ? ($status === 'confirmed' ? $confirmedDates[$confirmedOffset++] : $pendingDates[$pendingOffset++])
                : CarbonImmutable::parse($request->request_date)->addDays(2 + ($offset % 12));
            $createdAt = CarbonImmutable::parse($request->request_date)->startOfDay();
            $rows[] = [
                'student_id' => $request->student_id,
                'document_request_id' => $request->id,
                'registrar_staff_id' => $registrarIds[$offset % $registrarIds->count()],
                'appointment_date' => $appointmentDate->toDateString(),
                'appointment_time' => '00:00:00',
                'active_slot_key' => null,
                'purpose' => 'Document request collection.',
                'status' => $status,
                'remarks' => match ($status) {
                    'completed' => 'Document collected by student.',
                    'cancelled' => 'Appointment cancelled.',
                    default => null,
                },
                'created_at' => $createdAt,
                'completed_at' => $status === 'completed' ? $appointmentDate->startOfDay()->addHours(2) : null,
                'cancelled_at' => $status === 'cancelled' ? $appointmentDate->startOfDay()->addHours(1) : null,
                'updated_at' => $active ? $now : $appointmentDate->startOfDay()->addHours(2),
            ];

            if (count($rows) >= self::BATCH_SIZE) {
                DB::table('appointments')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('appointments')->insert($rows);
        }

        $this->seedStatusChanges($requestIds, $registrarIds, $now);
        $this->command?->info('Generated 20,000 date-only appointments. Active queue date: '.$activeDates[0]->toDateString().'.');
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
            'profiles' => $this->countForUsers('user_profiles', $userIds),
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

    /** @param Collection<int, int> $userIds */
    private function countForUsers(string $table, Collection $userIds): int
    {
        $count = 0;

        foreach ($userIds->chunk(self::BATCH_SIZE) as $userIdChunk) {
            $count += DB::table($table)->whereIn('user_id', $userIdChunk->all())->count();
        }

        return $count;
    }

    /** @param Collection<int, int> $recordIds */
    private function registerGeneratedRecords(string $recordType, Collection $recordIds, CarbonImmutable $createdAt): void
    {
        $registeredAt = CarbonImmutable::now()->startOfSecond();
        $rows = $recordIds->map(fn (int $recordId): array => [
            'dataset_key' => self::DATASET_KEY,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'record_created_at' => $createdAt,
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
        ])->all();

        $this->insertBatches('generated_data_records', $rows);
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
            $sequence < self::PENDING_REQUEST_COUNT => 'pending',
            $sequence < self::PENDING_REQUEST_COUNT + self::APPROVED_REQUEST_COUNT => 'approved',
            $sequence < self::PENDING_REQUEST_COUNT + self::APPROVED_REQUEST_COUNT + self::COMPLETED_REQUEST_COUNT => 'completed',
            $sequence < self::PENDING_REQUEST_COUNT + self::APPROVED_REQUEST_COUNT + self::COMPLETED_REQUEST_COUNT + self::REJECTED_REQUEST_COUNT => 'rejected',
            default => 'cancelled',
        };
    }

    private function appointmentStatus(string $requestStatus, int $sequence): ?string
    {
        return match ($requestStatus) {
            'pending' => $sequence < self::PENDING_APPOINTMENT_COUNT ? 'pending' : null,
            'approved' => 'confirmed',
            'completed' => 'completed',
            'cancelled' => $sequence < self::PENDING_REQUEST_COUNT + self::APPROVED_REQUEST_COUNT + self::COMPLETED_REQUEST_COUNT + self::REJECTED_REQUEST_COUNT + self::CANCELLED_APPOINTMENT_COUNT ? 'cancelled' : null,
            default => null,
        };
    }

    /** @return array<int, CarbonImmutable> */
    private function validActiveDates(int $count, CarbonImmutable $now): array
    {
        $settings = DB::table('appointment_availability_settings')->first();
        $blocked = DB::table('appointment_blocked_dates')->where('is_active', true)->pluck('blocked_date')->flip()->all();
        $dates = [];
        $date = $now->setTimezone('Asia/Manila')->startOfDay();
        while (count($dates) < $count) {
            $weekend = ($date->isSaturday() && ($settings->block_saturday ?? true)) || ($date->isSunday() && ($settings->block_sunday ?? true));
            if (! $weekend && ! isset($blocked[$date->toDateString()])) {
                for ($slot = 0; $slot < 5 && count($dates) < $count; $slot++) {
                    $dates[] = $date;
                }
            }
            $date = $date->addDay();
        }

        return $dates;
    }

    /** @param Collection<int, int> $requestIds @param Collection<int, int> $registrarIds */
    private function seedStatusChanges(Collection $requestIds, Collection $registrarIds, CarbonImmutable $now): void
    {
        $requests = collect();
        foreach ($requestIds->chunk(self::BATCH_SIZE) as $chunk) {
            $requests = $requests->merge(DB::table('document_requests')->whereIn('id', $chunk->all())->orderBy('id')->get(['id', 'status', 'created_at', 'approved_at', 'completed_at', 'rejected_at', 'cancelled_at']));
        }
        $rows = [];
        foreach ($requests as $offset => $request) {
            $staff = $registrarIds[$offset % $registrarIds->count()];
            $add = function (string $from, string $to, string $action, $at, string $actor = 'registrar') use (&$rows, $request, $staff): void {
                $rows[] = ['document_request_id' => $request->id, 'registrar_staff_id' => $actor === 'system' ? null : $staff, 'actor_type' => $actor, 'from_status' => $from, 'to_status' => $to, 'action' => $action, 'reason' => null, 'created_at' => $at, 'updated_at' => $at];
            };
            if ($request->status === 'approved') {
                $add('pending', 'approved', 'approved', $request->approved_at);
            }
            if ($request->status === 'completed') {
                $add('pending', 'approved', 'approved', $request->approved_at);
                $add('approved', 'completed', 'completed', $request->completed_at);
            }
            if ($request->status === 'rejected') {
                $add('pending', 'rejected', 'rejected', $request->rejected_at);
            }
            if ($request->status === 'cancelled') {
                $add('pending', 'cancelled', 'cancelled', $request->cancelled_at, $offset % 3 === 0 ? 'system' : 'registrar');
            }
            if (count($rows) >= self::BATCH_SIZE) {
                DB::table('document_request_status_changes')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('document_request_status_changes')->insert($rows);
        }
    }

    /**
     * @return array<int, array{
     *     sequence: int,
     *     batch: string,
     *     year_level: int,
     *     student_number: string,
     *     username: string,
     *     email: string,
     *     first_name: string,
     *     middle_name: string|null,
     *     last_name: string,
     *     gender: string,
     *     birth_date: string,
     *     contact_number: string,
     *     address: string,
     *     profile_photo: string
     * }>
     */
    private function studentIdentities(Generator $faker): array
    {
        $batchSequences = ['22' => 0, '23' => 0, '24' => 0, '25' => 0, '26' => 0];
        $batchForYearLevel = [1 => '26', 2 => '25', 3 => '24', 4 => '23'];
        $usedStudentNumbers = array_fill_keys(DB::table('students')->whereNotNull('student_number')->pluck('student_number')->all(), true);
        $usedUsernames = array_fill_keys(
            DB::table('users')->pluck('username')->map(fn (string $username): string => strtolower($username))->all(),
            true,
        );
        $usedEmails = array_fill_keys(
            DB::table('user_profiles')->whereNotNull('email')->pluck('email')->map(fn (string $email): string => strtolower($email))->all(),
            true,
        );
        $streets = ['Mabini Street', 'Rizal Street', 'Bonifacio Street', 'J. P. Laurel Street', 'Sampaguita Street'];
        $barangays = ['San Jose', 'San Rafael', 'Manggahan', 'Burgos', 'Balite'];
        $municipalities = ['Rodriguez, Rizal', 'San Mateo, Rizal', 'Marikina City', 'Quezon City'];
        $identities = [];

        for ($sequence = 1; $sequence <= self::STUDENT_COUNT; $sequence++) {
            $yearLevel = 1 + (($sequence - 1) % 4);
            $batch = $sequence % 20 === 0 ? '22' : $batchForYearLevel[$yearLevel];
            $gender = $sequence % 2 === 0 ? 'Female' : 'Male';
            $firstNames = $gender === 'Male' ? self::MALE_FIRST_NAMES : self::FEMALE_FIRST_NAMES;
            $firstName = $firstNames[($sequence * 7) % count($firstNames)];
            $lastName = self::FILIPINO_SURNAMES[(($sequence * 11) + intdiv($sequence, count($firstNames))) % count(self::FILIPINO_SURNAMES)];
            $middleName = $sequence % 4 === 0
                ? null
                : self::FILIPINO_SURNAMES[(($sequence * 13) + 5) % count(self::FILIPINO_SURNAMES)];
            $identityBase = Str::of($firstName.'.'.$lastName)
                ->ascii()
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '.')
                ->trim('.')
                ->value() ?: 'student';
            $usernameBase = rtrim(substr($identityBase, 0, 44), '.');

            do {
                $batchSequence = ++$batchSequences[$batch];
                $studentNumber = $batch.'-'.sprintf('%05d', $batchSequence);
            } while (isset($usedStudentNumbers[$studentNumber]));

            $usernameSequence = $batchSequence;
            do {
                $username = $usernameBase.str_pad((string) $usernameSequence, 2, '0', STR_PAD_LEFT);
                $usernameSequence++;
            } while (isset($usedUsernames[$username]));

            $emailSequence = 1;
            do {
                $email = $identityBase.($emailSequence === 1 ? '' : $emailSequence).'@cdm.edu.ph';
                $emailSequence++;
            } while (isset($usedEmails[$email]));

            $usedStudentNumbers[$studentNumber] = true;
            $usedUsernames[$username] = true;
            $usedEmails[$email] = true;

            $identities[] = [
                'sequence' => $sequence,
                'batch' => $batch,
                'year_level' => $yearLevel,
                'student_number' => $studentNumber,
                'username' => $username,
                'email' => $email,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'gender' => $gender,
                'birth_date' => $faker->dateTimeBetween('-24 years', '-17 years')->format('Y-m-d'),
                'contact_number' => '0917'.str_pad((string) (1_000_000 + $sequence), 7, '0', STR_PAD_LEFT),
                'address' => sprintf(
                    '%d %s, Barangay %s, %s',
                    1 + ($sequence % 240),
                    $streets[$sequence % count($streets)],
                    $barangays[$sequence % count($barangays)],
                    $municipalities[$sequence % count($municipalities)],
                ),
                'profile_photo' => $faker->randomElement(self::PROFILE_PHOTO_PATHS),
            ];
        }

        return $identities;
    }
}
