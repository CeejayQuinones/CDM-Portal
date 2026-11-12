<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OvrfTemplateDraft extends Model
{
    protected $fillable = [
        'original_filename', 'private_path', 'sha256', 'size_bytes', 'page_count',
        'status', 'uploaded_by_user_id', 'archived_at', 'archived_by_user_id',
    ];

    protected $hidden = ['private_path'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OvrfTemplateDraftEvent::class);
    }
}
