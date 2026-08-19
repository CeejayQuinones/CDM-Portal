<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StudentService
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Student::query()
            ->with(['user', 'userProfile', 'course', 'curriculum'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($studentQuery) use ($search): void {
                    $studentQuery->where('student_number', 'like', "%{$search}%")
                        ->orWhereHas('userProfile', fn ($profileQuery) => $profileQuery
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['course'] ?? null, function ($query, string $course): void {
                $query->where(function ($courseQuery) use ($course): void {
                    $courseQuery->where('course_id', $course)
                        ->orWhereHas('course', fn ($relationQuery) => $relationQuery->where('course_code', $course));
                });
            })
            ->when($filters['year_level'] ?? null, fn ($query, int $yearLevel) => $query->where('year_level', $yearLevel))
            ->when($filters['student_status'] ?? null, fn ($query, string $status) => $query->where('student_status', $status))
            ->orderBy('student_number')
            ->paginate(15)
            ->withQueryString();
    }

    public function find(int $studentId): Student
    {
        return Student::query()
            ->with([
                'user.role',
                'userProfile',
                'course.department',
                'curriculum',
                'latestEnrollment.academicYear',
                'latestEnrollment.semester',
            ])
            ->findOrFail($studentId);
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $studentId, array $attributes): Student
    {
        return DB::transaction(function () use ($studentId, $attributes): Student {
            $student = Student::query()->with('userProfile')->findOrFail($studentId);

            $student->update([
                'course_id' => $attributes['course_id'],
                'year_level' => $attributes['year_level'],
                'student_status' => $attributes['student_status'],
            ]);

            $student->userProfile->update([
                'first_name' => $attributes['first_name'],
                'middle_name' => $attributes['middle_name'] ?? null,
                'last_name' => $attributes['last_name'],
                'suffix' => $attributes['suffix'] ?? null,
                'birth_date' => $attributes['birth_date'] ?? null,
                'gender' => $attributes['gender'] ?? $student->userProfile->gender,
                'civil_status' => $attributes['civil_status'] ?? null,
                'nationality' => $attributes['nationality'] ?? $student->userProfile->nationality,
                'contact_number' => $attributes['contact_number'] ?? null,
                'address' => $attributes['address'] ?? null,
            ]);

            return $this->find($student->id);
        });
    }
}
