<?php

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PaymentMethodConfig;
use App\Support\Tetra98Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    PaymentMethodConfig::clearCache();
    Tetra98Config::clearCache();
});

function enableTetra98(): void
{
    Setting::updateOrCreate(['key' => 'payment.tetra98.enabled'], ['value' => '1']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.api_key'], ['value' => 'test-api-key']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.base_url'], ['value' => 'https://tetra98.ir']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.callback_path'], ['value' => '/webhooks/tetra98/callback']);
    Setting::updateOrCreate(['key' => 'payment.tetra98.min_amount'], ['value' => '10000']);
    PaymentMethodConfig::clearCache();
    Tetra98Config::clearCache();
}

it('exposes tetra98 method when enabled and configured', function () {
    enableTetra98();

    expect(PaymentMethodConfig::availableWalletChargeMethods())->toContain('tetra98');
});

it('denies tetra98 initiation when disabled', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('wallet.charge.tetra98.initiate'), [
            'amount' => 15000,
            'phone' => '09123456789',
        ])
        ->assertForbidden();
});

it('requires valid phone number for tetra98 initiation', function () {
    enableTetra98();
    $user = User::factory()->create();

    actingAs($user)
        ->from(route('wallet.charge.form'))
        ->post(route('wallet.charge.tetra98.initiate'), [
            'amount' => 15000,
            'phone' => '12345',
            'tetra98_context' => '1',
        ])
        ->assertSessionHasErrors(['phone']);
});

it('creates pending transaction and redirects to tetra98 payment page', function () {
    enableTetra98();
    $user = User::factory()->create();

    Http::fake([
        'https://tetra98.ir/api/create_order' => Http::response([
            'status' => '100',
            'Authority' => 'AUTH123',
            'payment_url_web' => 'https://tetra98.ir/payment/AUTH123',
            'payment_url_bot' => 'https://t.me/Tetra98_bot?start=pay_AUTH123',
            'tracking_id' => 'TRACK123',
        ], 200),
    ]);

    actingAs($user)
        ->post(route('wallet.charge.tetra98.initiate'), [
            'amount' => 16000,
            'phone' => '09123456789',
            'tetra98_context' => '1',
        ])
        ->assertRedirect('https://tetra98.ir/payment/AUTH123');

    $transaction = Transaction::latest()->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->status)->toBe(Transaction::STATUS_PENDING);
    expect($transaction->type)->toBe(Transaction::TYPE_DEPOSIT);
    expect($transaction->metadata['tetra98']['authority'] ?? null)->toBe('AUTH123');
    expect($transaction->metadata['tetra98']['hash_id'] ?? null)->not->toBeNull();
    expect($transaction->metadata['phone'])->toBe('09123456789');
});

it('processes successful tetra98 callback and credits wallet', function () {
    enableTetra98();
    $user = User::factory()->create(['balance' => 0]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 18000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'phone' => '09123456789',
            'email' => $user->email,
            'tetra98' => [
                'hash_id' => 'tetra98-'.$user->id.'-'.Str::uuid()->toString(),
                'amount_toman' => 18000,
                'state' => 'created',
            ],
        ],
    ]);

    $hashId = $transaction->metadata['tetra98']['hash_id'];

    Http::fake([
        'https://tetra98.ir/api/verify' => Http::response([
            'status' => '100',
            'authority' => 'AUTHXYZ',
        ], 200),
    ]);

    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'hashid' => $hashId,
        'authority' => 'AUTHXYZ',
    ])->assertOk();

    $transaction->refresh();
    $user->refresh();

    expect($transaction->status)->toBe(Transaction::STATUS_COMPLETED);
    expect($transaction->description)->toBe('شارژ کیف پول (درگاه Tetra98)');
    expect($transaction->metadata['tetra98']['state'] ?? null)->toBe('completed');
    expect($transaction->metadata['tetra98']['verification_status'] ?? null)->toBe('success');
    expect($user->balance)->toBe(18000);

    // Idempotent callback should not double credit
    post(Tetra98Config::getCallbackPath(), [
        'status' => 100,
        'hashid' => $hashId,
        'authority' => 'AUTHXYZ',
    ])->assertOk();

    $user->refresh();
    expect($user->balance)->toBe(18000);
});

it('marks transaction failed when callback reports failure', function () {
    enableTetra98();
    $user = User::factory()->create();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'order_id' => null,
        'amount' => 20000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول (درگاه Tetra98) - در انتظار پرداخت',
        'metadata' => [
            'payment_method' => 'tetra98',
            'phone' => '09123456789',
            'email' => $user->email,
            'tetra98' => [
                'hash_id' => 'tetra-fail-'.$user->id,
                'amount_toman' => 20000,
                'state' => 'created',
            ],
        ],
    ]);

    post(Tetra98Config::getCallbackPath(), [
        'status' => 0,
        'hashid' => 'tetra-fail-'.$user->id,
        'authority' => 'AUTHFAIL',
    ])->assertOk();

    $transaction->refresh();

    expect($transaction->status)->toBe(Transaction::STATUS_FAILED);
    expect($transaction->metadata['tetra98']['state'] ?? null)->toBe('failed');
});
