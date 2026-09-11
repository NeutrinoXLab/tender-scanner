<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotice extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'notice_id', 'is_saved', 'is_ignored', 'personal_status', 'personal_notes'];

    protected $attributes = ['is_saved' => false, 'is_ignored' => false, 'personal_status' => 'new'];

    protected $casts = ['is_saved' => 'boolean', 'is_ignored' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
