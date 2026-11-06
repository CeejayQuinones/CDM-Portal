<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfessorSubjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = $this->fixture();
        Sanctum::actingAs($this->fixture['registrar']);
    }

    public function test_registrar_assigns_multiple_subjects_and_more_than_one_professor_can_share_a_subject(): void
    {
        $professor = $this->fixture['professor'];
        $subjectIds = collect($this->fixture['subjects'])->take(2)->pluck('id')->all();

        $this->putJson("/api/registrar/professors/{$professor->id}/subjects", [
            'subject_ids' => $subjectIds,
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.assigned_subject_ids', $subjectIds);

        $other = $this->professor('SHARED');
        $this->putJson("/api/registrar/professors/{$other->id}/subjects", [
            'subject_ids' => [$subjectIds[0]],
            'confirmed' => true,
        ])->assertOk();

        $this->assertDatabaseHas('professor_subject', ['professor_id' => $professor->id, 'subject_id' => $subjectIds[0]]);
        $this->assertDatabaseHas('professor_subject', ['professor_id' => $other->id, 'subject_id' => $subjectIds[0]]);
        $this->assertDatabaseHas('account_audit_events', [
            'actor_user_id' => $this->fixture['registrar']->id,
            'subject_user_id' => $professor->user_id,
            'action' => 'professor_subjects_updated',
        ]);

        $this->getJson("/api/registrar/professors/{$professor->id}/subjects")
            ->assertOk()
            ->assertJsonPath('data.assigned_subject_ids', $subjectIds)
            ->assertJsonPath('data.subjects.0.program_code', $this->fixture['course']->course_code)
            ->assertJsonPath('data.subjects.0.department_code', $this->fixture['department']->department_code);
    }

    public function test_subject_side_updates_the_same_associations_as_professor_management(): void
    {
        $subject = $this->fixture['subjects'][0];
        $first = $this->fixture['professor'];
        $second = $this->professor('SUBJECT-SIDE');

        $this->putJson("/api/enrollment/academic/subjects/{$subject->id}/professors", [
            'professor_ids' => [$first->id, $second->id],
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.assigned_professor_ids', [$first->id, $second->id]);

        $this->getJson("/api/enrollment/academic/subjects/{$subject->id}/professors")
            ->assertOk()->assertJsonCount(2, 'data.professors')
            ->assertJsonPath('data.assigned_professor_ids', [$first->id, $second->id]);
        $this->getJson("/api/registrar/professors/{$first->id}/subjects")
            ->assertOk()->assertJsonPath('data.assigned_subject_ids', [$subject->id]);

        $this->putJson("/api/enrollment/academic/subjects/{$subject->id}/professors", [
            'professor_ids' => [$second->id],
            'confirmed' => true,
        ])->assertOk();
        $this->assertDatabaseMissing('professor_subject', ['professor_id' => $first->id, 'subject_id' => $subject->id]);
        $this->assertDatabaseHas('professor_subject', ['professor_id' => $second->id, 'subject_id' => $subject->id]);
    }

    public function test_duplicate_subject_ids_are_rejected_and_the_database_constraint_is_unique(): void
    {
        $professor = $this->fixture['professor'];
        $subjectId = $this->fixture['subjects'][0]->id;

        $this->putJson("/api/registrar/professors/{$professor->id}/subjects", [
            'subject_ids' => [$subjectId, $subjectId],
            'confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_ids.1');

        DB::table('professor_subject')->insert([
            'professor_id' => $professor->id,
            'subject_id' => $subjectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('professor_subject')->insert([
            'professor_id' => $professor->id,
            'subject_id' => $subjectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_removing_an_association_preserves_section_instructor_and_adviser_records(): void
    {
        $professor = $this->fixture['professor'];
        $subject = $this->fixture['subjects'][0];
        $section = $this->fixture['section'];
        $section->update(['adviser_id' => $professor->id]);
        $assignment = SectionSubject::query()->create([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'professor_id' => $professor->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'room' => 'Room 101',
        ]);
        $professor->subjects()->attach($subject->id);

        $this->putJson("/api/registrar/professors/{$professor->id}/subjects", [
            'subject_ids' => [],
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.assigned_subject_ids', []);

        $this->assertDatabaseHas('section_subjects', [
            'id' => $assignment->id,
            'professor_id' => $professor->id,
            'subject_id' => $subject->id,
        ]);
        $this->assertDatabaseHas('sections', ['id' => $section->id, 'adviser_id' => $professor->id]);
    }

    public function test_subject_association_alone_does_not_grant_teaching_or_grading_access(): void
    {
        $assignedProfessor = $this->fixture['professor'];
        $subject = $this->fixture['subjects'][0];
        $assignment = SectionSubject::query()->create([
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $subject->id,
            'professor_id' => $assignedProfessor->id,
            'day' => 'Tuesday',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'room' => 'Room 202',
        ]);
        $associatedProfessor = $this->professor('ASSOCIATED');
        $associatedProfessor->subjects()->attach($subject->id);

        Sanctum::actingAs($associatedProfessor->user);
        $this->withHeader('X-CDM-Client', 'web')->getJson('/api/grading/classes')
            ->assertOk()->assertJsonPath('data.total', 0);
        $this->withHeader('X-CDM-Client', 'web')->postJson("/api/grading/classes/{$assignment->id}/workspace")
            ->assertNotFound();
    }

    public function test_same_subject_can_have_different_section_instructors_while_schedule_conflicts_stay_enforced(): void
    {
        $firstProfessor = $this->fixture['professor'];
        $secondProfessor = $this->professor('SECOND');
        $subject = $this->fixture['subjects'][0];
        $firstProfessor->subjects()->attach($subject->id);
        $secondProfessor->subjects()->attach($subject->id);
        $secondSection = Section::query()->create([
            'course_id' => $this->fixture['course']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'section_name' => 'BSIT-1B',
            'year_level' => 1,
            'capacity' => 40,
            'status' => 'open',
        ]);

        $base = '/api/enrollment/academic/schedules';
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, [
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $subject->id,
            'professor_id' => $firstProfessor->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'room' => 'Room 101',
        ])->assertCreated();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, [
            'section_id' => $secondSection->id,
            'subject_id' => $subject->id,
            'professor_id' => $secondProfessor->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'room' => 'Room 202',
        ])->assertCreated();

        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, [
            'section_id' => $secondSection->id,
            'subject_id' => $this->fixture['subjects'][1]->id,
            'professor_id' => $secondProfessor->id,
            'day' => 'Monday',
            'start_time' => '08:30',
            'end_time' => '09:30',
            'room' => 'Room 303',
        ])->assertConflict()->assertJsonPath('message', 'Monday, 08:30–09:30 conflicts with an existing schedule for this section (BSIT-1B / IT101).');
    }

    public function test_multi_day_schedule_is_atomic_and_single_day_requests_remain_compatible(): void
    {
        $base = '/api/enrollment/academic/schedules';
        $payload = [
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $this->fixture['professor']->id,
            'room' => 'Room 101',
            'meetings' => [
                ['day' => 'Monday', 'start_time' => '08:00', 'end_time' => '10:00'],
                ['day' => 'Wednesday', 'start_time' => '13:00', 'end_time' => '15:00'],
                ['day' => 'Friday', 'start_time' => '09:00', 'end_time' => '11:00'],
            ],
        ];
        $response = $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, $payload)
            ->assertCreated()->assertJsonCount(3, 'data.meetings');
        $assignmentId = $response->json('data.id');
        $this->assertDatabaseCount('section_subject_meetings', 3);
        $this->assertDatabaseHas('section_subjects', ['id' => $assignmentId, 'day' => 'Monday', 'start_time' => '08:00:00']);

        $secondSection = Section::query()->create([
            'course_id' => $this->fixture['course']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'section_name' => 'BSIT-1C', 'year_level' => 1, 'capacity' => 40, 'status' => 'open',
        ]);
        $secondProfessor = $this->professor('SINGLE-DAY');
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, [
            'section_id' => $secondSection->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $secondProfessor->id,
            'day' => 'Monday', 'start_time' => '10:00', 'end_time' => '12:00', 'room' => 'Room 202',
        ])->assertCreated()->assertJsonCount(1, 'data.meetings');

        $before = SectionSubject::query()->count();
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, [
            'section_id' => $secondSection->id,
            'subject_id' => $this->fixture['subjects'][1]->id,
            'professor_id' => $secondProfessor->id,
            'room' => 'Room 303',
            'meetings' => [
                ['day' => 'Tuesday', 'start_time' => '08:00', 'end_time' => '09:00'],
                ['day' => 'Monday', 'start_time' => '10:30', 'end_time' => '11:30'],
            ],
        ])->assertConflict()->assertJsonPath('message', 'Monday, 10:30–11:30 conflicts with an existing schedule for this section (BSIT-1C / IT101).');
        $this->assertSame($before, SectionSubject::query()->count());
        $this->assertDatabaseMissing('section_subjects', ['section_id' => $secondSection->id, 'subject_id' => $this->fixture['subjects'][1]->id]);
    }

    public function test_multi_day_schedule_reports_professor_and_room_conflicts_and_allows_adjacent_meetings(): void
    {
        $base = '/api/enrollment/academic/schedules';
        $primary = $this->fixture['professor'];
        $other = $this->professor('CONFLICTS');
        $secondSection = Section::query()->create([
            'course_id' => $this->fixture['course']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'section_name' => 'BSIT-1D', 'year_level' => 1, 'capacity' => 40, 'status' => 'open',
        ]);

        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, [
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $primary->id,
            'room' => 'Room 101',
            'meetings' => [['day' => 'Wednesday', 'start_time' => '08:00', 'end_time' => '10:00']],
        ])->assertCreated();

        $candidate = [
            'section_id' => $secondSection->id,
            'subject_id' => $this->fixture['subjects'][1]->id,
            'professor_id' => $primary->id,
            'room' => 'Room 202',
            'meetings' => [['day' => 'Wednesday', 'start_time' => '09:00', 'end_time' => '11:00']],
        ];
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, $candidate)
            ->assertConflict()->assertJsonPath('message', 'Wednesday, 09:00–11:00 conflicts with an existing schedule for this professor (BSIT-1A / IT101).');

        $candidate['professor_id'] = $other->id;
        $candidate['room'] = ' room 101 ';
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, $candidate)
            ->assertConflict()->assertJsonPath('message', 'Wednesday, 09:00–11:00 conflicts with an existing schedule for this room (BSIT-1A / IT101).');

        $candidate['room'] = 'Room 202';
        $candidate['meetings'][0] = ['day' => 'Wednesday', 'start_time' => '10:00', 'end_time' => '11:00'];
        $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, $candidate)->assertCreated();
    }

    public function test_duplicate_weekdays_are_rejected_before_any_schedule_is_written(): void
    {
        $payload = [
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $this->fixture['professor']->id,
            'room' => 'Room 101',
            'meetings' => [
                ['day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00'],
                ['day' => 'Monday', 'start_time' => '13:00', 'end_time' => '14:00'],
            ],
        ];

        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/enrollment/academic/schedules', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('meetings.1.day');
        $this->assertDatabaseCount('section_subjects', 0);
        $this->assertDatabaseCount('section_subject_meetings', 0);
    }

    public function test_multi_day_request_validation_and_editing_use_the_meetings_contract(): void
    {
        $base = '/api/enrollment/academic/schedules';
        $payload = [
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $this->fixture['professor']->id,
            'room' => 'Room 250',
            'meetings' => [['day' => 'Monday', 'start_time' => '13:00', 'end_time' => '14:00']],
        ];

        $created = $this->withHeader('X-CDM-Client', 'desktop')->postJson($base, $payload)
            ->assertCreated()
            ->assertJsonPath('data.day', 'Monday')
            ->assertJsonPath('data.start_time', '13:00:00')
            ->assertJsonPath('data.meetings.0.start_time', '13:00:00');
        $assignmentId = $created->json('data.id');
        $version = $created->json('data.version');

        $this->withHeader('X-CDM-Client', 'desktop')->putJson("{$base}/{$assignmentId}", array_replace($payload, [
            'version' => $version,
            'meetings' => [
                ['day' => 'Monday', 'start_time' => '13:00', 'end_time' => '14:00'],
                ['day' => 'Wednesday', 'start_time' => '09:15', 'end_time' => '10:45'],
            ],
        ]))->assertOk()->assertJsonCount(2, 'data.meetings');
        $this->assertDatabaseHas('section_subject_meetings', [
            'section_subject_id' => $assignmentId,
            'day' => 'Wednesday',
            'start_time' => '09:15:00',
            'end_time' => '10:45:00',
        ]);

        $current = SectionSubject::query()->findOrFail($assignmentId);
        $this->withHeader('X-CDM-Client', 'desktop')->putJson("{$base}/{$assignmentId}", array_replace($payload, [
            'version' => $current->version,
            'meetings' => [['day' => 'Monday', 'start_time' => '14:00', 'end_time' => '13:00']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('meetings.0.end_time');
        $this->assertDatabaseCount('section_subject_meetings', 2);
    }

    public function test_professor_conflicts_are_enforced_across_sections_and_year_levels_in_the_same_term(): void
    {
        $professor = $this->fixture['professor'];
        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/enrollment/academic/schedules', [
            'section_id' => $this->fixture['section']->id,
            'subject_id' => $this->fixture['subjects'][0]->id,
            'professor_id' => $professor->id,
            'day' => 'Thursday', 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Room 101',
        ])->assertCreated();

        $yearTwoSubject = Subject::query()->create([
            'curriculum_id' => $this->fixture['curriculum']->id,
            'semester_id' => $this->fixture['semester']->id,
            'subject_code' => 'IT201',
            'subject_name' => 'Year Two Subject',
            'year_level' => 2,
            'units' => 3,
            'lecture_hours' => 3,
            'laboratory_hours' => 0,
            'status' => 'active',
        ]);
        $yearTwoSection = Section::query()->create([
            'course_id' => $this->fixture['course']->id,
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $this->fixture['semester']->id,
            'section_name' => 'BSIT-2A', 'year_level' => 2, 'capacity' => 40, 'status' => 'open',
        ]);

        $this->withHeader('X-CDM-Client', 'desktop')->postJson('/api/enrollment/academic/schedules', [
            'section_id' => $yearTwoSection->id,
            'subject_id' => $yearTwoSubject->id,
            'professor_id' => $professor->id,
            'day' => 'Thursday', 'start_time' => '09:00', 'end_time' => '11:00', 'room' => 'Room 202',
        ])->assertConflict()->assertJsonPath('message', 'Thursday, 09:00–11:00 conflicts with an existing schedule for this professor (BSIT-1A / IT101).');
        $this->assertDatabaseMissing('section_subjects', ['section_id' => $yearTwoSection->id, 'subject_id' => $yearTwoSubject->id]);
    }

    public function test_only_registrar_staff_can_manage_subject_associations(): void
    {
        $professor = $this->fixture['professor'];
        $payload = ['subject_ids' => [$this->fixture['subjects'][0]->id], 'confirmed' => true];

        foreach ([Role::ADMIN, Role::PROFESSOR, Role::STUDENT, Role::GUEST] as $role) {
            Sanctum::actingAs(User::factory()->create([
                'role_id' => Role::query()->firstOrCreate(['role_name' => $role])->id,
                'status' => 'active',
            ]));
            $this->putJson("/api/registrar/professors/{$professor->id}/subjects", $payload)->assertForbidden();
            $this->putJson("/api/enrollment/academic/subjects/{$this->fixture['subjects'][0]->id}/professors", [
                'professor_ids' => [$professor->id], 'confirmed' => true,
            ])->assertForbidden();
        }
    }

    private function professor(string $suffix, ?Department $department = null): Professor
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('role_name', Role::PROFESSOR)->value('id'),
            'status' => 'active',
        ]);
        $profile = $user->profile()->create([
            'first_name' => 'Professor',
            'last_name' => $suffix,
            'gender' => 'Prefer not to say',
        ]);

        return Professor::query()->create([
            'user_id' => $user->id,
            'user_profile_id' => $profile->id,
            'department_id' => ($department ?? $this->fixture['department'])->id,
            'employee_number' => "PS-{$suffix}",
            'status' => 'active',
        ]);
    }

    private function fixture(): array
    {
        foreach ([Role::ADMIN, Role::REGISTRAR_STAFF, Role::PROFESSOR, Role::STUDENT, Role::GUEST] as $role) {
            Role::query()->firstOrCreate(['role_name' => $role]);
        }
        $registrar = User::factory()->create([
            'role_id' => Role::query()->where('role_name', Role::REGISTRAR_STAFF)->value('id'),
            'status' => 'active',
        ]);
        $department = Department::query()->create([
            'department_code' => 'ICS',
            'department_name' => 'Institute of Computer Studies',
            'status' => 'active',
        ]);
        $course = Course::query()->create([
            'department_id' => $department->id,
            'course_code' => 'BSIT',
            'course_name' => 'Bachelor of Science in Information Technology',
            'years' => 4,
            'status' => 'active',
        ]);
        $curriculum = Curriculum::query()->create([
            'course_id' => $course->id,
            'curriculum_code' => 'BSIT-2026',
            'curriculum_name' => 'BSIT Curriculum 2026',
            'effective_year' => 2026,
            'status' => 'active',
        ]);
        $semester = Semester::query()->create([
            'semester_name' => 'First Semester',
            'semester_order' => 1,
            'status' => 'active',
        ]);
        $year = AcademicYear::query()->create([
            'school_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-30',
            'status' => 'active',
        ]);
        $subjects = collect([1, 2, 3])->map(fn (int $number): Subject => Subject::query()->create([
            'curriculum_id' => $curriculum->id,
            'semester_id' => $semester->id,
            'subject_code' => "IT10{$number}",
            'subject_name' => "Subject {$number}",
            'year_level' => 1,
            'units' => 3,
            'lecture_hours' => 3,
            'laboratory_hours' => 0,
            'status' => 'active',
        ]))->all();
        $professor = $this->professor('PRIMARY', $department);
        $section = Section::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'section_name' => 'BSIT-1A',
            'year_level' => 1,
            'capacity' => 40,
            'status' => 'open',
        ]);

        return compact('registrar', 'department', 'course', 'curriculum', 'semester', 'year', 'subjects', 'professor', 'section');
    }
}
