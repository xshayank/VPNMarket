<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerConfigEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_config_id',
        'type',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(ResellerConfig::class, 'reseller_config_id');
    }
}
