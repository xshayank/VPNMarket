<?php

use App\Helpers\SecurityLog;

test('redacts sensitive fields from arrays', function () {
    $data = [
        'username' => 'testuser',
        'password' => 'secret123',
        'api_token' => 'token_abc123',
        'api_key' => 'key_xyz789',
        'email' => 'test@example.com',
    ];

    $redacted = SecurityLog::redact($data);

    expect($redacted['username'])->toBe('testuser');
    expect($redacted['password'])->toBe('[REDACTED]');
    expect($redacted['api_token'])->toBe('[REDACTED]');
    expect($redacted['api_key'])->toBe('[REDACTED]');
    expect($redacted['email'])->toBe('test@example.com');
});

test('redacts nested sensitive fields', function () {
    $data = [
        'user' => [
            'name' => 'John',
            'credentials' => [
                'password' => 'secret',
                'api_token' => 'token123',
            ],
        ],
    ];

    $redacted = SecurityLog::redact($data);

    expect($redacted['user']['name'])->toBe('John');
    expect($redacted['user']['credentials']['password'])->toBe('[REDACTED]');
    expect($redacted['user']['credentials']['api_token'])->toBe('[REDACTED]');
});

test('redacts jwt tokens from strings', function () {
    $string = 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $redacted = SecurityLog::redact($string);

    expect($redacted)->not->toContain('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9');
    expect($redacted)->toContain('[REDACTED_JWT]');
});

test('redacts api keys with common prefixes from strings', function () {
    $string = 'API Key: api_test_abcdefghijklmnopqrstuvwxyz1234567890';

    $redacted = SecurityLog::redact($string);

    expect($redacted)->not->toContain('api_test_abcdefghijklmnopqrstuvwxyz1234567890');
    expect($redacted)->toContain('[REDACTED_KEY]');
});
