<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StarsefarControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function enableGateway(): void
    {
        Setting::setValue('starsefar_enabled', 'true');
        Setting::setValue('starsefar_api_key', 'test-api-key');
        Setting::setValue('starsefar_base_url', 'https://starsefar.xyz');
    }

    public function test_initiate_requires_gateway_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('wallet.charge.starsefar.initiate'), [
            'amount' => 30000,
        ]);

        $response->assertForbidden();
    }

    public function test_initiate_creates_transaction_and_returns_link(): void
    {
        $this->enableGateway();

        $user = User::factory()->create();

        Http::fake([
            'https://starsefar.xyz/api/create-gift-link' => Http::response([
                'success' => true,
                'link' => 'https://starsefar.xyz/?order_id=gift_test',
                'orderId' => 'gift_test',
            ], 201),
        ]);

        $response = $this->actingAs($user)->postJson(route('wallet.charge.starsefar.initiate'), [
            'amount' => 30000,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'orderId',
                'link',
                'statusEndpoint',
            ]);

        $this->assertDatabaseHas('payment_gateway_transactions', [
            'order_id' => 'gift_test',
            'user_id' => $user->id,
            'amount_toman' => 30000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);
    }

    public function test_status_endpoint_updates_to_paid_when_remote_paid(): void
    {
        $this->enableGateway();

        $user = User::factory()->create(['balance' => 0]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_status',
            'user_id' => $user->id,
            'amount_toman' => 50000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        Http::fake([
            'https://starsefar.xyz/api/check-order/*' => Http::response([
                'success' => true,
                'data' => [
                    'orderId' => 'gift_status',
                    'paid' => true,
                    'status' => 'paid',
                ],
            ]),
        ]);

        $response = $this->actingAs($user)->getJson(route('wallet.charge.starsefar.status', ['orderId' => 'gift_status']));

        $response->assertOk()->assertJson(['status' => PaymentGatewayTransaction::STATUS_PAID]);

        $this->assertDatabaseHas('payment_gateway_transactions', [
            'id' => $transaction->id,
            'status' => PaymentGatewayTransaction::STATUS_PAID,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'deposit',
            'status' => 'completed',
        ]);

        $this->assertEquals(50000, $user->fresh()->balance);
    }

    public function test_webhook_marks_transaction_paid(): void
    {
        $this->enableGateway();

        $user = User::factory()->create(['balance' => 0]);

        $transaction = PaymentGatewayTransaction::create([
            'provider' => 'starsefar',
            'order_id' => 'gift_webhook',
            'user_id' => $user->id,
            'amount_toman' => 70000,
            'status' => PaymentGatewayTransaction::STATUS_PENDING,
        ]);

        $response = $this->postJson(route('webhooks.starsefar'), [
            'success' => true,
            'orderId' => 'gift_webhook',
            'status' => 'completed',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('payment_gateway_transactions', [
            'id' => $transaction->id,
            'status' => PaymentGatewayTransaction::STATUS_PAID,
        ]);

        $this->assertEquals(70000, $user->fresh()->balance);
    }
}
