<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoticeRevision extends Model
{
    protected $guarded = [];

    protected $casts = ['before_data' => 'array', 'after_data' => 'array', 'changed_fields' => 'array'];
}
