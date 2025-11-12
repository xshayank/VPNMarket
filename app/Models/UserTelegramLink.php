<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTelegramLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'chat_id',
        'username',
        'first_name',
        'last_name',
        'verified_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
