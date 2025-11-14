<?php

namespace App\Services\Payments;

use App\Support\Tetra98Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Tetra98Client
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $apiKey = null,
    ) {
        $this->baseUrl = $baseUrl ?? Tetra98Config::getBaseUrl();
        $this->apiKey = $apiKey ?? Tetra98Config::getApiKey();
    }

    protected function buildRequest(): PendingRequest
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Tetra98 API key is not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->asJson()
            ->acceptJson()
            ->timeout(15);
    }

    public function createOrder(
        string $hashId,
        int $amountToman,
        string $description,
        string $email,
        string $mobile,
        string $callbackUrl
    ): Response {
        $payload = [
            'ApiKey' => $this->apiKey,
            'Hash_id' => $hashId,
            'Amount' => $amountToman * 10,
            'Description' => $description,
            'Email' => $email,
            'Mobile' => $mobile,
            'CallbackURL' => $callbackUrl,
        ];

        return $this->buildRequest()->post('/api/create_order', $payload);
    }

    public function verify(string $authority): Response
    {
        $payload = [
            'authority' => $authority,
            'ApiKey' => $this->apiKey,
        ];

        return $this->buildRequest()->post('/api/verify', $payload);
    }
}
