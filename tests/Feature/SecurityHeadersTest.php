<?php

use Tests\TestCase;

test('security headers are applied to web requests', function () {
    $response = $this->get('/');

    // Verify security headers are present
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-XSS-Protection', '1; mode=block');
    
    // Verify CSP header exists
    expect($response->headers->has('Content-Security-Policy'))->toBeTrue();
    
    // Verify Permissions-Policy exists
    expect($response->headers->has('Permissions-Policy'))->toBeTrue();
});

test('hsts not applied in testing environment', function () {
    $response = $this->get('/');

    // HSTS should not be set in testing environment (non-HTTPS)
    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});
