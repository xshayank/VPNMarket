<?php

namespace Modules\Reseller\Models;

use App\Models\Plan;
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
}
