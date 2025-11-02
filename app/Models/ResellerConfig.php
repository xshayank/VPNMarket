<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResellerConfig extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reseller_id',
        'external_username',
        'comment',
        'traffic_limit_bytes',
        'usage_bytes',
        'expires_at',
        'status',
        'panel_type',
        'panel_user_id',
        'subscription_url',
        'panel_id',
        'created_by',
        'disabled_at',
    ];

    protected $casts = [
        'traffic_limit_bytes' => 'integer',
        'usage_bytes' => 'integer',
        'expires_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ResellerConfigEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isDeleted(): bool
    {
        return $this->status === 'deleted';
    }

    public function hasTrafficRemaining(): bool
    {
        return $this->usage_bytes < $this->traffic_limit_bytes;
    }

    public function isExpiredByTime(): bool
    {
        return now() >= $this->expires_at;
    }
}
