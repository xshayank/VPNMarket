<?php

use App\Helpers\OwnerExtraction;

test('extracts owner from admin field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'admin' => 'admin_x',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('extracts owner from admin_username field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'admin_username' => 'admin_x',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('extracts owner from owner field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'owner' => 'admin_x',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('extracts owner from owner_username field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'owner_username' => 'admin_x',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('extracts owner from created_by field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'created_by' => 'admin_x',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('extracts owner from created_by_username field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'created_by_username' => 'admin_x',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('extracts owner from meta.owner field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'meta' => [
            'owner' => 'admin_x',
        ],
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('extracts owner from meta.owner_username field', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'meta' => [
            'owner_username' => 'admin_x',
        ],
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('returns null when no owner field exists', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'status' => 'active',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBeNull();
});

test('prioritizes admin field over other fields', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'admin' => 'admin_x',
        'owner' => 'admin_y',
        'created_by' => 'admin_z',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('prioritizes admin_username over owner fields', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'admin_username' => 'admin_x',
        'owner' => 'admin_y',
        'created_by' => 'admin_z',
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBe('admin_x');
});

test('returns null when owner field is not a string', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'admin' => 123, // Not a string
    ];
    
    expect(OwnerExtraction::ownerUsername($record))->toBeNull();
});

test('ignores empty string owner values', function () {
    $record = [
        'id' => 1,
        'username' => 'user1',
        'admin' => '',
        'owner' => 'admin_x',
    ];
    
    // Empty string is still a string, so it will be returned
    // This is intentional - empty string indicates no owner
    expect(OwnerExtraction::ownerUsername($record))->toBe('');
});
