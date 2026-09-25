<?php

namespace App\Models\Admission;

use Illuminate\Database\Eloquent\Model;

class AdmissionExamSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['policy_snapshot' => 'array', 'started_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime', 'revision' => 'integer', 'attempt_number' => 'integer'];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function questions()
    {
        return $this->hasMany(AdmissionExamSessionQuestion::class, 'session_id')->orderBy('position');
    }

    public function result()
    {
        return $this->hasOne(AdmissionExamResult::class, 'session_id');
    }

    public function applicant()
    {
        return $this->belongsTo(AdmissionApplicant::class, 'applicant_id');
    }
}
