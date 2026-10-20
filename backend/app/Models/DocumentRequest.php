<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentRequest extends Model
{
    protected $hidden = ['verification_code_lookup', 'verification_code_hash'];

    protected $fillable = ['student_id', 'document_type_id', 'registrar_staff_id', 'quantity', 'total_fee', 'purpose', 'status', 'request_date', 'release_date', 'remarks', 'cancellation_reason', 'cancelled_at', 'approved_at', 'completed_at', 'rejected_at', 'verification_code_lookup', 'verification_code_hash', 'code_verified_at'];

    protected $appends = ['request_reference'];

    protected function casts(): array
    {
        return ['request_date' => 'date', 'release_date' => 'date', 'cancelled_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime', 'rejected_at' => 'datetime', 'code_verified_at' => 'datetime'];
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

    public function activeAppointment(): HasOne
    {
        return $this->hasOne(Appointment::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereIn('status', ['pending', 'confirmed']),
        );
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(DocumentRequestStatusChange::class)->latest('created_at')->latest('id');
    }

    protected function requestReference(): Attribute
    {
        return Attribute::get(fn (): string => sprintf('REQ-%06d', $this->getKey()));
    }
}
