<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyMigrationRow extends Model
{
    protected $fillable = ['batch_id', 'source_row', 'source_key_sha256', 'payload', 'status', 'reason_code', 'resolved_refs'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'resolved_refs' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LegacyMigrationBatch::class, 'batch_id');
    }
}
