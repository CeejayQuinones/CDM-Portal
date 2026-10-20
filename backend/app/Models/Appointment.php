<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = ['student_id', 'document_request_id', 'registrar_staff_id', 'appointment_date', 'appointment_time', 'purpose', 'status', 'remarks', 'active_slot_key', 'completed_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['appointment_date' => 'date', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function registrarStaff(): BelongsTo
    {
        return $this->belongsTo(RegistrarStaff::class);
    }
}
