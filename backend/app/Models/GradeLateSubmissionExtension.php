<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeLateSubmissionExtension extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function gradeSheet()
    {
        return $this->belongsTo(GradeSheet::class);
    }

    public function authorizedBy()
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
