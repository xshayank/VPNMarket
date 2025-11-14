<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\PaymentMethodConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function enableAllWalletMethods(): void
{
    Setting::setValue(PaymentMethodConfig::CARD_TO_CARD_SETTING_KEY, '1');

    Setting::setValue('starsefar_enabled', 'true');
    Setting::setValue('starsefar_api_key', 'test-api-key');
    Setting::setValue('starsefar_base_url', 'https://starsefar.test');
    Setting::setValue('starsefar_default_target_account', '@vpnmarket');

    Setting::setValue('payment.tetra98.enabled', '1');
    Setting::setValue('payment.tetra98.api_key', 'test-tetra-key');
    Setting::setValue('payment.tetra98.base_url', 'https://tetra98.test');
    Setting::setValue('payment.tetra98.callback_path', '/webhooks/tetra98/callback');
    Setting::setValue('payment.tetra98.min_amount', '20000');

    PaymentMethodConfig::clearCache();
}

it('renders all wallet charge method sections when methods are enabled', function () {
    $user = User::factory()->create();

    enableAllWalletMethods();

    $this->withoutVite();

    $response = $this->actingAs($user)->get(route('wallet.charge.form'));

    $response->assertOk();
    $response->assertSee('data-method="card"', false);
    $response->assertSee('data-method="starsefar"', false);
    $response->assertSee('data-method="tetra98"', false);
    $response->assertSee('id="payment-method-error"', false);
});

it('keeps fallback container available when no payment methods are enabled', function () {
    $user = User::factory()->create();

    Setting::setValue(PaymentMethodConfig::CARD_TO_CARD_SETTING_KEY, '0');
    Setting::setValue('starsefar_enabled', 'false');
    Setting::setValue('payment.tetra98.enabled', '0');
    Setting::setValue('payment.tetra98.api_key', '');

    PaymentMethodConfig::clearCache();

    $this->withoutVite();

    $response = $this->actingAs($user)->get(route('wallet.charge.form'));

    $response->assertOk();
    $response->assertSee('هیچ روش پرداختی فعال نیست', false);
    $response->assertSee('id="payment-method-error"', false);
});
