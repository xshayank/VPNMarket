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

    public function createUser(array $userData): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) {
                return ['detail' => 'Authentication failed'];
            }
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
        if (!$this->accessToken) {
            if (!$this->login()) return null;
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
        if (! $this->accessToken) {
            if (! $this->login()) {
                return null;
            }
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
        if (! $this->accessToken) {
            if (! $this->login()) {
                return false;
            }
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

    /**
     * List all admins from the panel
     */
    public function listAdmins(): array
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return [];
            }
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->baseUrl.'/api/admins');

            if ($response->successful()) {
                $admins = $response->json();
                // Filter to only non-sudo admins
                return array_filter($admins, function ($admin) {
                    return !($admin['is_sudo'] ?? false);
                });
            }

            Log::warning('Marzban List Admins failed:', ['status' => $response->status()]);
            return [];
        } catch (\Exception $e) {
            Log::error('Marzban List Admins Exception:', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * List configs/users created by a specific admin
     */
    public function listConfigsByAdmin(string $adminUsername): array
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return [];
            }
        }

        try {
            // Marzban API uses /api/users endpoint with optional filters
            // We'll fetch all users and filter by admin if the API provides that info
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->baseUrl.'/api/users', [
                    'admin' => $adminUsername,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                // Handle both direct array and paginated response
                $users = $data['users'] ?? $data;
                
                return array_map(function ($user) {
                    return [
                        'id' => $user['id'] ?? null,
                        'username' => $user['username'],
                        'status' => $user['status'] ?? 'active',
                        'used_traffic' => $user['used_traffic'] ?? 0,
                        'data_limit' => $user['data_limit'] ?? null,
                    ];
                }, $users);
            }

            Log::warning('Marzban List Configs by Admin failed:', ['status' => $response->status()]);
            return [];
        } catch (\Exception $e) {
            Log::error('Marzban List Configs by Admin Exception:', ['message' => $e->getMessage()]);
            return [];
        }
    }
}
