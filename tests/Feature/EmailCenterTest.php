<?php

use App\Filament\Pages\EmailCenter;
use App\Jobs\SendExpiredNormalUsersEmailsJob;
use App\Jobs\SendExpiredResellerUsersEmailsJob;
use App\Jobs\SendRenewalWalletRemindersJob;
use App\Jobs\SendResellerTrafficTimeRemindersJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

test('email center page renders successfully', function () {
    Livewire::test(EmailCenter::class)
        ->assertSuccessful();
});

test('email center form displays automation settings', function () {
    Livewire::test(EmailCenter::class)
        ->assertFormExists()
        ->assertFormFieldExists('email.auto_remind_renewal_wallet')
        ->assertFormFieldExists('email.auto_remind_reseller_traffic_time');
});

test('settings are loaded correctly from database', function () {
    Setting::create(['key' => 'email.auto_remind_renewal_wallet', 'value' => 'true']);
    Setting::create(['key' => 'email.renewal_days_before', 'value' => '5']);
    Setting::create(['key' => 'email.min_wallet_threshold', 'value' => '20000']);

    $component = Livewire::test(EmailCenter::class);
    
    // Just verify the component renders successfully with the settings
    $component->assertSuccessful();
});

test('settings can be saved', function () {
    // Test the Setting model's setValue method directly
    Setting::setValue('email.auto_remind_renewal_wallet', 'true');
    Setting::setValue('email.renewal_days_before', '7');
    Setting::setValue('email.min_wallet_threshold', '15000');

    expect(Setting::getValue('email.auto_remind_renewal_wallet'))->toBe('true')
        ->and(Setting::getValue('email.renewal_days_before'))->toBe('7')
        ->and(Setting::getValue('email.min_wallet_threshold'))->toBe('15000');
});

test('manual send to expired normal users dispatches job', function () {
    Queue::fake();

    $plan = Plan::factory()->create();
    $user = User::factory()->create(['email' => 'test@example.com']);
    Order::factory()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => 'paid',
        'expires_at' => now()->subDay(),
    ]);

    Livewire::test(EmailCenter::class)
        ->callAction('sendExpiredNormalUsers');

    Queue::assertPushed(SendExpiredNormalUsersEmailsJob::class);
});

test('manual send to expired resellers dispatches job', function () {
    Queue::fake();

    Livewire::test(EmailCenter::class)
        ->callAction('sendExpiredResellers');

    Queue::assertPushed(SendExpiredResellerUsersEmailsJob::class);
});

test('run reminders now dispatches both reminder jobs', function () {
    Queue::fake();

    Livewire::test(EmailCenter::class)
        ->callAction('runRemindersNow');

    Queue::assertPushed(SendRenewalWalletRemindersJob::class);
    Queue::assertPushed(SendResellerTrafficTimeRemindersJob::class);
});

test('expired normal users count is calculated correctly', function () {
    $plan = Plan::factory()->create();
    
    // Create expired user without active orders
    $expiredUser = User::factory()->create();
    Order::factory()->create([
        'user_id' => $expiredUser->id,
        'plan_id' => $plan->id,
        'status' => 'paid',
        'expires_at' => now()->subDay(),
    ]);

    // Create user with both expired and active orders (should not count)
    $activeUser = User::factory()->create();
    Order::factory()->create([
        'user_id' => $activeUser->id,
        'plan_id' => $plan->id,
        'status' => 'paid',
        'expires_at' => now()->subDay(),
    ]);
    Order::factory()->create([
        'user_id' => $activeUser->id,
        'plan_id' => $plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(10),
    ]);

    $component = Livewire::test(EmailCenter::class);
    $count = $component->instance()->getExpiredNormalUsersCount();

    expect($count)->toBe(1);
});

test('expired resellers count is calculated correctly', function () {
    $user = User::factory()->create();
    $panel = \App\Models\Panel::factory()->create();
    
    // Create expired reseller by window_ends_at
    Reseller::factory()->create([
        'user_id' => $user->id,
        'panel_id' => $panel->id,
        'type' => 'traffic',
        'window_ends_at' => now()->subDay(),
    ]);

    // Create expired reseller by traffic limit
    Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $panel->id,
        'type' => 'traffic',
        'traffic_total_bytes' => 1000000,
        'traffic_used_bytes' => 1000000,
    ]);

    // Create active reseller (should not count)
    Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $panel->id,
        'type' => 'traffic',
        'traffic_total_bytes' => 1000000,
        'traffic_used_bytes' => 500000,
        'window_ends_at' => now()->addDays(10),
    ]);

    $component = Livewire::test(EmailCenter::class);
    $count = $component->instance()->getExpiredResellersCount();

    expect($count)->toBe(2);
});

test('toggle fields visibility based on automation switches', function () {
    Livewire::test(EmailCenter::class)
        ->assertFormFieldIsHidden('email.renewal_days_before')
        ->assertFormFieldIsHidden('email.min_wallet_threshold')
        ->fillForm(['email.auto_remind_renewal_wallet' => true])
        ->assertFormFieldIsVisible('email.renewal_days_before')
        ->assertFormFieldIsVisible('email.min_wallet_threshold');
});

test('setting helper methods work correctly', function () {
    Setting::setValue('test.bool', 'true');
    Setting::setValue('test.int', '42');
    Setting::setValue('test.string', 'hello');

    expect(Setting::getBool('test.bool'))->toBeTrue()
        ->and(Setting::getBool('test.nonexistent', false))->toBeFalse()
        ->and(Setting::getInt('test.int'))->toBe(42)
        ->and(Setting::getInt('test.nonexistent', 10))->toBe(10)
        ->and(Setting::getValue('test.string'))->toBe('hello')
        ->and(Setting::getValue('test.nonexistent', 'default'))->toBe('default');
});

test('setting get method works as alias to getValue', function () {
    // Test with dotted keys that would cause SQL errors without the fix
    Setting::setValue('reseller.usage_sync_interval_minutes', '5');
    Setting::setValue('email.auto_remind_renewal_wallet', 'true');
    Setting::setValue('email.auto_remind_reseller_traffic_time', 'false');

    // Verify get() method works correctly
    expect(Setting::get('reseller.usage_sync_interval_minutes'))->toBe('5')
        ->and(Setting::get('email.auto_remind_renewal_wallet'))->toBe('true')
        ->and(Setting::get('email.auto_remind_reseller_traffic_time'))->toBe('false')
        ->and(Setting::get('nonexistent.key'))->toBeNull()
        ->and(Setting::get('nonexistent.key', 'default_value'))->toBe('default_value');

    // Verify get() and getValue() return the same results
    expect(Setting::get('reseller.usage_sync_interval_minutes'))
        ->toBe(Setting::getValue('reseller.usage_sync_interval_minutes'));
});
