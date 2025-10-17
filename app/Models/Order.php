<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'expires_at',
        'payment_method', 'card_payment_receipt', 'nowpayments_payment_id',
        'config_details',
        'amount',
        'source',
        'promo_code_id',
        'discount_amount',
        'original_amount',
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
}
