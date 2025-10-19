<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'currency',
        'features',
        'is_popular',
        'is_active',
        'volume_gb',
        'duration_days',
        'panel_id',
        'marzneshin_service_ids',
        'reseller_visible',
        'reseller_price',
        'reseller_discount_percent',
    ];

    protected $casts = [
        'marzneshin_service_ids' => 'array',
        'reseller_visible' => 'boolean',
        'reseller_price' => 'float',
        'reseller_discount_percent' => 'float',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function panel()
    {
        return $this->belongsTo(Panel::class);
    }
}
