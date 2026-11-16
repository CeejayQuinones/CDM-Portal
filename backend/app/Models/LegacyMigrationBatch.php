<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegacyMigrationBatch extends Model
{
    protected $fillable = ['entity', 'source_sha256', 'operator_reference', 'row_count', 'status', 'approved_by', 'approved_at', 'approval_reference', 'backup_reference'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LegacyMigrationRow::class, 'batch_id');
    }
}
