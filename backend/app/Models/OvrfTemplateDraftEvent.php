<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvrfTemplateDraftEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['ovrf_template_draft_id', 'actor_user_id', 'action', 'created_at'];
}
