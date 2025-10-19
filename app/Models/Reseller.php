<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reseller extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'username_prefix',
        'panel_id',
        'traffic_total_bytes',
        'traffic_used_bytes',
        'window_starts_at',
        'window_ends_at',
        'marzneshin_allowed_service_ids',
        'settings',
    ];

    protected $casts = [
        'traffic_total_bytes' => 'integer',
        'traffic_used_bytes' => 'integer',
        'window_starts_at' => 'datetime',
        'window_ends_at' => 'datetime',
        'marzneshin_allowed_service_ids' => 'array',
        'settings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }

    public function allowedPlans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'reseller_allowed_plans')
            ->withPivot('override_type', 'override_value', 'active')
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ResellerOrder::class);
    }

    public function configs(): HasMany
    {
        return $this->hasMany(ResellerConfig::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPlanBased(): bool
    {
        return $this->type === 'plan';
    }

    public function isTrafficBased(): bool
    {
        return $this->type === 'traffic';
    }

    public function hasTrafficRemaining(): bool
    {
        if (!$this->isTrafficBased()) {
            return false;
        }

        return $this->traffic_used_bytes < $this->traffic_total_bytes;
    }

    public function isWindowValid(): bool
    {
        if (!$this->isTrafficBased()) {
            return false;
        }

        $now = now();
        return $this->window_starts_at <= $now && $now <= $this->window_ends_at;
    }
}
