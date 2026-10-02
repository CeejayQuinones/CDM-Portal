<?php

namespace App\Services\Event;

use App\Models\Event;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EventAudienceResolver
{
    public function visibleTo(Builder $query, User $user): Builder
    {
        $role = $user->role?->role_name;
        if (in_array($role, [Role::ADMIN, Role::REGISTRAR_STAFF], true)) {
            return $query;
        }

        $query->where('status', 'published');
        if ($role === Role::PROFESSOR) {
            return $query->whereHas('audiences', fn (Builder $audiences) => $audiences
                ->whereIn('audience_type', ['all_professors', 'all_users']));
        }
        if ($role !== Role::STUDENT || ! $user->student) {
            return $query->whereRaw('1 = 0');
        }

        $student = $user->student;
        $sectionIds = $student->enrollments()
            ->whereIn('status', ['enrolled', 'completed'])
            ->whereHas('academicYear', fn (Builder $q) => $q->where('status', 'active'))
            ->whereHas('semester', fn (Builder $q) => $q->where('status', 'active'))
            ->whereNotNull('section_id')->pluck('section_id');
        $yearLevels = $student->enrollments()
            ->whereIn('status', ['enrolled', 'completed'])
            ->whereHas('academicYear', fn (Builder $q) => $q->where('status', 'active'))
            ->whereHas('semester', fn (Builder $q) => $q->where('status', 'active'))
            ->whereHas('section')->with('section:id,year_level')->get()
            ->pluck('section.year_level')->filter()->unique();

        return $query->whereHas('audiences', function (Builder $audiences) use ($student, $sectionIds, $yearLevels): void {
            $audiences->whereIn('audience_type', ['all_students', 'all_users'])
                ->orWhere(fn (Builder $q) => $q->where('audience_type', 'course')->where('course_id', $student->course_id));
            if ($yearLevels->isNotEmpty()) {
                $audiences->orWhere(fn (Builder $q) => $q->where('audience_type', 'year_level')->whereIn('year_level', $yearLevels));
            }
            if ($sectionIds->isNotEmpty()) {
                $audiences->orWhere(fn (Builder $q) => $q->where('audience_type', 'section')->whereIn('section_id', $sectionIds));
            }
        });
    }

    public function canView(Event $event, User $user): bool
    {
        return $this->visibleTo(Event::query(), $user)->whereKey($event->getKey())->exists();
    }

    public function eligibleStudents(Event $event): Builder
    {
        $event->loadMissing('audiences');
        $types = $event->audiences->pluck('audience_type');
        $courseIds = $event->audiences->where('audience_type', 'course')->pluck('course_id')->filter();
        $yearLevels = $event->audiences->where('audience_type', 'year_level')->pluck('year_level')->filter();
        $sectionIds = $event->audiences->where('audience_type', 'section')->pluck('section_id')->filter();

        return Student::query()->whereHas('user', fn (Builder $query) => $query->where('status', 'active'))
            ->where(function (Builder $query) use ($types, $courseIds, $yearLevels, $sectionIds): void {
                $query->whereRaw('1 = 0');
                if ($types->intersect(['all_students', 'all_users'])->isNotEmpty()) {
                    $query->orWhereRaw('1 = 1');
                }
                if ($courseIds->isNotEmpty()) {
                    $query->orWhereIn('course_id', $courseIds);
                }
                if ($yearLevels->isNotEmpty() || $sectionIds->isNotEmpty()) {
                    $query->orWhereHas('enrollments', function (Builder $enrollments) use ($yearLevels, $sectionIds): void {
                        $enrollments->whereIn('status', ['enrolled', 'completed'])
                            ->whereHas('academicYear', fn (Builder $academicYear) => $academicYear->where('status', 'active'))
                            ->whereHas('semester', fn (Builder $semester) => $semester->where('status', 'active'))
                            ->whereHas('section', function (Builder $section) use ($yearLevels, $sectionIds): void {
                                $section->where(function (Builder $target) use ($yearLevels, $sectionIds): void {
                                    if ($yearLevels->isNotEmpty()) {
                                        $target->orWhereIn('year_level', $yearLevels);
                                    }
                                    if ($sectionIds->isNotEmpty()) {
                                        $target->orWhereIn('id', $sectionIds);
                                    }
                                });
                            });
                    });
                }
            });
    }

    public function isStudentEligible(Event $event, Student $student): bool
    {
        return $this->eligibleStudents($event)->whereKey($student->id)->exists();
    }
}
