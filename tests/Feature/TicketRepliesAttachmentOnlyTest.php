<?php

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Ticketing\Models\Ticket;

beforeEach(function () {
    Storage::fake('public');
});

test('normal user can reply to ticket with only attachment', function () {
    $user = User::factory()->create();
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Test Subject',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'web',
    ]);

    $file = UploadedFile::fake()->image('screenshot.jpg');

    $response = $this->actingAs($user)->post(route('tickets.reply', $ticket->id), [
        'attachment' => $file,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'پاسخ شما با موفقیت ثبت شد.');

    // Check that reply was created with empty message
    $reply = $ticket->replies()->latest()->first();
    expect($reply)->not->toBeNull();
    expect($reply->message)->toBe('');
    expect($reply->attachment_path)->not->toBeNull();
    
    // Verify file was stored
    Storage::disk('public')->assertExists($reply->attachment_path);
});

test('normal user can reply to ticket with only message', function () {
    $user = User::factory()->create();
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Test Subject',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'web',
    ]);

    $response = $this->actingAs($user)->post(route('tickets.reply', $ticket->id), [
        'message' => 'This is my reply',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'پاسخ شما با موفقیت ثبت شد.');

    // Check that reply was created with message
    $reply = $ticket->replies()->latest()->first();
    expect($reply)->not->toBeNull();
    expect($reply->message)->toBe('This is my reply');
    expect($reply->attachment_path)->toBeNull();
});

test('normal user can reply to ticket with both message and attachment', function () {
    $user = User::factory()->create();
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Test Subject',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'web',
    ]);

    $file = UploadedFile::fake()->image('screenshot.jpg');

    $response = $this->actingAs($user)->post(route('tickets.reply', $ticket->id), [
        'message' => 'Here is my issue',
        'attachment' => $file,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'پاسخ شما با موفقیت ثبت شد.');

    // Check that reply was created with both
    $reply = $ticket->replies()->latest()->first();
    expect($reply)->not->toBeNull();
    expect($reply->message)->toBe('Here is my issue');
    expect($reply->attachment_path)->not->toBeNull();
    
    Storage::disk('public')->assertExists($reply->attachment_path);
});

test('normal user cannot reply with neither message nor attachment', function () {
    $user = User::factory()->create();
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Test Subject',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'web',
    ]);

    $response = $this->actingAs($user)->post(route('tickets.reply', $ticket->id), []);

    $response->assertSessionHasErrors('message');
    
    // No reply should be created
    expect($ticket->replies()->count())->toBe(0);
});

test('normal user reply with whitespace-only message and no attachment fails validation', function () {
    $user = User::factory()->create();
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Test Subject',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'web',
    ]);

    $response = $this->actingAs($user)->post(route('tickets.reply', $ticket->id), [
        'message' => '   ',
    ]);

    // This should redirect back with errors because trimmed message is empty and no attachment
    $response->assertRedirect();
    $response->assertSessionHasErrors('message');
});

test('reseller can reply to ticket with only attachment', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Reseller Test Subject',
        'message' => 'Reseller Test Message',
        'priority' => 'high',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $file = UploadedFile::fake()->create('logs.txt', 100);

    $response = $this->actingAs($user)->post(route('reseller.tickets.reply', $ticket->id), [
        'attachment' => $file,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'پاسخ شما با موفقیت ثبت شد.');

    // Check that reply was created with empty message
    $reply = $ticket->replies()->latest()->first();
    expect($reply)->not->toBeNull();
    expect($reply->message)->toBe('');
    expect($reply->attachment_path)->not->toBeNull();
    
    Storage::disk('public')->assertExists($reply->attachment_path);
});

test('reseller can reply to ticket with only message', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Reseller Test Subject',
        'message' => 'Reseller Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $response = $this->actingAs($user)->post(route('reseller.tickets.reply', $ticket->id), [
        'message' => 'This is my reseller reply',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'پاسخ شما با موفقیت ثبت شد.');

    // Check that reply was created with message
    $reply = $ticket->replies()->latest()->first();
    expect($reply)->not->toBeNull();
    expect($reply->message)->toBe('This is my reseller reply');
    expect($reply->attachment_path)->toBeNull();
});

test('reseller can reply to ticket with both message and attachment', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Reseller Test Subject',
        'message' => 'Reseller Test Message',
        'priority' => 'low',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $file = UploadedFile::fake()->create('document.pdf', 200);

    $response = $this->actingAs($user)->post(route('reseller.tickets.reply', $ticket->id), [
        'message' => 'Here is the documentation',
        'attachment' => $file,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'پاسخ شما با موفقیت ثبت شد.');

    // Check that reply was created with both
    $reply = $ticket->replies()->latest()->first();
    expect($reply)->not->toBeNull();
    expect($reply->message)->toBe('Here is the documentation');
    expect($reply->attachment_path)->not->toBeNull();
    
    Storage::disk('public')->assertExists($reply->attachment_path);
});

test('reseller cannot reply with neither message nor attachment', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'Reseller Test Subject',
        'message' => 'Reseller Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $response = $this->actingAs($user)->post(route('reseller.tickets.reply', $ticket->id), []);

    $response->assertSessionHasErrors('message');
    
    // No reply should be created
    expect($ticket->replies()->count())->toBe(0);
});

test('reseller reply accepts valid file types', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'File Type Test',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $fileTypes = [
        UploadedFile::fake()->image('image.jpg'),
        UploadedFile::fake()->image('image.jpeg'),
        UploadedFile::fake()->image('image.png'),
        UploadedFile::fake()->image('image.webp'),
        UploadedFile::fake()->create('document.pdf', 100),
        UploadedFile::fake()->create('notes.txt', 50),
        UploadedFile::fake()->create('debug.log', 75),
        UploadedFile::fake()->create('archive.zip', 200),
    ];

    foreach ($fileTypes as $file) {
        $response = $this->actingAs($user)->post(route('reseller.tickets.reply', $ticket->id), [
            'attachment' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }
});

test('reseller reply rejects invalid file types', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'File Type Test',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'reseller',
    ]);

    $invalidFile = UploadedFile::fake()->create('script.exe', 100);

    $response = $this->actingAs($user)->post(route('reseller.tickets.reply', $ticket->id), [
        'attachment' => $invalidFile,
    ]);

    $response->assertSessionHasErrors('attachment');
});

test('normal user reply accepts valid file types', function () {
    $user = User::factory()->create();
    
    $ticket = Ticket::create([
        'user_id' => $user->id,
        'subject' => 'File Type Test',
        'message' => 'Test Message',
        'priority' => 'medium',
        'status' => 'open',
        'source' => 'web',
    ]);

    $fileTypes = [
        UploadedFile::fake()->image('image.jpg'),
        UploadedFile::fake()->image('image.jpeg'),
        UploadedFile::fake()->image('image.png'),
        UploadedFile::fake()->image('image.webp'),
        UploadedFile::fake()->create('document.pdf', 100),
        UploadedFile::fake()->create('notes.txt', 50),
        UploadedFile::fake()->create('debug.log', 75),
        UploadedFile::fake()->create('archive.zip', 200),
    ];

    foreach ($fileTypes as $file) {
        $response = $this->actingAs($user)->post(route('tickets.reply', $ticket->id), [
            'attachment' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }
});
