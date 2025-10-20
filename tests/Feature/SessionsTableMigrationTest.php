<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sessions_table_exists_after_migrations(): void
    {
        // The RefreshDatabase trait runs all migrations
        // Verify the sessions table exists
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    public function test_sessions_table_has_required_columns(): void
    {
        // Verify all required columns exist
        $this->assertTrue(Schema::hasColumn('sessions', 'id'));
        $this->assertTrue(Schema::hasColumn('sessions', 'user_id'));
        $this->assertTrue(Schema::hasColumn('sessions', 'ip_address'));
        $this->assertTrue(Schema::hasColumn('sessions', 'user_agent'));
        $this->assertTrue(Schema::hasColumn('sessions', 'payload'));
        $this->assertTrue(Schema::hasColumn('sessions', 'last_activity'));
    }
}
