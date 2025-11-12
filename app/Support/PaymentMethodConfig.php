<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Support\StarsefarConfig;

/**
 * Central configuration helper for payment methods.
 */
class PaymentMethodConfig
{
    public const CARD_TO_CARD_SETTING_KEY = 'payment_card_to_card_enabled';
    protected const CACHE_KEY_CARD_TO_CARD = 'payment_methods.card_to_card.enabled';

    public static function cardToCardEnabled(): bool
    {
        return Cache::rememberForever(self::CACHE_KEY_CARD_TO_CARD, function () {
            if (! Schema::hasTable('settings')) {
                return true;
            }

            $value = Setting::getValue(self::CARD_TO_CARD_SETTING_KEY);

            if ($value === null) {
                return true;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        });
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_CARD_TO_CARD);
    }

    /**
     * @return array<string>
     */
    public static function availableWalletChargeMethods(): array
    {
        $methods = [];

        if (self::cardToCardEnabled()) {
            $methods[] = 'card';
        }

        if (StarsefarConfig::isEnabled()) {
            $methods[] = 'starsefar';
        }

        return $methods;
    }
}
