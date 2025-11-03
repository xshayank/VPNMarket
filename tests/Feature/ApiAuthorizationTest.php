<?php

use App\Models\User;
use App\Models\Panel;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the RBAC seeder to set up roles and permissions
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\RbacSeeder']);
});

test('admin can access panel API endpoints', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->assignRole('admin');
    
    // Test listing panels
    $response = $this->actingAs($admin)->getJson('/api/admin/panels');
    $response->assertStatus(200)
             ->assertJson(['success' => true]);
    
    // Test creating a panel
    $response = $this->actingAs($admin)->postJson('/api/admin/panels', [
        'name' => 'Test Panel',
        'url' => 'https://example.com',
        'panel_type' => 'marzban',
        'is_active' => true,
    ]);
    $response->assertStatus(201)
             ->assertJson(['success' => true]);
});

test('non-admin cannot access panel API endpoints', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $user->assignRole('user');
    
    // Test listing panels - should be forbidden
    $response = $this->actingAs($user)->getJson('/api/admin/panels');
    $response->assertStatus(403);
    
    // Test creating a panel - should be forbidden
    $response = $this->actingAs($user)->postJson('/api/admin/panels', [
        'name' => 'Test Panel',
        'url' => 'https://example.com',
        'panel_type' => 'marzban',
        'is_active' => true,
    ]);
    $response->assertStatus(403);
});

test('reseller cannot access panel API endpoints', function () {
    $reseller = User::factory()->create(['is_admin' => false]);
    $reseller->assignRole('reseller');
    
    // Test listing panels - should be forbidden
    $response = $this->actingAs($reseller)->getJson('/api/admin/panels');
    $response->assertStatus(403);
    
    // Test creating a panel - should be forbidden
    $response = $this->actingAs($reseller)->postJson('/api/admin/panels', [
        'name' => 'Test Panel',
        'url' => 'https://example.com',
        'panel_type' => 'marzban',
        'is_active' => true,
    ]);
    $response->assertStatus(403);
});

test('admin can update and delete panels', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->assignRole('admin');
    
    $panel = Panel::factory()->create();
    
    // Test updating a panel
    $response = $this->actingAs($admin)->putJson("/api/admin/panels/{$panel->id}", [
        'name' => 'Updated Panel',
        'is_active' => false,
    ]);
    $response->assertStatus(200)
             ->assertJson(['success' => true]);
    
    // Test deleting a panel
    $response = $this->actingAs($admin)->deleteJson("/api/admin/panels/{$panel->id}");
    $response->assertStatus(200)
             ->assertJson(['success' => true]);
});

test('admin can access audit logs API', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->assignRole('admin');
    
    $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs');
    $response->assertStatus(200);
});

test('non-admin cannot access audit logs API', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $user->assignRole('user');
    
    $response = $this->actingAs($user)->getJson('/api/admin/audit-logs');
    $response->assertStatus(403);
});

test('unauthenticated users cannot access API endpoints', function () {
    // Test panels endpoint
    $response = $this->getJson('/api/admin/panels');
    $response->assertStatus(401);
    
    // Test audit logs endpoint
    $response = $this->getJson('/api/admin/audit-logs');
    $response->assertStatus(401);
});

test('super admin has full API access', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $superAdmin->assignRole('super-admin');
    
    $panel = Panel::factory()->create();
    
    // Test all CRUD operations
    $response = $this->actingAs($superAdmin)->getJson('/api/admin/panels');
    $response->assertStatus(200);
    
    $response = $this->actingAs($superAdmin)->postJson('/api/admin/panels', [
        'name' => 'Super Admin Panel',
        'url' => 'https://superadmin.com',
        'panel_type' => 'marzban',
        'is_active' => true,
    ]);
    $response->assertStatus(201);
    
    $response = $this->actingAs($superAdmin)->getJson("/api/admin/panels/{$panel->id}");
    $response->assertStatus(200);
    
    $response = $this->actingAs($superAdmin)->putJson("/api/admin/panels/{$panel->id}", [
        'name' => 'Updated by Super Admin',
    ]);
    $response->assertStatus(200);
});
