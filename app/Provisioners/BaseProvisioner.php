<?php

namespace App\Provisioners;

use Illuminate\Support\Facades\Log;

abstract class BaseProvisioner implements ProvisionerInterface
{
    /**
     * Retry an operation with exponential backoff
     * Attempts: 0s, 1s, 3s (3 total attempts)
     *
     * @param  callable  $operation  The operation to retry (should return bool)
     * @param  string  $description  Description for logging (no sensitive data)
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    protected function retryOperation(callable $operation, string $description): array
    {
        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Exponential backoff: 0s, 1s, 3s
                if ($attempt > 1) {
                    $delay = $attempt == 2 ? 1 : 3;
                    usleep($delay * 1000000); // Convert to microseconds
                }

                $result = $operation();

                if ($result) {
                    return [
                        'success' => true,
                        'attempts' => $attempt,
                        'last_error' => null,
                    ];
                }

                $lastError = 'Operation returned false';
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning("Attempt {$attempt}/{$maxAttempts} to {$description} failed: {$lastError}");
            }
        }

        Log::error("All {$maxAttempts} attempts to {$description} failed. Last error: {$lastError}");

        return [
            'success' => false,
            'attempts' => $maxAttempts,
            'last_error' => $lastError,
        ];
    }
}
