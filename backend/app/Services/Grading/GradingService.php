<?php

namespace App\Services\Grading;

use App\Models\EnrollmentSubject;
use App\Models\GradeAssessment;
use App\Models\GradeAuditEvent;
use App\Models\GradeCategoryWeight;
use App\Models\GradeLateSubmissionExtension;
use App\Models\GradePeriodSchedule;
use App\Models\GradeScore;
use App\Models\GradeSheet;
use App\Models\GradingScale;
use App\Models\Professor;
use App\Models\Role;
use App\Models\SectionSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GradingService
{
    public const CATEGORIES = ['Quiz', 'Activity', 'Recitation', 'Major Exam'];

    public const DEFAULT_WEIGHTS = ['Quiz' => 15, 'Activity' => 35, 'Recitation' => 10, 'Major Exam' => 40];

    public function professor(User $user): Professor
    {
        $actor = $user->fresh('role');
        abort_unless($actor->status === 'active' && $actor->role?->role_name === Role::PROFESSOR, 403, 'Active Professor access required.');

        $professor = Professor::where('user_id', $actor->id)->where('status', 'active')->first();
        abort_unless($professor, 409, 'Your academic Professor profile is not configured. Contact the Registrar before using Grading.');

        return $professor;
    }

    public function classes(User $user, array $filters)
    {
        $professor = $this->professor($user);
        $query = SectionSubject::query()->where('professor_id', $professor->id)
            ->with(['section.course.department', 'section.academicYear', 'section.semester', 'subject', 'gradeSheet'])
            ->select('section_subjects.*')->selectSub(function ($query) {
                $query->from('enrollment_subjects as es')->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
                    ->whereColumn('es.subject_id', 'section_subjects.subject_id')
                    ->where(fn ($roster) => $roster->whereColumn('es.section_subject_id', 'section_subjects.id')
                        ->orWhere(fn ($home) => $home->whereNull('es.section_subject_id')
                            ->whereColumn('e.section_id', 'section_subjects.section_id')))
                    ->whereIn('e.status', ['enrolled', 'completed'])->whereIn('es.subject_status', ['enrolled', 'completed'])
                    ->selectRaw('count(*)');
            }, 'student_count');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->whereHas('subject', fn ($s) => $s->where('subject_code', 'like', "%{$search}%")->orWhere('subject_name', 'like', "%{$search}%"))->orWhereHas('section', fn ($s) => $s->where('section_name', 'like', "%{$search}%")));
        }
        foreach (['academic_year_id', 'semester_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->whereHas('section', fn ($q) => $q->where($key, $filters[$key]));
            }
        }

        $page = $query->latest('section_subjects.id')->paginate(24);
        $terms = $page->getCollection()->map(fn ($assignment) => [$assignment->section->academic_year_id, $assignment->section->semester_id])->unique();
        if ($terms->isEmpty()) {
            return $page;
        }
        $schedules = GradePeriodSchedule::where(function ($query) use ($terms) {
            foreach ($terms as [$year, $semester]) {
                $query->orWhere(fn ($term) => $term->where('academic_year_id', $year)->where('semester_id', $semester));
            }
        })->get()->keyBy(fn ($schedule) => $schedule->academic_year_id.':'.$schedule->semester_id);
        $page->getCollection()->transform(fn ($assignment) => $this->classData($assignment, null, $schedules->get($assignment->section->academic_year_id.':'.$assignment->section->semester_id)));

        return $page;
    }

    public function openWorkspace(User $user, SectionSubject $assignment): array
    {
        $professor = $this->professor($user);
        abort_unless($assignment->professor_id === $professor->id, 404, 'Assigned class not found.');
        $sheet = DB::transaction(function () use ($user, $assignment, $professor) {
            $locked = SectionSubject::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($locked->professor_id === $professor->id, 409, 'The class assignment changed. Reload before continuing.');
            $sheet = GradeSheet::firstOrCreate(['section_subject_id' => $locked->id], ['professor_id' => $professor->id]);
            abort_unless($sheet->professor_id === $professor->id, 409, 'The grade sheet belongs to another Professor assignment.');
            if ($sheet->wasRecentlyCreated) {
                foreach (['midterm', 'finals'] as $period) {
                    foreach (self::DEFAULT_WEIGHTS as $category => $weight) {
                        GradeCategoryWeight::create(['grade_sheet_id' => $sheet->id, 'period' => $period, 'category' => $category, 'weight_percentage' => $weight]);
                    }
                }
                $this->audit($user, 'grade_sheet.created', $sheet, null, ['section_subject_id' => $locked->id]);
            }

            return $sheet;
        });

        return $this->workspaceData($assignment->fresh(), $sheet->fresh());
    }

    /**
     * Return an existing Professor-owned gradesheet without creating or changing it.
     * This is used by read-only export and import-preview operations.
     */
    public function readWorkspace(User $user, GradeSheet $sheet): array
    {
        $this->assertOwnedSheetAccess($user, $sheet);

        return $this->workspaceData($sheet->sectionSubject()->firstOrFail(), $sheet);
    }

    public function saveAssessment(User $user, GradeSheet $sheet, array $input, ?GradeAssessment $assessment = null): GradeAssessment
    {
        $this->ownedSheet($user, $sheet);
        $this->assertOpen($sheet, $input['period'] ?? $assessment?->period);

        return DB::transaction(function () use ($user, $sheet, $input, $assessment) {
            $lockedSheet = GradeSheet::query()->lockForUpdate()->findOrFail($sheet->id);
            $this->ownedSheet($user, $lockedSheet);
            $categories = GradeCategoryWeight::query()->where('grade_sheet_id', $sheet->id)->lockForUpdate()->pluck('category')->unique()->values();
            $category = trim($input['category']);
            $existingCategory = $categories->first(fn ($value) => mb_strtolower($value) === mb_strtolower($category));
            if (! empty($input['create_category'])) {
                abort_unless(! $assessment && ! $existingCategory, 422, 'Choose a new category name that is not already used.');
                abort_unless($categories->count() < 20, 422, 'This grade sheet has reached the category limit.');
                foreach (['midterm', 'finals'] as $period) {
                    GradeCategoryWeight::create(['grade_sheet_id' => $sheet->id, 'period' => $period, 'category' => $category, 'weight_percentage' => 0]);
                }
                $this->audit($user, 'grade_category.created', $sheet, null, ['category' => $category]);
            } else {
                abort_unless($existingCategory === $category, 422, 'Choose a category already configured for this grade sheet.');
            }
            if ($assessment) {
                $row = GradeAssessment::lockForUpdate()->where('grade_sheet_id', $sheet->id)->findOrFail($assessment->id);
                abort_unless($row->version === (int) $input['version'], 409, 'This assessment changed. Reload before continuing.');
                $old = $row->only(['label', 'category', 'max_score', 'display_order', 'status']);
                $row->fill(collect($input)->only(['label', 'max_score', 'display_order'])->all() + ['category' => $category]);
                $row->version++;
                $row->save();
                $this->audit($user, 'assessment.updated', $sheet, null, ['assessment_id' => $row->id, 'old' => $old, 'new' => $row->only(array_keys($old))]);

                return $row;
            }
            $row = GradeAssessment::create(collect($input)->only(['period', 'label', 'max_score', 'display_order'])->all() + ['category' => $category, 'grade_sheet_id' => $sheet->id]);
            $this->audit($user, 'assessment.created', $sheet, null, ['assessment_id' => $row->id, 'new' => $row->only(['period', 'label', 'category', 'max_score', 'display_order'])]);

            return $row;
        });
    }

    /** @return array<int, GradeAssessment> */
    public function saveAssessmentsBulk(User $user, GradeSheet $sheet, array $input): array
    {
        $this->ownedSheet($user, $sheet);
        $this->assertOpen($sheet, $input['period']);

        return DB::transaction(function () use ($user, $sheet, $input): array {
            $lockedSheet = GradeSheet::query()->lockForUpdate()->findOrFail($sheet->id);
            $this->ownedSheet($user, $lockedSheet);
            $this->assertOpen($lockedSheet, $input['period']);

            $labels = collect($input['assessments'])->map(fn (array $row): string => mb_strtolower(trim($row['label'])));
            abort_if($labels->duplicates()->isNotEmpty(), 422, 'Bulk entry contains duplicate assessment labels.');

            $existingLabels = GradeAssessment::query()
                ->where('grade_sheet_id', $lockedSheet->id)
                ->where('period', $input['period'])
                ->lockForUpdate()
                ->pluck('label')
                ->map(fn (string $label): string => mb_strtolower(trim($label)));
            abort_if($labels->intersect($existingLabels)->isNotEmpty(), 409, 'An assessment with the same label already exists in this grading period.');

            $created = [];
            $categories = GradeCategoryWeight::query()->where('grade_sheet_id', $lockedSheet->id)->where('period', $input['period'])->pluck('category')->all();
            foreach ($input['assessments'] as $assessment) {
                abort_unless(in_array($assessment['category'], $categories, true), 422, 'Bulk entry uses a category that is not configured for this grade sheet.');
                $created[] = GradeAssessment::create($assessment + [
                    'grade_sheet_id' => $lockedSheet->id,
                    'period' => $input['period'],
                ]);
            }
            $this->audit($user, 'assessments.bulk_created', $lockedSheet, null, [
                'period' => $input['period'],
                'assessment_ids' => collect($created)->pluck('id')->all(),
                'count' => count($created),
            ]);

            return $created;
        });
    }

    public function assessmentStatus(User $user, GradeSheet $sheet, GradeAssessment $assessment, array $input): GradeAssessment
    {
        $this->ownedSheet($user, $sheet);
        $this->assertOpen($sheet, $assessment->period);

        return DB::transaction(function () use ($user, $sheet, $assessment, $input) {
            $row = GradeAssessment::lockForUpdate()->where('grade_sheet_id', $sheet->id)->findOrFail($assessment->id);
            abort_unless($row->version === (int) $input['version'], 409, 'This assessment changed. Reload before continuing.');
            $row->status = $input['status'];
            $row->version++;
            $row->save();
            $this->audit($user, $row->status === 'archived' ? 'assessment.archived' : 'assessment.restored', $sheet, null, ['assessment_id' => $row->id]);

            return $row;
        });
    }

    public function saveScores(User $user, GradeSheet $sheet, array $input): array
    {
        $this->ownedSheet($user, $sheet);
        $assessment = GradeAssessment::where('grade_sheet_id', $sheet->id)->where('status', 'active')->findOrFail($input['assessment_id']);
        $this->assertOpen($sheet, $assessment->period);

        return DB::transaction(function () use ($user, $sheet, $assessment, $input) {
            $sheet = GradeSheet::query()->lockForUpdate()->findOrFail($sheet->id);
            $this->ownedSheet($user, $sheet);
            $assessment = GradeAssessment::query()->lockForUpdate()->where('grade_sheet_id', $sheet->id)->where('status', 'active')->findOrFail($assessment->id);
            $this->assertOpen($sheet, $assessment->period);
            $saved = [];
            $changes = [];
            foreach ($input['scores'] as $entry) {
                abort_unless((float) $entry['score'] <= (float) $assessment->max_score, 422, 'A score exceeds the assessment maximum.');
                $valid = $this->rosterQuery($sheet)->where('es.id', $entry['enrollment_subject_id'])->exists();
                abort_unless($valid, 422, 'A Student is not enrolled in this class and subject.');
                $score = GradeScore::lockForUpdate()->where('grade_assessment_id', $assessment->id)->where('enrollment_subject_id', $entry['enrollment_subject_id'])->first();
                if ($score) {
                    abort_unless(isset($entry['version']) && $score->version === (int) $entry['version'], 409, 'A score changed in another session. Reload before saving.');
                    $old = $score->score;
                    $score->score = $entry['score'];
                    $score->updated_by = $user->id;
                    $score->version++;
                    $score->save();
                    $action = 'score.updated';
                } else {
                    $old = null;
                    $score = GradeScore::create(['grade_assessment_id' => $assessment->id, 'enrollment_subject_id' => $entry['enrollment_subject_id'], 'score' => $entry['score'], 'updated_by' => $user->id]);
                    $action = 'score.created';
                }
                $this->audit($user, $action, $sheet, null, ['assessment_id' => $assessment->id, 'enrollment_subject_id' => $score->enrollment_subject_id, 'old' => $old, 'new' => $score->score]);
                $changes[] = ['assessment_id' => $assessment->id, 'enrollment_subject_id' => $score->enrollment_subject_id, 'before' => $old, 'after' => $score->score];
                $saved[] = $score;
            }

            $this->audit($user, 'scores.saved', $sheet, null, ['period' => $assessment->period, 'assessment_label' => $assessment->label, 'changes' => $changes]);

            return $saved;
        });
    }

    public function scoreSaveHistory(User $user, GradeSheet $sheet): array
    {
        $this->assertOwnedSheetAccess($user, $sheet);

        return [
            'revision' => GradeAuditEvent::query()->where('grade_sheet_id', $sheet->id)->where('action', 'like', 'score.%')->max('id') ?? 0,
            'entries' => GradeAuditEvent::query()->where('grade_sheet_id', $sheet->id)
                ->whereIn('action', ['scores.saved', 'scores.restored'])
                ->latest('id')->limit(30)->get()
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'saved_at' => $event->created_at?->toIso8601String(),
                    'period' => $event->metadata['period'] ?? null,
                    'assessment_label' => $event->metadata['assessment_label'] ?? 'Assessment',
                    'count' => count($event->metadata['changes'] ?? []),
                ])->all(),
        ];
    }

    public function restoreScoreSave(User $user, GradeSheet $sheet, GradeAuditEvent $save, int $expectedRevision): void
    {
        $this->ownedSheet($user, $sheet);
        abort_unless($save->grade_sheet_id === $sheet->id && in_array($save->action, ['scores.saved', 'scores.restored'], true), 404, 'Saved score history not found.');
        $metadata = $save->metadata ?? [];
        $changes = $metadata['changes'] ?? [];
        abort_unless(is_array($changes) && $changes !== [], 422, 'This save has no restorable score changes.');

        DB::transaction(function () use ($user, $sheet, $save, $expectedRevision, $metadata, $changes): void {
            $locked = GradeSheet::query()->lockForUpdate()->findOrFail($sheet->id);
            $this->ownedSheet($user, $locked);
            $latestRevision = (int) (GradeAuditEvent::query()->where('grade_sheet_id', $locked->id)->where('action', 'like', 'score.%')->max('id') ?? 0);
            abort_unless($latestRevision === $expectedRevision, 409, 'Scores changed since this history was opened. Reload the save history before restoring.');

            $restored = [];
            foreach ($changes as $change) {
                $assessment = GradeAssessment::query()->lockForUpdate()->where('grade_sheet_id', $locked->id)->where('status', 'active')->findOrFail($change['assessment_id']);
                $this->assertOpen($locked, $assessment->period);
                abort_unless($this->rosterQuery($locked)->where('es.id', $change['enrollment_subject_id'])->exists(), 409, 'A Student is no longer enrolled in this class.');
                $score = GradeScore::query()->lockForUpdate()->where('grade_assessment_id', $assessment->id)->where('enrollment_subject_id', $change['enrollment_subject_id'])->first();
                $current = $score?->score;
                $previous = $change['before'];
                abort_unless($previous === null || ($previous >= 0 && $previous <= $assessment->max_score), 422, 'A historical score no longer fits the assessment maximum.');
                if ($previous === null) {
                    $score?->delete();
                } elseif ($score) {
                    $score->forceFill(['score' => $previous, 'updated_by' => $user->id, 'version' => $score->version + 1])->save();
                } else {
                    GradeScore::create(['grade_assessment_id' => $assessment->id, 'enrollment_subject_id' => $change['enrollment_subject_id'], 'score' => $previous, 'updated_by' => $user->id]);
                }
                $this->audit($user, 'score.restored', $locked, null, ['assessment_id' => $assessment->id, 'enrollment_subject_id' => $change['enrollment_subject_id'], 'old' => $current, 'new' => $previous, 'source_save_id' => $save->id]);
                $restored[] = ['assessment_id' => $assessment->id, 'enrollment_subject_id' => $change['enrollment_subject_id'], 'before' => $current, 'after' => $previous];
            }
            $this->audit($user, 'scores.restored', $locked, null, ['period' => $metadata['period'] ?? null, 'assessment_label' => $metadata['assessment_label'] ?? 'Assessment', 'source_save_id' => $save->id, 'changes' => $restored]);
        });
    }

    public function updateWeights(User $user, GradeSheet $sheet, string $period, array $weights): array
    {
        $this->ownedSheet($user, $sheet);
        $this->assertOpen($sheet, $period);
        abort_unless(abs(array_sum(array_column($weights, 'weight_percentage')) - 100) < 0.001, 422, 'Category weights must total 100%.');

        return DB::transaction(function () use ($user, $sheet, $period, $weights) {
            $configured = GradeCategoryWeight::query()->where('grade_sheet_id', $sheet->id)->where('period', $period)->lockForUpdate()->pluck('category')->sort()->values()->all();
            $submitted = collect($weights)->pluck('category')->sort()->values()->all();
            abort_unless($submitted === $configured, 422, 'Include every configured category exactly once.');
            foreach ($weights as $input) {
                $row = GradeCategoryWeight::lockForUpdate()->where(['grade_sheet_id' => $sheet->id, 'period' => $period, 'category' => $input['category']])->firstOrFail();
                abort_unless($row->version === (int) $input['version'], 409, 'Category weights changed. Reload before continuing.');
                $row->update(['weight_percentage' => $input['weight_percentage'], 'version' => $row->version + 1]);
            }
            $this->audit($user, 'grade_weights.updated', $sheet, null, ['period' => $period]);

            return $sheet->weights()->where('period', $period)->orderBy('id')->get()->all();
        });
    }

    public function periodState(GradePeriodSchedule $schedule, string $period, ?Carbon $now = null): array
    {
        $now ??= now();
        $open = $schedule->{$period.'_opens_at'};
        $deadline = $schedule->{$period.'_deadline'};
        $state = $schedule->status === 'archived' || $now->greaterThanOrEqualTo($deadline) ? 'Closed' : ($now->lessThan($open) ? 'Upcoming' : 'Open');

        return ['state' => $state, 'editable' => $state === 'Open', 'opens_at' => $open?->toIso8601String(), 'deadline' => $deadline?->toIso8601String()];
    }

    public function activeLateExtension(GradeSheet $sheet, bool $lockForUpdate = false): ?GradeLateSubmissionExtension
    {
        $query = GradeLateSubmissionExtension::query()
            ->where('grade_sheet_id', $sheet->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latest('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function lateExtensionData(?GradeLateSubmissionExtension $extension): ?array
    {
        if (! $extension) {
            return null;
        }
        $extension->loadMissing(['authorizedBy.profile', 'revokedBy.profile']);
        $effectiveStatus = $extension->status === 'active' && $extension->expires_at->lessThanOrEqualTo(now()) ? 'expired' : $extension->status;
        $name = fn (?User $user) => $user?->profile
            ? trim($user->profile->first_name.' '.$user->profile->last_name)
            : $user?->username;

        return [
            'id' => $extension->id,
            'grade_sheet_id' => $extension->grade_sheet_id,
            'reason' => $extension->reason,
            'expires_at' => $extension->expires_at->toIso8601String(),
            'status' => $effectiveStatus,
            'is_active' => $effectiveStatus === 'active',
            'authorized_by' => ['id' => $extension->authorized_by, 'name' => $name($extension->authorizedBy)],
            'revoked_by' => $extension->revoked_by ? ['id' => $extension->revoked_by, 'name' => $name($extension->revokedBy)] : null,
            'used_at' => $extension->used_at?->toIso8601String(),
            'revoked_at' => $extension->revoked_at?->toIso8601String(),
            'version' => $extension->version,
            'created_at' => $extension->created_at?->toIso8601String(),
        ];
    }

    public function periodLateExtensions(GradePeriodSchedule $period): array
    {
        $sheets = GradeSheet::query()
            ->whereHas('sectionSubject.section', fn ($query) => $query
                ->where('academic_year_id', $period->academic_year_id)
                ->where('semester_id', $period->semester_id))
            ->with([
                'sectionSubject.subject',
                'sectionSubject.section.course',
                'professor.user.profile',
                'lateSubmissionExtensions' => fn ($query) => $query->with(['authorizedBy.profile', 'revokedBy.profile'])->latest('id'),
            ])
            ->get()
            ->sortBy(fn (GradeSheet $sheet) => sprintf(
                '%s|%s|%s|%s',
                $sheet->professor?->user?->profile?->last_name,
                $sheet->professor?->user?->profile?->first_name,
                $sheet->sectionSubject?->subject?->subject_code,
                $sheet->sectionSubject?->section?->section_name,
            ), SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(function (GradeSheet $sheet) {
                $profile = $sheet->professor?->user?->profile;
                $extensions = $sheet->lateSubmissionExtensions->map(fn ($extension) => $this->lateExtensionData($extension))->values()->all();

                return [
                    'id' => $sheet->id,
                    'status' => $sheet->status,
                    'professor' => ['id' => $sheet->professor_id, 'name' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? ''))],
                    'class' => [
                        'subject_code' => $sheet->sectionSubject?->subject?->subject_code,
                        'subject_name' => $sheet->sectionSubject?->subject?->subject_name,
                        'section' => $sheet->sectionSubject?->section?->section_name,
                        'program' => $sheet->sectionSubject?->section?->course?->course_code,
                    ],
                    'eligible' => in_array($sheet->status, ['draft', 'returned'], true),
                    'active_extension' => collect($extensions)->firstWhere('is_active', true),
                    'extensions' => $extensions,
                ];
            })->all();

        return ['period' => $this->scheduleData($period->loadMissing(['academicYear', 'semester'])), 'sheets' => $sheets];
    }

    public function grantLateExtension(User $actor, GradePeriodSchedule $period, GradeSheet $sheet, array $input): GradeLateSubmissionExtension
    {
        return DB::transaction(function () use ($actor, $period, $sheet, $input) {
            $locked = GradeSheet::query()->lockForUpdate()->with('sectionSubject.section')->findOrFail($sheet->id);
            $section = $locked->sectionSubject->section;
            abort_unless((int) $section->academic_year_id === (int) $period->academic_year_id && (int) $section->semester_id === (int) $period->semester_id, 404, 'Grade sheet does not belong to this grade period.');
            abort_unless(in_array($locked->status, ['draft', 'returned'], true), 409, 'Late access can only be granted to Draft or Returned grade sheets.');
            abort_if($this->activeLateExtension($locked, true), 409, 'This grade sheet already has active late-submission access.');

            $extension = GradeLateSubmissionExtension::create([
                'grade_sheet_id' => $locked->id,
                'reason' => trim($input['reason']),
                'expires_at' => $input['expires_at'],
                'authorized_by' => $actor->id,
            ]);
            $this->audit($actor, 'grade_late_extension.granted', $locked, $period, [
                'extension_id' => $extension->id,
                'professor_id' => $locked->professor_id,
                'section_subject_id' => $locked->section_subject_id,
                'reason' => $extension->reason,
                'expires_at' => $extension->expires_at->toIso8601String(),
            ]);

            return $extension->load(['authorizedBy.profile']);
        });
    }

    public function revokeLateExtension(User $actor, GradeLateSubmissionExtension $extension, int $version): GradeLateSubmissionExtension
    {
        return DB::transaction(function () use ($actor, $extension, $version) {
            $locked = GradeLateSubmissionExtension::query()->lockForUpdate()->findOrFail($extension->id);
            abort_unless($locked->version === $version, 409, 'This late-submission access changed. Reload before continuing.');
            abort_unless($locked->status === 'active', 409, 'Only active late-submission access can be revoked.');
            $locked->forceFill(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'version' => $locked->version + 1])->save();
            $sheet = GradeSheet::with('sectionSubject.section')->findOrFail($locked->grade_sheet_id);
            $section = $sheet->sectionSubject->section;
            $period = GradePeriodSchedule::where(['academic_year_id' => $section->academic_year_id, 'semester_id' => $section->semester_id])->first();
            $this->audit($actor, 'grade_late_extension.revoked', $sheet, $period, ['extension_id' => $locked->id]);

            return $locked->fresh(['authorizedBy.profile', 'revokedBy.profile']);
        });
    }

    public function consumeLateExtension(User $actor, GradeSheet $sheet, ?GradePeriodSchedule $period, GradeLateSubmissionExtension $extension): void
    {
        abort_unless($extension->status === 'active' && $extension->expires_at->isFuture(), 409, 'Late-submission access has expired or is no longer active.');
        $extension->forceFill(['status' => 'used', 'used_at' => now(), 'version' => $extension->version + 1])->save();
        $this->audit($actor, 'grade_late_extension.used', $sheet, $period, ['extension_id' => $extension->id]);
    }

    public function scheduleData(?GradePeriodSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        return $schedule->toArray() + ['midterm_state' => $this->periodState($schedule, 'midterm'), 'finals_state' => $this->periodState($schedule, 'finals')];
    }

    private function ownedSheet(User $user, GradeSheet $sheet): void
    {
        $this->assertOwnedSheetAccess($user, $sheet);
        abort_unless(in_array($sheet->status, ['draft', 'returned'], true), 409, 'This grade sheet is locked for review or publication.');
    }

    public function assertOwnedSheetAccess(User $user, GradeSheet $sheet): Professor
    {
        $professor = $this->professor($user);
        abort_unless($sheet->professor_id === $professor->id && $sheet->sectionSubject()->where('professor_id', $professor->id)->exists(), 404, 'Grade sheet not found.');

        return $professor;
    }

    public function assertEditableSheet(User $user, GradeSheet $sheet, string $period): Professor
    {
        $professor = $this->assertOwnedSheetAccess($user, $sheet);
        abort_unless(in_array($sheet->status, ['draft', 'returned'], true), 409, 'This grade sheet is locked for review or publication.');
        $this->assertOpen($sheet, $period);

        return $professor;
    }

    /** @return array<int, int> */
    public function rosterIds(GradeSheet $sheet, bool $lockForUpdate = false): array
    {
        $query = $this->rosterQuery($sheet)->select('es.id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->pluck('es.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function assertOpen(GradeSheet $sheet, ?string $period): void
    {
        abort_unless(in_array($period, ['midterm', 'finals'], true), 422, 'A valid grading period is required.');
        if ($sheet->status === 'returned') {
            return;
        }
        if ($this->activeLateExtension($sheet)) {
            return;
        }
        $section = $sheet->sectionSubject()->with('section')->firstOrFail()->section;
        $schedule = GradePeriodSchedule::where(['academic_year_id' => $section->academic_year_id, 'semester_id' => $section->semester_id, 'status' => 'active'])->first();
        abort_unless($schedule, 409, 'No active grading schedule is configured for this term.');
        $state = $this->periodState($schedule, $period);
        abort_unless($state['editable'], 409, ucfirst($period).' grade entry is '.$state['state'].'.');
    }

    private function rosterQuery(GradeSheet $sheet): Builder
    {
        $assignment = $sheet->sectionSubject;

        return EnrollmentSubject::query()->from('enrollment_subjects as es')
            ->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
            ->where('es.subject_id', $assignment->subject_id)
            ->where(fn ($roster) => $roster->where('es.section_subject_id', $assignment->id)
                ->orWhere(fn ($home) => $home->whereNull('es.section_subject_id')
                    ->where('e.section_id', $assignment->section_id)))
            ->whereIn('e.status', ['enrolled', 'completed'])->whereIn('es.subject_status', ['enrolled', 'completed']);
    }

    private function workspaceData(SectionSubject $assignment, GradeSheet $sheet): array
    {
        $assignment->load(['section.course.department', 'section.academicYear', 'section.semester', 'subject', 'professor.user.profile']);
        $roster = $this->rosterQuery($sheet)->join('students as s', 's.id', '=', 'e.student_id')->join('user_profiles as up', 'up.id', '=', 's.user_profile_id')
            ->orderBy('up.last_name')->orderBy('up.first_name')->get(['es.id as enrollment_subject_id', 's.id as student_id', 's.student_number', 'up.first_name', 'up.middle_name', 'up.last_name']);
        $sheet->load(['assessments.scores', 'weights']);
        $schedule = GradePeriodSchedule::where(['academic_year_id' => $assignment->section->academic_year_id, 'semester_id' => $assignment->section->semester_id])->first();

        return ['class' => $this->classData($assignment, $roster->count(), $schedule), 'sheet' => $sheet, 'roster' => $roster, 'schedule' => $this->scheduleData($schedule), 'late_submission_extension' => $this->lateExtensionData($this->activeLateExtension($sheet)), 'categories' => collect(self::CATEGORIES)->merge($sheet->weights->pluck('category'))->unique()->values()->all(), 'grade_scales' => GradingScale::query()->where('is_active', true)->orderByDesc('min_percentage')->get(['min_percentage', 'max_percentage', 'grade_point', 'remark', 'is_active'])];
    }

    private function classData(SectionSubject $assignment, ?int $count = null, ?GradePeriodSchedule $schedule = null): array
    {
        $section = $assignment->section;

        return ['id' => $assignment->id, 'subject_code' => $assignment->subject->subject_code, 'subject_name' => $assignment->subject->subject_name, 'units' => (float) $assignment->subject->units, 'section' => $section->section_name, 'year_level' => $section->year_level, 'program' => $section->course->course_code ?? $section->course->course_name, 'institute' => $section->course->department?->department_name, 'academic_year' => $section->academicYear->school_year, 'semester' => $section->semester->semester_name, 'student_count' => $count ?? (int) $assignment->student_count, 'sheet_id' => $assignment->gradeSheet?->id, 'schedule' => $this->scheduleData($schedule)];
    }

    public function audit(User $actor, string $action, ?GradeSheet $sheet, ?GradePeriodSchedule $schedule, array $metadata = []): void
    {
        GradeAuditEvent::create(['actor_user_id' => $actor->id, 'grade_sheet_id' => $sheet?->id, 'grade_period_schedule_id' => $schedule?->id, 'action' => $action, 'metadata' => $metadata]);
    }
}
