<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentWindowCapacity extends Model
{
    protected $fillable = ['appointment_date', 'service_window', 'capacity', 'updated_by'];
}
