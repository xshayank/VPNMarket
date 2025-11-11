<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class StarsEfarClient
{
    protected string $baseUrl;

    protected ?string $apiKey;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null)
    {
        $this->baseUrl = $baseUrl ?? config('starsefar.base_url', 'https://starsefar.xyz');
        $this->apiKey = $apiKey ?? config('starsefar.api_key');
    }

    protected function buildRequest(): PendingRequest
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('StarsEfar API key is not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->withToken($this->apiKey);
    }

    /**
     * Create a new gift link payment request.
     *
     * @param  int         $amountToman
     * @param  string|null $targetAccount
     * @param  string|null $callbackUrl
     * @return array<string, mixed>
     */
    public function createGiftLink(int $amountToman, ?string $targetAccount = null, ?string $callbackUrl = null): array
    {
        $payload = [
            'amount' => $amountToman,
        ];

        if (! empty($targetAccount)) {
            $payload['targetAccount'] = $targetAccount;
        }

        if (! empty($callbackUrl)) {
            $payload['callbackUrl'] = $callbackUrl;
        }

        $response = $this->buildRequest()->post('/api/create-gift-link', $payload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning('StarsEfar create gift link failed', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        $response->throw();

        return [];
    }

    /**
     * Check order status by order ID.
     *
     * @param  string $orderId
     * @return array<string, mixed>
     */
    public function checkOrder(string $orderId): array
    {
        $response = $this->buildRequest()->get('/api/check-order/'.Str::of($orderId)->trim());

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning('StarsEfar check order failed', [
            'order_id' => $orderId,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        $response->throw();

        return [];
    }
}
