<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentDateCapacity extends Model
{
    protected $fillable = ['appointment_date', 'capacity', 'updated_by'];

    protected function casts(): array
    {
        return ['appointment_date' => 'date', 'capacity' => 'integer'];
    }
}
