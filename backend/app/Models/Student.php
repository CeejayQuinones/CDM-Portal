<?php

namespace App\Models;

use App\Models\Enrollment\EnrollmentApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_profile_id',
        'course_id',
        'curriculum_id',
        'student_number',
        'admission_date',
        'year_level',
        'student_status',
    ];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'year_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** The student's most recent academic context, if one exists. */
    public function latestEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)->latestOfMany();
    }

    /** Enrollment selected by StudentService's term-aware ranking subquery. */
    public function currentEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'current_enrollment_id');
    }

    /** Assigned application selected by StudentService's term-aware ranking. */
    public function currentEnrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class, 'current_enrollment_application_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function riskNotifications(): HasMany
    {
        return $this->hasMany(RiskNotification::class);
    }

    public function physicalRecordLocation(): HasOne
    {
        return $this->hasOne(StudentRecordLocation::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function enrollmentApplications(): HasMany
    {
        return $this->hasMany(EnrollmentApplication::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(StudentSetting::class);
    }

    public function eventAttendances(): HasMany
    {
        return $this->hasMany(EventAttendance::class);
    }
}
