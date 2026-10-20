<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentRecordStaffPreference extends Model
{
    protected $fillable = ['user_id', 'visible_columns'];

    protected function casts(): array
    {
        return ['visible_columns' => 'array'];
    }
}
