<?php

namespace App\Services\StudentRecords;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentRosterImportPreview;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentRosterImportService
{
    public const HEADERS = [
        'student_number', 'first_name', 'middle_name', 'last_name', 'suffix',
        'enrollment_email', 'course_code', 'curriculum_code', 'year_level',
        'student_status', 'admission_date', 'gender', 'birth_date',
        'contact_number', 'address', 'record_reference',
    ];

    public function __construct(private readonly StudentRecordAuditWriter $audit) {}

    public function preview(UploadedFile $file, User $actor): array
    {
        StudentRosterImportPreview::query()->where('expires_at', '<', now())->delete();
        $rows = $this->readCsv($file);
        $results = $this->inspect($rows);
        $valid = count($rows) > 0 && collect($results)->every(fn ($item) => $item['errors'] === []);
        $token = null;
        if ($valid) {
            $token = Str::random(64);
            StudentRosterImportPreview::query()->create([
                'token_hash' => hash('sha256', $token),
                'actor_user_id' => $actor->id,
                'rows' => $rows,
                'expires_at' => now()->addMinutes(15),
            ]);
        }

        return [
            'rows' => $results,
            'counts' => ['total' => count($rows), 'valid' => $valid ? count($rows) : collect($results)->filter(fn ($item) => $item['errors'] === [])->count(), 'invalid' => collect($results)->filter(fn ($item) => $item['errors'] !== [])->count()],
            'preview_token' => $token,
            'message' => $valid ? 'Review every record before confirming. No accounts are active or prepared for activation.' : 'Correct the listed rows and preview again. Nothing was saved.',
        ];
    }

    public function confirm(string $token, User $actor): array
    {
        return DB::transaction(function () use ($token, $actor): array {
            $preview = StudentRosterImportPreview::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($preview && $preview->actor_user_id === $actor->id && $preview->expires_at->isFuture(), 409, 'Preview expired or already used. Upload the file again.');
            $results = $this->inspect($preview->rows);
            if (collect($results)->contains(fn ($item) => $item['errors'] !== [])) {
                throw ValidationException::withMessages(['file' => ['Roster data changed since preview. Upload and preview again.']]);
            }
            $roleId = Role::query()->where('role_name', Role::STUDENT)->value('id');
            abort_unless($roleId, 409, 'Student role is not configured.');
            foreach ($preview->rows as $row) {
                $user = User::query()->create([
                    'username' => 'unclaimed-'.Str::uuid(),
                    'password' => Str::random(64),
                    'role_id' => $roleId,
                    'status' => 'inactive',
                    'is_first_login' => true,
                ]);
                $profile = UserProfile::query()->create([
                    'user_id' => $user->id,
                    'first_name' => $row['first_name'],
                    'middle_name' => $row['middle_name'] ?: null,
                    'last_name' => $row['last_name'],
                    'suffix' => $row['suffix'] ?: null,
                    'email' => $row['enrollment_email'] ?: null,
                    'gender' => $row['gender'],
                    'birth_date' => $row['birth_date'] ?: null,
                    'contact_number' => $row['contact_number'] ?: null,
                    'address' => $row['address'] ?: null,
                ]);
                $course = Course::query()->where('course_code', $row['course_code'])->firstOrFail();
                $curriculum = Curriculum::query()->where('curriculum_code', $row['curriculum_code'])->where('course_id', $course->id)->firstOrFail();
                $student = Student::query()->create([
                    'user_id' => $user->id,
                    'user_profile_id' => $profile->id,
                    'student_number' => $row['student_number'],
                    'course_id' => $course->id,
                    'curriculum_id' => $curriculum->id,
                    'year_level' => (int) $row['year_level'],
                    'student_status' => $row['student_status'],
                    'admission_date' => $row['admission_date'],
                ]);
                $this->audit->record($actor, $student->id, 'student_roster_record_imported', [
                    'record_reference' => $row['record_reference'],
                    'student_number' => $row['student_number'],
                    'email_needs_registrar_review' => true,
                ]);
            }
            $preview->delete();

            return ['imported' => count($preview->rows), 'message' => 'Inactive student and alumni records imported. Review original enrollment emails before preparing activation.'];
        }, 3);
    }

    private function readCsv(UploadedFile $file): array
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => ['Cannot read this CSV file.']]);
        }
        try {
            $headers = fgetcsv($stream);
            if ($headers !== false && isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            }
            if ($headers === false || array_map(fn ($value) => strtolower(trim((string) $value)), $headers) !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => ['Use the supplied CSV template with its exact column order.']]);
            }
            $rows = [];
            while (($values = fgetcsv($stream)) !== false) {
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                if (count($values) !== count(self::HEADERS)) {
                    throw ValidationException::withMessages(['file' => ['A row does not match the template column count.']]);
                }
                $row = array_map(fn ($value) => trim((string) $value), array_combine(self::HEADERS, $values));
                $row['enrollment_email'] = mb_strtolower($row['enrollment_email']);
                $rows[] = $row;
                if (count($rows) > 1000) {
                    throw ValidationException::withMessages(['file' => ['Import at most 1,000 records per file.']]);
                }
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => ['The roster has no student rows.']]);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function inspect(array $rows): array
    {
        $numbers = [];
        $emails = [];
        $results = [];
        foreach ($rows as $index => $row) {
            $row['enrollment_email'] = mb_strtolower($row['enrollment_email']);
            $errors = collect(Validator::make($row, [
                'student_number' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'suffix' => ['nullable', 'string', 'max:20'],
                'enrollment_email' => ['nullable', 'email', 'max:255'],
                'course_code' => ['required', 'string', 'max:20'],
                'curriculum_code' => ['required', 'string', 'max:30'],
                'year_level' => ['required', 'integer', 'between:1,8'],
                'student_status' => ['required', 'in:regular,irregular,graduated,transferred,dropped,leave_of_absence'],
                'admission_date' => ['required', 'date_format:Y-m-d'],
                'gender' => ['required', 'in:Male,Female,Prefer not to say'],
                'birth_date' => ['nullable', 'date_format:Y-m-d'],
                'contact_number' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:2000'],
                'record_reference' => ['required', 'string', 'max:120'],
            ])->errors()->all())->flatten()->all();
            $numberKey = mb_strtolower($row['student_number']);
            if (isset($numbers[$numberKey])) {
                $errors[] = 'Student number repeats row '.$numbers[$numberKey].'.';
            }
            $numbers[$numberKey] = $index + 2;
            if ($row['enrollment_email'] !== '') {
                if (isset($emails[$row['enrollment_email']])) {
                    $errors[] = 'Enrollment email repeats row '.$emails[$row['enrollment_email']].'.';
                }
                $emails[$row['enrollment_email']] = $index + 2;
            }
            if (Student::query()->whereRaw('LOWER(student_number) = ?', [$numberKey])->exists()) {
                $errors[] = 'Student number already exists; this import never overwrites records.';
            }
            if ($row['enrollment_email'] !== '' && UserProfile::query()->whereRaw('LOWER(email) = ?', [$row['enrollment_email']])->exists()) {
                $errors[] = 'Email is already assigned to a profile.';
            }
            $course = Course::query()->where('course_code', $row['course_code'])->first();
            if (! $course) {
                $errors[] = 'Course code was not found.';
            }
            if ($course && ! Curriculum::query()->where('curriculum_code', $row['curriculum_code'])->where('course_id', $course->id)->exists()) {
                $errors[] = 'Curriculum does not belong to this course.';
            }
            if ($course && (int) $row['year_level'] > $course->years) {
                $errors[] = 'Year level exceeds course duration.';
            }
            $results[] = ['row' => $index + 2, 'data' => $row, 'errors' => $errors];
        }

        return $results;
    }
}
