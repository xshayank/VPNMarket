<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class SecurityLog
{
    /**
     * Redact sensitive data from log entries
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact($data)
    {
        if (is_string($data)) {
            return self::redactString($data);
        }

        if (is_array($data)) {
            return self::redactArray($data);
        }

        return $data;
    }

    /**
     * Redact sensitive fields from an array
     *
     * @param array $data
     * @return array
     */
    protected static function redactArray(array $data): array
    {
        $sensitiveKeys = [
            'password',
            'api_token',
            'api_key',
            'token',
            'secret',
            'auth',
            'authorization',
            'apiKey',
            'apiToken',
            'apiSecret',
            'private_key',
            'access_token',
            'refresh_token',
        ];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Check if key contains sensitive keyword
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, strtolower($sensitiveKey))) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive && !is_array($value)) {
                // Only redact if it's not an array (to allow nested objects like 'credentials' => [...])
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            } elseif (is_string($value)) {
                $data[$key] = self::redactString($value);
            }
        }

        return $data;
    }

    /**
     * Redact sensitive patterns from a string
     *
     * @param string $data
     * @return string
     */
    protected static function redactString(string $data): string
    {
        // Redact JWT tokens (three base64 segments separated by dots)
        $data = preg_replace(
            '/eyJ[a-zA-Z0-9_-]+\.eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+/',
            '[REDACTED_JWT]',
            $data
        );

        // Redact API keys with common prefixes (more specific pattern)
        // Matches: sk_*, pk_*, api_*, token_*, key_* followed by long alphanumeric strings
        $data = preg_replace(
            '/\b(sk|pk|api|token|key)_[a-zA-Z0-9_-]{20,}\b/',
            '[REDACTED_KEY]',
            $data
        );

        return $data;
    }

    /**
     * Log security-related events with automatic redaction
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $redactedContext = self::redactArray($context);
        
        Log::log($level, $message, $redactedContext);
    }

    /**
     * Log info level security event
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * Log warning level security event
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * Log error level security event
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }
}
