<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\PaymentGatewayTransaction;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StarsefarWalletReactivationTest extends TestCase
{
    use RefreshDatabase;

    protected function enableGateway(): void
    {
        Setting::setValue('starsefar_enabled', 'true');
        Setting::setValue('starsefar_api_key', 'test-api-key');
        Setting::setValue('starsefar_base_url', 'https://starsefar.xyz');
        Setting::setValue('starsefar_default_target_account', '@xShayank');
    }

    public function test_starsefar_payment_credits_wallet_reseller_balance(): void
    {
        $this->enableGateway();

        $user = User::factory()->create(['balance' => 0]);
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => -500,
            'status' => 'active',
        ]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_wallet_reseller',
            'user_id' => $user->id,
            'amount_toman' => 10000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        $response = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_wallet_reseller',
            'status' => 'completed',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('payment_gateway_transactions', [
            'id' => $transaction->id,
            'status' => PaymentGatewayTransaction::STATUS_PAID,
        ]);

        // Wallet transaction should be created
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'amount' => 10000,
            'type' => 'deposit',
            'status' => 'completed',
        ]);

        // Reseller wallet balance should be credited
        $this->assertEquals(9500, $reseller->fresh()->wallet_balance);
        
        // User balance should NOT be credited (only reseller wallet)
        $this->assertEquals(0, $user->fresh()->balance);
    }

    public function test_starsefar_payment_reactivates_suspended_wallet_reseller(): void
    {
        $this->enableGateway();

        $user = User::factory()->create(['balance' => 0]);
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => -1500, // Below suspension threshold
            'status' => 'suspended_wallet',
        ]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_reactivate',
            'user_id' => $user->id,
            'amount_toman' => 5000, // Enough to exceed threshold
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        $response = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_reactivate',
            'status' => 'completed',
        ]);

        $response->assertOk();

        // Reseller should be reactivated
        $this->assertEquals('active', $reseller->fresh()->status);
        $this->assertEquals(3500, $reseller->fresh()->wallet_balance);
    }

    public function test_starsefar_payment_does_not_reactivate_if_below_threshold(): void
    {
        $this->enableGateway();

        $user = User::factory()->create(['balance' => 0]);
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => -1500, // Below suspension threshold
            'status' => 'suspended_wallet',
        ]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_still_suspended',
            'user_id' => $user->id,
            'amount_toman' => 200, // Not enough to exceed threshold
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        $response = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_still_suspended',
            'status' => 'completed',
        ]);

        $response->assertOk();

        // Reseller should still be suspended
        $this->assertEquals('suspended_wallet', $reseller->fresh()->status);
        $this->assertEquals(-1300, $reseller->fresh()->wallet_balance);
    }

    public function test_starsefar_payment_reenables_wallet_suspended_configs(): void
    {
        $this->enableGateway();

        $user = User::factory()->create(['balance' => 0]);
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => -1500,
            'status' => 'suspended_wallet',
        ]);

        $panel = Panel::factory()->create([
            'panel_type' => 'marzban',
            'name' => 'Test Panel',
            'url' => 'https://panel.test',
        ]);

        // Create disabled config with wallet suspension marker
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user',
            'status' => 'disabled',
            'meta' => [
                'disabled_by_wallet_suspension' => true,
                'disabled_at' => now()->toISOString(),
            ],
        ]);

        // Mock the panel API to return success for enable
        Http::fake([
            'https://panel.test/*' => Http::response([
                'success' => true,
                'access_token' => 'fake-token',
            ], 200),
        ]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_reenable_configs',
            'user_id' => $user->id,
            'amount_toman' => 5000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        $response = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_reenable_configs',
            'status' => 'completed',
        ]);

        $response->assertOk();

        // Config should be re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);
        $this->assertArrayNotHasKey('disabled_by_wallet_suspension', $config->meta ?? []);
    }

    public function test_starsefar_payment_idempotency(): void
    {
        $this->enableGateway();

        $user = User::factory()->create(['balance' => 0]);
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => 0,
            'status' => 'active',
        ]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_idempotent',
            'user_id' => $user->id,
            'amount_toman' => 10000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        // First webhook call
        $response1 = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_idempotent',
            'status' => 'completed',
        ]);

        $response1->assertOk();

        $balanceAfterFirst = $reseller->fresh()->wallet_balance;
        $this->assertEquals(10000, $balanceAfterFirst);

        // Second webhook call (duplicate)
        $response2 = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_idempotent',
            'status' => 'completed',
        ]);

        $response2->assertOk();

        // Balance should not change
        $this->assertEquals($balanceAfterFirst, $reseller->fresh()->wallet_balance);

        // Only one wallet transaction should exist
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_starsefar_payment_logs_structured_events(): void
    {
        $this->enableGateway();

        Log::shouldReceive('info')
            ->with('StarsEfar payment verified', \Mockery::on(function ($context) {
                return $context['action'] === 'starsefar_payment_verified';
            }))
            ->once();

        Log::shouldReceive('info')
            ->with('StarsEfar wallet credited', \Mockery::on(function ($context) {
                return $context['action'] === 'starsefar_wallet_credited';
            }))
            ->once();

        Log::shouldReceive('info')
            ->with('Wallet credited for reseller', \Mockery::any())
            ->once();

        Log::shouldReceive('info')
            ->with('StarsEfar payment triggers reseller auto-reactivation', \Mockery::on(function ($context) {
                return $context['action'] === 'starsefar_reseller_reactivation_start';
            }))
            ->once();

        Log::shouldReceive('info')
            ->with(\Mockery::pattern('/Re-enabling.*wallet-suspended configs/'), \Mockery::any())
            ->once();

        Log::shouldReceive('info')
            ->with('StarsEfar payment reseller reactivation completed', \Mockery::on(function ($context) {
                return $context['action'] === 'starsefar_reseller_reactivation_complete';
            }))
            ->once();

        Log::shouldReceive('info')
            ->with(\Mockery::pattern('/Wallet config re-enable completed/'), \Mockery::any())
            ->once();

        $user = User::factory()->create(['balance' => 0]);
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'wallet',
            'wallet_balance' => -1500,
            'status' => 'suspended_wallet',
        ]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_logs',
            'user_id' => $user->id,
            'amount_toman' => 5000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_logs',
            'status' => 'completed',
        ]);
    }

    public function test_starsefar_payment_normal_user_uses_user_balance(): void
    {
        $this->enableGateway();

        // Normal user without reseller account
        $user = User::factory()->create(['balance' => 0]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_normal_user',
            'user_id' => $user->id,
            'amount_toman' => 8000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        $response = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_normal_user',
            'status' => 'completed',
        ]);

        $response->assertOk();

        // User balance should be credited
        $this->assertEquals(8000, $user->fresh()->balance);
    }
}
