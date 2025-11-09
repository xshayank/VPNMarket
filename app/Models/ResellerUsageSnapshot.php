<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerUsageSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'reseller_id',
        'total_bytes',
        'measured_at',
    ];

    protected $casts = [
        'total_bytes' => 'integer',
        'measured_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
