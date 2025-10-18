<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarzneshinService
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected string $nodeHostname;

    protected ?string $accessToken = null;

    public function __construct(string $baseUrl, string $username, string $password, string $nodeHostname)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->nodeHostname = $nodeHostname;
    }

    public function login(): bool
    {
        try {
            $response = Http::asForm()->post($this->baseUrl.'/api/admins/token', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->successful() && isset($response->json()['access_token'])) {
                $this->accessToken = $response->json()['access_token'];

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Marzneshin Login Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    public function createUser(array $userData): ?array
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return ['detail' => 'Authentication failed'];
            }
        }

        try {
            // Map our application data to Marzneshin API format
            $apiData = [
                'username' => $userData['username'],
                'expire_strategy' => 'fixed_date',
                'expire_date' => $this->convertTimestampToIso8601($userData['expire']),
                'data_limit' => $userData['data_limit'],
                'data_limit_reset_strategy' => 'no_reset',
                'service_ids' => $userData['service_ids'] ?? [],
            ];

            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($this->baseUrl.'/api/users', $apiData);

            Log::info('Marzneshin Create User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Marzneshin Create User Exception:', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Convert Unix timestamp to ISO 8601 datetime format
     *
     * @param  int  $timestamp  Unix timestamp
     * @return string ISO 8601 formatted datetime
     */
    private function convertTimestampToIso8601(int $timestamp): string
    {
        return date('c', $timestamp);
    }

    public function updateUser(string $username, array $userData): ?array
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return null;
            }
        }

        try {
            $apiData = [
                'expire_strategy' => 'fixed_date',
                'expire_date' => $this->convertTimestampToIso8601($userData['expire']),
                'data_limit' => $userData['data_limit'],
            ];

            if (isset($userData['service_ids'])) {
                $apiData['service_ids'] = $userData['service_ids'];
            }

            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->put($this->baseUrl."/api/users/{$username}", $apiData);

            Log::info('Marzneshin Update User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Marzneshin Update User Exception:', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public function generateSubscriptionLink(array $userApiResponse): string
    {
        $subscriptionUrl = $userApiResponse['subscription_url'];

        return "لینک سابسکریپشن شما (در تمام برنامه‌ها import کنید):\n".$this->nodeHostname.$subscriptionUrl;
    }
}
