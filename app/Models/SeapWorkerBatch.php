<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeapWorkerBatch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['request' => 'array', 'receipt' => 'array'];
}
