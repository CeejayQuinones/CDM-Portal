<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentWindowDefault extends Model
{
    protected $fillable = ['service_window', 'capacity', 'updated_by'];
}
