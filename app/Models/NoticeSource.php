<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoticeSource extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'normalized_data' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
