<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentRequest extends Model
{
    protected $fillable = ['student_id', 'document_type_id', 'registrar_staff_id', 'quantity', 'total_fee', 'purpose', 'status', 'request_date', 'release_date', 'remarks', 'approved_at', 'processed_at', 'ready_for_release_at', 'released_at', 'rejected_at'];

    protected function casts(): array
    {
        return ['request_date' => 'date', 'release_date' => 'date', 'approved_at' => 'datetime', 'processed_at' => 'datetime', 'ready_for_release_at' => 'datetime', 'released_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function registrarStaff(): BelongsTo
    {
        return $this->belongsTo(RegistrarStaff::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function latestAppointment(): HasOne
    {
        return $this->hasOne(Appointment::class)->latestOfMany();
    }
}
