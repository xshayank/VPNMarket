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

    public function updateUser(string $username, array $userData): bool
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return false;
            }
        }

        try {
            // Always include username in the payload as required by Marzneshin API
            $apiData = [
                'username' => $username,
            ];

            // Only add fields that are provided
            if (isset($userData['expire'])) {
                $apiData['expire_strategy'] = 'fixed_date';
                $apiData['expire_date'] = $this->convertTimestampToIso8601($userData['expire']);
            }

            if (isset($userData['data_limit'])) {
                $apiData['data_limit'] = $userData['data_limit'];
            }

            if (isset($userData['service_ids'])) {
                $apiData['service_ids'] = (array) $userData['service_ids'];
            }

            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->put($this->baseUrl."/api/users/{$username}", $apiData);

            Log::info('Marzneshin Update User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Marzneshin Update User Exception:', ['message' => $e->getMessage()]);

            return false;
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
        $baseHost = ! empty($this->nodeHostname) ? $this->nodeHostname : $this->baseUrl;

        // Ensure exactly one slash between hostname and path
        return rtrim($baseHost, '/').'/'.ltrim($subscriptionUrl, '/');
    }

    public function generateSubscriptionLink(array $userApiResponse): string
    {
        $absoluteUrl = $this->buildAbsoluteSubscriptionUrl($userApiResponse);

        return "لینک سابسکریپشن شما (در تمام برنامه‌ها import کنید):\n".$absoluteUrl;
    }

    public function listServices(): array
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return [];
            }
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(10)
                ->connectTimeout(5)
                ->get($this->baseUrl.'/api/services');

            if ($response->successful()) {
                $data = $response->json();
                $services = [];

                // Handle paginated response - items could be directly in response or in 'items' key
                $items = $data['items'] ?? $data;

                if (is_array($items)) {
                    foreach ($items as $service) {
                        if (isset($service['id']) && isset($service['name'])) {
                            $services[] = [
                                'id' => $service['id'],
                                'name' => $service['name'],
                            ];
                        }
                    }
                }

                return $services;
            }

            Log::error('Marzneshin List Services failed:', ['status' => $response->status(), 'body' => $response->body()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Marzneshin List Services Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }

    public function enableUser(string $username): bool
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return false;
            }
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($this->baseUrl."/api/users/{$username}/enable");

            Log::info('Marzneshin Enable User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Marzneshin Enable User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    public function disableUser(string $username): bool
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return false;
            }
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($this->baseUrl."/api/users/{$username}/disable");

            Log::info('Marzneshin Disable User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Marzneshin Disable User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    public function resetUser(string $username): bool
    {
        if (! $this->accessToken) {
            if (! $this->login()) {
                return false;
            }
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($this->baseUrl."/api/users/{$username}/reset");

            Log::info('Marzneshin Reset User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Marzneshin Reset User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
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
                ->get($this->baseUrl."/api/users/{$username}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Marzneshin Get User failed:', ['status' => $response->status(), 'username' => $username]);

            return null;
        } catch (\Exception $e) {
            Log::error('Marzneshin Get User Exception:', ['message' => $e->getMessage(), 'username' => $username]);

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
                ->delete($this->baseUrl."/api/users/{$username}");

            Log::info('Marzneshin Delete User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Marzneshin Delete User Exception:', ['message' => $e->getMessage()]);

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
                $data = $response->json();
                // Handle paginated response
                $admins = $data['items'] ?? $data;

                // Filter to only non-sudo admins
                return array_filter($admins, function ($admin) {
                    return ! ($admin['is_sudo'] ?? false);
                });
            }

            Log::warning('Marzneshin List Admins failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Marzneshin List Admins Exception:', ['message' => $e->getMessage()]);

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
            // Marzneshin API uses /api/users endpoint with optional filters
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->baseUrl.'/api/users', [
                    'admin' => $adminUsername,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                // Handle paginated response
                $users = $data['items'] ?? $data;

                // Map and filter users - ensure we only return configs owned by the specified admin
                $configs = array_map(function ($user) {
                    return [
                        'id' => $user['id'] ?? null,
                        'username' => $user['username'],
                        'status' => $user['status'] ?? 'active',
                        'used_traffic' => $user['used_traffic'] ?? 0,
                        'data_limit' => $user['data_limit'] ?? null,
                        'admin' => $user['admin'] ?? null,
                        'owner_username' => $user['admin'] ?? null,
                    ];
                }, $users);

                // Client-side filter as safety net in case API doesn't support admin parameter
                // Filter by admin username or owner fields
                return array_filter($configs, function ($config) use ($adminUsername) {
                    $owner = $config['admin'] ?? $config['owner_username'] ?? null;
                    return $owner === $adminUsername;
                });
            }

            Log::warning('Marzneshin List Configs by Admin failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Marzneshin List Configs by Admin Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
