<?php

namespace Modules\Reseller\Models;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reseller extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'username_prefix',
        'traffic_total_bytes',
        'traffic_used_bytes',
        'window_starts_at',
        'window_ends_at',
        'marzneshin_allowed_service_ids',
        'settings',
    ];

    protected $casts = [
        'marzneshin_allowed_service_ids' => 'array',
        'settings' => 'array',
        'window_starts_at' => 'datetime',
        'window_ends_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allowedPlans(): HasMany
    {
        return $this->hasMany(ResellerAllowedPlan::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ResellerOrder::class);
    }

    public function configs(): HasMany
    {
        return $this->hasMany(ResellerConfig::class);
    }

    public function getEffectiveUsernamePrefix(): string
    {
        if ($this->username_prefix) {
            return $this->username_prefix;
        }

        return config('reseller.username_prefix', 'resell');
    }

    public function resolvePlanPrice(Plan $plan): ?array
    {
        if (!$plan->reseller_visible) {
            return null;
        }

        $allowed = $this->allowedPlans
            ->firstWhere('plan_id', $plan->getKey());

        if ($allowed && !$allowed->active) {
            return null;
        }

        $planPrice = $plan->price;
        $price = null;
        $source = null;

        if ($allowed && $allowed->override_type === 'percent' && $allowed->override_value !== null) {
            $price = round($planPrice * (1 - ($allowed->override_value / 100)), 2);
            $source = 'override_percent';
        } elseif ($allowed && $allowed->override_type === 'price' && $allowed->override_value !== null) {
            $price = (float) $allowed->override_value;
            $source = 'override_price';
        } elseif ($plan->reseller_discount_percent !== null) {
            $price = round($planPrice * (1 - ($plan->reseller_discount_percent / 100)), 2);
            $source = 'plan_percent';
        } elseif ($plan->reseller_price !== null) {
            $price = (float) $plan->reseller_price;
            $source = 'plan_price';
        }

        if ($price === null) {
            return null;
        }

        return [
            'price' => $price,
            'source' => $source,
        ];
    }
}
