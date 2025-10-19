<?php

use App\Models\Reseller;
use App\Models\User;
use Modules\Ticketing\Models\Ticket;

test('reseller can visit tickets index page', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('reseller.tickets.index'));

    $response->assertStatus(200);
    $response->assertSee('تیکت‌های ریسلر', false);
});

test('reseller can create a new ticket', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('reseller.tickets.create'));

    $response->assertStatus(200);
    $response->assertSee('ارسال تیکت جدید', false);
});

test('reseller can submit a ticket and it has source reseller', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $response = $this->actingAs($user)->post(route('reseller.tickets.store'), [
        'subject' => 'Test Reseller Ticket',
        'message' => 'This is a test message from reseller',
        'priority' => 'medium',
    ]);

    $response->assertRedirect();
    
    $ticket = Ticket::where('user_id', $user->id)->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->source)->toBe('reseller');
    expect($ticket->subject)->toBe('Test Reseller Ticket');
    expect($ticket->status)->toBe('open');
});

test('reseller can view their own ticket', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Test Subject',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $response = $this->actingAs($user)->get(route('reseller.tickets.show', $ticket->id));

    $response->assertStatus(200);
    $response->assertSee('Test Subject', false);
});

test('reseller cannot view another users ticket', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    Reseller::factory()->create([
        'user_id' => $user1->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    Reseller::factory()->create([
        'user_id' => $user2->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $ticket = Ticket::create([
        'user_id' => $user2->id,
        'subject' => 'User 2 Ticket',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $response = $this->actingAs($user1)->get(route('reseller.tickets.show', $ticket->id));

    $response->assertForbidden();
});

test('reseller cannot view non-reseller source tickets', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Web Ticket',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'web',
    ]);

    $response = $this->actingAs($user)->get(route('reseller.tickets.show', $ticket->id));

    $response->assertForbidden();
});

test('non-reseller cannot access reseller tickets routes', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('reseller.tickets.index'));

    $response->assertForbidden();
});

test('reseller sees only their own tickets in index', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    
    Reseller::factory()->create([
        'user_id' => $user1->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    Reseller::factory()->create([
        'user_id' => $user2->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    // Create ticket for user1
    Ticket::create([
        'user_id' => $user1->id,
        'subject' => 'User 1 Ticket',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    // Create ticket for user2
    Ticket::create([
        'user_id' => $user2->id,
        'subject' => 'User 2 Ticket',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $response = $this->actingAs($user1)->get(route('reseller.tickets.index'));

    $response->assertStatus(200);
    $response->assertSee('User 1 Ticket', false);
    $response->assertDontSee('User 2 Ticket', false);
});

test('reseller navigation points to reseller tickets index', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
    // Check that the navigation includes the reseller tickets route
    $response->assertSee(route('reseller.tickets.index'), false);
});
