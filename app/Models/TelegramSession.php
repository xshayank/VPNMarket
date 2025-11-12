<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'state',
        'data',
        'last_activity_at',
    ];

    protected $casts = [
        'data' => 'array',
        'last_activity_at' => 'datetime',
    ];
}
