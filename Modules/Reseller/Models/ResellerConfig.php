<?php

namespace Modules\Reseller\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResellerConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id',
        'external_username',
        'traffic_limit_bytes',
        'usage_bytes',
        'expires_at',
        'status',
        'panel_type',
        'panel_user_id',
        'created_by',
        'disabled_at',
        'deleted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'disabled_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ResellerConfigEvent::class);
    }
}
