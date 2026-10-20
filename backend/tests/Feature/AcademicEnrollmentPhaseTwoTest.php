<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class AcademicEnrollmentPhaseTwoTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('phase-two');
        Sanctum::actingAs($this->fixture['registrar']);
    }

    public function test_staff_manage_academic_years_and_conflicting_active_configuration_is_blocked(): void
    {
        $payload = [
            'school_year' => '2027-2028',
            'start_date' => '2027-08-01',
            'end_date' => '2028-05-31',
            'status' => 'upcoming',
        ];
        $year = $this->postJson('/api/enrollment/academic-years', $payload)
            ->assertCreated()->assertJsonPath('data.school_year', '2027-2028')->json('data');
        $this->postJson('/api/enrollment/academic-years', $payload)->assertConflict();

        $payload['status'] = 'active';
        $this->putJson('/api/enrollment/academic-years/'.$year['id'], $payload)->assertConflict();

        $semester = Semester::create(['semester_name' => 'Phase Two Term', 'semester_order' => 2, 'status' => 'active']);
        $period = [
            'academic_year_id' => $year['id'],
            'semester_id' => $semester->id,
            'opens_at' => now()->subHour()->toISOString(),
            'closes_at' => now()->addHour()->toISOString(),
            'enabled' => true,
            'document_requirements' => $this->requirements(),
        ];
        $this->postJson('/api/enrollment/periods', $period)->assertUnprocessable();

        foreach ([Role::STUDENT, Role::PROFESSOR, Role::GUEST] as $role) {
            Sanctum::actingAs(User::factory()->create([
                'role_id' => Role::firstOrCreate(['role_name' => $role])->id,
                'status' => 'active',
            ]));
            $this->postJson('/api/enrollment/academic-years', array_replace($payload, ['school_year' => '2028-2029']))->assertForbidden();
        }
    }

    public function test_overlapping_enabled_periods_are_rejected_and_non_overlapping_history_is_allowed(): void
    {
        $semester = Semester::create(['semester_name' => 'Overlapping Term', 'semester_order' => 3, 'status' => 'active']);
        $payload = [
            'academic_year_id' => $this->fixture['year']->id,
            'semester_id' => $semester->id,
            'opens_at' => now()->toISOString(),
            'closes_at' => now()->addHours(2)->toISOString(),
            'enabled' => true,
            'document_requirements' => $this->requirements(),
        ];
        $this->postJson('/api/enrollment/periods', $payload)->assertConflict();

        $payload['opens_at'] = now()->addDays(2)->toISOString();
        $payload['closes_at'] = now()->addDays(3)->toISOString();
        $this->postJson('/api/enrollment/periods', $payload)->assertCreated()->assertJsonPath('data.state', 'upcoming');
    }

    public function test_optional_documents_are_visible_but_do_not_block_submission(): void
    {
        $optional = DocumentType::create([
            'document_name' => 'Optional supporting evidence', 'status' => 'active',
            'processing_fee' => 0, 'processing_days' => 1, 'requires_appointment' => false,
        ]);
        $this->fixture['period']->update(['document_requirements' => [
            'regular' => ['required' => [], 'optional' => [$optional->id]],
            'irregular' => ['required' => [], 'optional' => []],
            'transferee' => ['required' => [], 'optional' => []],
            'returnee' => ['required' => [], 'optional' => []],
        ]]);
        $application = $this->fixture['application'];
        $application->forceFill(['status' => 'draft', 'version' => $application->version + 1, 'submitted_at' => null, 'reviewed_at' => null, 'reviewed_by' => null])->save();

        Sanctum::actingAs($this->fixture['user']);
        $this->getJson('/api/enrollment/applications/'.$application->id)
            ->assertOk()
            ->assertJsonPath('data.requirements.0.name', 'Optional supporting evidence')
            ->assertJsonPath('data.requirements.0.required', false);
        $this->postJson('/api/enrollment/applications/'.$application->id.'/submit', [
            'version' => $application->version, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_section_curriculum_drives_second_year_subject_and_professor_assignment(): void
    {
        $other = Curriculum::create([
            'course_id' => $this->fixture['course']->id,
            'curriculum_code' => 'OTHER-2026', 'curriculum_name' => 'Other Curriculum',
            'effective_year' => 2026, 'status' => 'active',
        ]);
        $wrong = Subject::create([
            'curriculum_id' => $other->id, 'semester_id' => $this->fixture['semester']->id,
            'subject_code' => 'WRONG201', 'subject_name' => 'Wrong Curriculum Subject',
            'year_level' => 2, 'units' => 3, 'lecture_hours' => 3, 'laboratory_hours' => 0, 'status' => 'active',
        ]);
        $section = $this->fixture['section'];
        $section->update(['curriculum_id' => $this->fixture['curriculum']->id, 'year_level' => 2]);

        $url = '/api/enrollment/academic/schedules?section_id='.$section->id;
        $this->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.id', $this->fixture['subjects'][2]->id)
            ->assertJsonMissing(['id' => $wrong->id]);

        $this->postJson('/api/enrollment/academic/schedules', [
            'section_id' => $section->id,
            'subject_id' => $this->fixture['subjects'][2]->id,
            'professor_id' => $this->fixture['professor']->id,
            'day' => 'Wednesday', 'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'LAB 2',
        ])->assertCreated()->assertJsonPath('data.professor_id', $this->fixture['professor']->id);
        $this->assertDatabaseHas('section_subjects', [
            'section_id' => $section->id,
            'subject_id' => $this->fixture['subjects'][2]->id,
            'professor_id' => $this->fixture['professor']->id,
        ]);
    }

    private function requirements(): array
    {
        return collect(['regular', 'irregular', 'transferee', 'returnee'])
            ->mapWithKeys(fn ($classification) => [$classification => ['required' => [], 'optional' => []]])->all();
    }
}
