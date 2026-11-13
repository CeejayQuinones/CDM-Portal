<?php

namespace Tests\Feature;

use App\Models\Enrollment\EnrollmentPeriod;
use App\Models\HistoricalGradeRecord;
use App\Models\Section;
use App\Models\Semester;
use App\Models\StudentRecordAuditEvent;
use App\Models\Subject;
use App\Services\Enrollment\EnrollmentWorkflowService;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EnrollmentAcademicFixture;
use Tests\TestCase;

class EnrollmentYearLevelTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->fixture = EnrollmentAcademicFixture::create('promotion');
    }

    public function test_year_level_is_only_promoted_after_verified_completion_registrar_approval_and_finalization(): void
    {
        $f = $this->fixture;
        $f['period']->update(['enabled' => false]);
        $semester = Semester::create(['semester_name' => 'Next term', 'semester_order' => 2, 'status' => 'active']);
        $period = EnrollmentPeriod::create([
            'academic_year_id' => $f['year']->id, 'semester_id' => $semester->id,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'enabled' => true,
            'document_requirements' => ['regular' => [], 'irregular' => [], 'transferee' => [], 'returnee' => []],
            'created_by' => $f['registrar']->id, 'updated_by' => $f['registrar']->id,
        ]);
        $nextSubject = Subject::create([
            'curriculum_id' => $f['curriculum']->id, 'semester_id' => $semester->id,
            'subject_code' => 'PROMO201', 'subject_name' => 'Second year subject',
            'year_level' => 2, 'units' => 3, 'lecture_hours' => 3,
            'laboratory_hours' => 0, 'status' => 'active',
        ]);
        $section = Section::create([
            'course_id' => $f['course']->id, 'academic_year_id' => $f['year']->id,
            'semester_id' => $semester->id, 'section_name' => 'PROMO-2A',
            'year_level' => 2, 'capacity' => 30, 'status' => 'open',
        ]);

        Sanctum::actingAs($f['user']);
        $this->postJson('/api/enrollment/applications', [
            'period_id' => $period->id, 'classification' => 'regular', 'year_level' => 3,
        ])->assertUnprocessable();
        $application = $this->postJson('/api/enrollment/applications', [
            'period_id' => $period->id, 'classification' => 'regular', 'year_level' => 2,
        ])->assertCreated()->assertJsonPath('data.year_level', 2)->json('data');
        $this->assertSame(1, $f['student']->fresh()->year_level);
        $this->getJson('/api/enrollment/applications/'.$application['id'])
            ->assertOk()->assertJsonPath('data.year_level_review.requires_promotion', true)
            ->assertJsonPath('data.year_level_review.eligible_for_promotion', false)
            ->assertJsonPath('data.year_level_review.required_subjects', 2);

        $workflow = app(EnrollmentWorkflowService::class);
        $a = $workflow->mutate($f['user'], $application['id'], 'submit', ['version' => $application['version']]);
        $a = $workflow->mutate($f['registrar'], $a->id, 'review', ['version' => $a->version]);
        Sanctum::actingAs($f['registrar']);
        $url = '/api/enrollment/applications/'.$a->id.'/approve';
        $this->postJson($url, ['version' => $a->version, 'confirmed' => true, 'notes' => 'Checked record.'])
            ->assertUnprocessable();

        foreach (array_slice($f['subjects'], 0, 2) as $subject) {
            HistoricalGradeRecord::create([
                'student_id' => $f['student']->id, 'academic_year_id' => $f['year']->id,
                'semester_id' => $f['semester']->id, 'curriculum_id' => $f['curriculum']->id,
                'subject_id' => $subject->id, 'subject_code' => $subject->subject_code,
                'subject_name' => $subject->subject_name, 'credited_units' => $subject->units,
                'final_grade' => '1.75', 'official_result' => 'Passed',
                'source_type' => 'manual', 'source_reference' => 'Synthetic test record',
                'verification_status' => 'verified', 'entered_by' => $f['registrar']->id,
                'verified_by' => $f['registrar']->id, 'verified_at' => now(),
            ]);
        }
        $this->postJson($url, ['version' => $a->version, 'confirmed' => true])->assertUnprocessable();
        $this->postJson($url, ['version' => $a->version, 'confirmed' => true, 'notes' => 'Verified both first-year results.'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, $f['student']->fresh()->year_level);

        $academic = '/api/enrollment/academic/applications/'.$a->id;
        HistoricalGradeRecord::where('student_id', $f['student']->id)->where('subject_id', $f['subjects'][0]->id)
            ->update(['verification_status' => 'pending']);
        $this->postJson($academic.'/subjects', ['version' => $a->fresh()->version, 'subject_ids' => [$nextSubject->id]])
            ->assertUnprocessable();
        HistoricalGradeRecord::where('student_id', $f['student']->id)->where('subject_id', $f['subjects'][0]->id)
            ->update(['verification_status' => 'verified']);
        $this->postJson($academic.'/subjects', ['version' => $a->fresh()->version, 'subject_ids' => [$nextSubject->id]])->assertOk();
        $this->postJson($academic.'/assign', ['version' => $a->fresh()->version, 'section_id' => $section->id])->assertOk();
        $this->postJson($academic.'/finalize', ['version' => $a->fresh()->version, 'confirmed' => true])
            ->assertOk()->assertJsonPath('data.status', 'enrolled');
        $this->assertSame(2, $f['student']->fresh()->year_level);
        $this->assertSame(1, StudentRecordAuditEvent::where('student_id', $f['student']->id)
            ->where('action', 'student_year_level_promoted')->count());
        $this->assertSame(1, $f['application']->fresh()->year_level);
    }
}
