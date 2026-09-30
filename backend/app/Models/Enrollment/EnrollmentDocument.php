<?php

namespace App\Models\Enrollment;

use Illuminate\Database\Eloquent\Model;

class EnrollmentDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['file_path'];
}
