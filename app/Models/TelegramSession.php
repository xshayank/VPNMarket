<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSession extends Model
{
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

    /**
     * Get session data value by key
     */
    public function getData(string $key, $default = null)
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Set session data value by key
     */
    public function setData(string $key, $value): void
    {
        $data = $this->data ?? [];
        data_set($data, $key, $value);
        $this->data = $data;
    }

    /**
     * Update last activity timestamp
     */
    public function touch(): void
    {
        $this->last_activity_at = now();
        $this->save();
    }
}
