<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'expires_at',
        'payment_method', 'card_payment_receipt', 'nowpayments_payment_id',
        'config_details',
        'amount',
        'source',
        'promo_code_id',
        'discount_amount',
        'original_amount',
        'usage_bytes',
        'traffic_limit_bytes',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'usage_bytes' => 'integer',
        'traffic_limit_bytes' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function store(Plan $plan)
    {

        return view('payment.choose', ['plan' => $plan]);
    }

    /**
     * Check if this order's config can be extended (for normal users)
     * 
     * @return bool
     */
    public function canBeExtended(): bool
    {
        // Only paid orders can be extended
        if ($this->status !== 'paid' || !$this->plan_id) {
            return false;
        }

        // Check if expired or out of traffic
        $isExpiredOrNoTraffic = $this->expires_at <= now() || 
                                ($this->traffic_limit_bytes && $this->usage_bytes >= $this->traffic_limit_bytes);
        
        // Check if has 3 days or less remaining
        $hasThreeDaysOrLess = $this->expires_at <= now()->addDays(3);

        return $isExpiredOrNoTraffic || $hasThreeDaysOrLess;
    }

    /**
     * Check if this order is expired or has run out of traffic
     * 
     * @return bool
     */
    public function isExpiredOrNoTraffic(): bool
    {
        return $this->expires_at <= now() || 
               ($this->traffic_limit_bytes && $this->usage_bytes >= $this->traffic_limit_bytes);
    }
}
