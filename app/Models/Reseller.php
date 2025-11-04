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
        'config_limit',
        'traffic_total_bytes',
        'traffic_used_bytes',
        'window_starts_at',
        'window_ends_at',
        'marzneshin_allowed_service_ids',
        'eylandoo_allowed_node_ids',
        'settings',
    ];

    protected $casts = [
        'config_limit' => 'integer',
        'traffic_total_bytes' => 'integer',
        'traffic_used_bytes' => 'integer',
        'window_starts_at' => 'datetime',
        'window_ends_at' => 'datetime',
        'marzneshin_allowed_service_ids' => 'array',
        'eylandoo_allowed_node_ids' => 'array',
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
        if (! $this->isTrafficBased()) {
            return false;
        }

        return $this->traffic_used_bytes < $this->traffic_total_bytes;
    }

    public function isWindowValid(): bool
    {
        if (! $this->isTrafficBased()) {
            return false;
        }

        // If window_ends_at is null, treat as unlimited (always valid)
        if (! $this->window_ends_at) {
            return true;
        }

        // Ensure window_starts_at is set
        if (! $this->window_starts_at) {
            return false;
        }

        $now = now();

        // Window is valid while now < window_ends_at (start of day)
        // i.e., a window ending on 2025-11-03 becomes invalid at 2025-11-03 00:00
        return $this->window_starts_at <= $now && $now < $this->window_ends_at->copy()->startOfDay();
    }

    /**
     * Get the base date for extending the window.
     * Returns the later of: current window_ends_at or now()
     */
    public function getExtendWindowBaseDate(): \Illuminate\Support\Carbon
    {
        $now = now();

        return $this->window_ends_at && $this->window_ends_at->gt($now)
            ? $this->window_ends_at
            : $now;
    }

    /**
     * Get a timezone-aware now instance
     */
    private function getAppTimezoneNow(): \Illuminate\Support\Carbon
    {
        return now()->timezone(config('app.timezone', 'Asia/Tehran'));
    }

    /**
     * Convert window_ends_at to app timezone
     */
    private function getWindowEndInAppTimezone(): ?\Illuminate\Support\Carbon
    {
        if (!$this->window_ends_at) {
            return null;
        }

        return $this->window_ends_at->copy()->timezone(config('app.timezone', 'Asia/Tehran'));
    }

    /**
     * Get time remaining in seconds, clamped to 0 when expired
     */
    public function getTimeRemainingSeconds(): int
    {
        $windowEnd = $this->getWindowEndInAppTimezone();
        if (!$windowEnd) {
            return 0;
        }

        $now = $this->getAppTimezoneNow();
        
        // If window_ends_at is in the past, return 0
        if ($windowEnd->lte($now)) {
            return 0;
        }

        return $now->diffInSeconds($windowEnd);
    }

    /**
     * Get time remaining in days, clamped to 0 when expired
     */
    public function getTimeRemainingDays(): int
    {
        $windowEnd = $this->getWindowEndInAppTimezone();
        if (!$windowEnd) {
            return 0;
        }

        $now = $this->getAppTimezoneNow();
        
        // If window_ends_at is in the past, return 0
        if ($windowEnd->lte($now)) {
            return 0;
        }

        return $now->diffInDays($windowEnd);
    }
}
