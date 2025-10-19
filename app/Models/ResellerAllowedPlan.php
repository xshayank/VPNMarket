<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerAllowedPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'plan_id',
        'override_type',
        'override_value',
        'active',
    ];

    protected $casts = [
        'override_value' => 'decimal:2',
        'active' => 'boolean',
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
