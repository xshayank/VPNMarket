<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarzbanService
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
            $response = Http::asForm()->post($this->baseUrl . '/api/admin/token', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->successful() && isset($response->json()['access_token'])) {
                $this->accessToken = $response->json()['access_token'];
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error('Marzban Login Exception:', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Ensure authentication token is valid
     * This method consolidates authentication checks to reduce redundant code
     */
    protected function ensureAuthenticated(): bool
    {
        if (!$this->accessToken) {
            return $this->login();
        }
        return true;
    }

    public function createUser(array $userData): ?array
    {
        if (!$this->ensureAuthenticated()) {
            return ['detail' => 'Authentication failed'];
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($this->baseUrl . '/api/user', [
                    'username' => $userData['username'],
                    'proxies' => ['vless' => new \stdClass()],
                    'inbounds' => new \stdClass(),

                    'expire' => $userData['expire'],
                    'data_limit' => $userData['data_limit'],
                    // ======================================================
                    'data_limit_reset_strategy' => 'no_reset',
                ]);

            Log::info('Marzban Create User Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();

        } catch (\Exception $e) {
            Log::error('Marzban Create User Exception:', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function updateUser(string $username, array $userData): ?array
    {
        if (!$this->ensureAuthenticated()) {
            return null;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->put($this->baseUrl . "/api/user/{$username}", [
                    'expire' => $userData['expire'],
                    'data_limit' => $userData['data_limit'],
                ]);

            Log::info('Marzban Update User Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Marzban Update User Exception:', ['message' => $e->getMessage()]);
            return null;
        }
    }


    /**
     * Build absolute subscription URL from API response
     */
    public function buildAbsoluteSubscriptionUrl(array $userApiResponse): string
    {
        $subscriptionUrl = $userApiResponse['subscription_url'];
        
        // If the subscription URL is already absolute, return as is
        if (preg_match('#^https?://#i', $subscriptionUrl)) {
            return $subscriptionUrl;
        }
        
        // Use nodeHostname if set, otherwise fall back to baseUrl
        $baseHost = !empty($this->nodeHostname) ? $this->nodeHostname : $this->baseUrl;
        
        // Ensure exactly one slash between hostname and path
        return rtrim($baseHost, '/') . '/' . ltrim($subscriptionUrl, '/');
    }

    public function generateSubscriptionLink(array $userApiResponse): string
    {
        $absoluteUrl = $this->buildAbsoluteSubscriptionUrl($userApiResponse);

        return "لینک سابسکریپشن شما (در تمام برنامه‌ها import کنید):\n" . $absoluteUrl;
    }

    public function getUser(string $username): ?array
    {
        if (!$this->ensureAuthenticated()) {
            return null;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->baseUrl."/api/user/{$username}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Marzban Get User failed:', ['status' => $response->status(), 'username' => $username]);

            return null;
        } catch (\Exception $e) {
            Log::error('Marzban Get User Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    public function deleteUser(string $username): bool
    {
        if (!$this->ensureAuthenticated()) {
            return false;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->delete($this->baseUrl."/api/user/{$username}");

            Log::info('Marzban Delete User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Marzban Delete User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
