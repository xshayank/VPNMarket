<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'plan_id',
        'quantity',
        'unit_price',
        'total_price',
        'price_source',
        'delivery_mode',
        'status',
        'fulfilled_at',
        'artifacts',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'fulfilled_at' => 'datetime',
        'artifacts' => 'array',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isProvisioning(): bool
    {
        return $this->status === 'provisioning';
    }

    public function isFulfilled(): bool
    {
        return $this->status === 'fulfilled';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
