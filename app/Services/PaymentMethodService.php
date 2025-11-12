<?php

namespace App\Services;

use App\Support\PaymentMethodConfig;
use App\Support\StarsefarConfig;

class PaymentMethodService
{
    /**
     * Get list of enabled payment methods
     *
     * @return array<string, array>
     */
    public function getEnabledMethods(): array
    {
        $methods = [];

        // Check if Card-to-Card is enabled
        if (PaymentMethodConfig::cardToCardEnabled()) {
            $methods['card_to_card'] = [
                'id' => 'card_to_card',
                'name' => 'کارت به کارت',
                'type' => 'manual',
                'requires_proof' => true,
                'description' => 'واریز به کارت بانکی و ارسال رسید',
            ];
        }

        // Check if StarsEfar is enabled
        if (StarsefarConfig::isEnabled()) {
            $methods['starsefar'] = [
                'id' => 'starsefar',
                'name' => 'استارز ایفار',
                'type' => 'gateway',
                'requires_proof' => false,
                'description' => 'پرداخت آنلاین با درگاه استارز ایفار',
                'min_amount' => StarsefarConfig::getMinAmountToman(),
            ];
        }

        return $methods;
    }

    /**
     * Check if a specific payment method is enabled
     */
    public function isMethodEnabled(string $methodId): bool
    {
        $methods = $this->getEnabledMethods();

        return isset($methods[$methodId]);
    }

    /**
     * Get payment method details
     */
    public function getMethodDetails(string $methodId): ?array
    {
        $methods = $this->getEnabledMethods();

        return $methods[$methodId] ?? null;
    }
}
