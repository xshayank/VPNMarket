<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    public function inbounds()
    {
        return $this->hasMany(\App\Models\Inbound::class);
    }

    /**
     * Get a setting value by key (alias for getValue)
     */
    public static function get(string $key, $default = null)
    {
        return self::getValue($key, $default);
    }

    /**
     * Get a setting value by key
     */
    public static function getValue(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by key
     */
    public static function setValue(string $key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Get a boolean setting value
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::getValue($key);
        if ($value === null) {
            return $default;
        }
        return $value === 'true' || $value === '1' || $value === true;
    }

    /**
     * Get an integer setting value
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::getValue($key);
        return $value !== null ? (int) $value : $default;
    }
}
