<?php

namespace App\Models\Admission;

use Illuminate\Database\Eloquent\Model;

class AdmissionExamResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['category_scores' => 'array', 'category_maximums' => 'array', 'system_passed' => 'boolean', 'registrar_pass' => 'boolean', 'official_score' => 'integer', 'version' => 'integer', 'finalized_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    public function session()
    {
        return $this->belongsTo(AdmissionExamSession::class, 'session_id');
    }

    public function outcome(): string
    {
        if ($this->official_status !== 'published') {
            return strtoupper($this->official_status);
        }
        if ($this->registrar_pass || $this->official_score >= 75) {
            return 'PASSED';
        }

        return $this->session->attempt_number >= 2 ? 'FAILED' : 'RETAKE';
    }
}
