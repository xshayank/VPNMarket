<?php

use App\Helpers\SecurityLog;
use Tests\TestCase;

class SecurityLogTest extends TestCase
{
    /**
     * Test that sensitive data is redacted from arrays.
     */
    public function test_redacts_sensitive_fields_from_arrays(): void
    {
        $data = [
            'username' => 'testuser',
            'password' => 'secret123',
            'api_token' => 'token_abc123',
            'api_key' => 'key_xyz789',
            'email' => 'test@example.com',
        ];

        $redacted = SecurityLog::redact($data);

        $this->assertEquals('testuser', $redacted['username']);
        $this->assertEquals('[REDACTED]', $redacted['password']);
        $this->assertEquals('[REDACTED]', $redacted['api_token']);
        $this->assertEquals('[REDACTED]', $redacted['api_key']);
        $this->assertEquals('test@example.com', $redacted['email']);
    }

    /**
     * Test that sensitive data is redacted from nested arrays.
     */
    public function test_redacts_nested_sensitive_fields(): void
    {
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

        $this->assertEquals('John', $redacted['user']['name']);
        $this->assertEquals('[REDACTED]', $redacted['user']['credentials']['password']);
        $this->assertEquals('[REDACTED]', $redacted['user']['credentials']['api_token']);
    }

    /**
     * Test that JWT tokens are redacted from strings.
     */
    public function test_redacts_jwt_tokens_from_strings(): void
    {
        $string = 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

        $redacted = SecurityLog::redact($string);

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $redacted);
        $this->assertStringContainsString('[REDACTED_JWT]', $redacted);
    }

    /**
     * Test that API keys are redacted from strings.
     */
    public function test_redacts_long_api_keys_from_strings(): void
    {
        $string = 'API Key: test_abcdefghijklmnopqrstuvwxyz1234567890';

        $redacted = SecurityLog::redact($string);

        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz1234567890', $redacted);
        $this->assertStringContainsString('[REDACTED_KEY]', $redacted);
    }
}
