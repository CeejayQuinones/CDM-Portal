<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistrarStaff extends Model
{
    protected $table = 'registrar_staff';

    protected $fillable = ['user_id', 'user_profile_id', 'employee_number', 'position', 'employment_status', 'status'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function handledRequests(): HasMany { return $this->hasMany(DocumentRequest::class); }
    public function appointments(): HasMany { return $this->hasMany(Appointment::class); }
}
