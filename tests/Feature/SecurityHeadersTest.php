<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    /**
     * Test that security headers are applied to web requests.
     */
    public function test_security_headers_are_applied_to_web_requests(): void
    {
        $response = $this->get('/');

        // Verify security headers are present
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        
        // Verify CSP header exists
        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        
        // Verify Permissions-Policy exists
        $this->assertTrue($response->headers->has('Permissions-Policy'));
    }

    /**
     * Test that HSTS header is NOT applied in non-production or non-HTTPS.
     */
    public function test_hsts_not_applied_in_testing_environment(): void
    {
        $response = $this->get('/');

        // HSTS should not be set in testing environment (non-HTTPS)
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}
