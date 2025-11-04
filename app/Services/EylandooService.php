<?php

namespace App\Services;

use App\Helpers\OwnerExtraction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EylandooService
{
    protected string $baseUrl;

    protected string $apiKey;

    protected string $nodeHostname;

    public function __construct(string $baseUrl, string $apiKey, string $nodeHostname = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->nodeHostname = $nodeHostname;
    }

    /**
     * Get authenticated HTTP client
     */
    protected function client()
    {
        return Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Create a new user
     *
     * @param  array  $userData  User data with keys: username, data_limit, expire, max_clients (connections)
     * @return array|null Response from API or null on failure
     */
    public function createUser(array $userData): ?array
    {
        try {
            $payload = [
                'username' => $userData['username'],
            ];

            // Add max_clients (connections) if provided
            if (isset($userData['max_clients'])) {
                $payload['max_clients'] = (int) $userData['max_clients'];
            }

            // Add data_limit in bytes if provided
            if (isset($userData['data_limit']) && $userData['data_limit'] !== null) {
                $payload['data_limit'] = (int) $userData['data_limit'];
                $payload['data_limit_unit'] = 'GB';
            }

            // Add expiry date if provided
            if (isset($userData['expire'])) {
                $payload['activation_type'] = 'fixed_date';
                $payload['expiry_date_str'] = date('Y-m-d', $userData['expire']);
            }

            $response = $this->client()->post($this->baseUrl.'/api/v1/users', $payload);

            Log::info('Eylandoo Create User Response:', $response->json() ?? ['raw' => $response->body()]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Eylandoo Create User Exception:', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get user details
     *
     * @param  string  $username  Username to fetch
     * @return array|null User data or null on failure
     */
    public function getUser(string $username): ?array
    {
        try {
            $response = $this->client()->get($this->baseUrl."/api/v1/users/{$username}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Eylandoo Get User failed:', ['status' => $response->status(), 'username' => $username]);

            return null;
        } catch (\Exception $e) {
            Log::error('Eylandoo Get User Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    /**
     * Update user details
     *
     * @param  string  $username  Username to update
     * @param  array  $userData  Data to update (data_limit, expire, max_clients, etc.)
     * @return bool Success status
     */
    public function updateUser(string $username, array $userData): bool
    {
        try {
            $payload = [];

            // Update max_clients if provided
            if (isset($userData['max_clients'])) {
                $payload['max_clients'] = (int) $userData['max_clients'];
            }

            // Update data_limit if provided
            if (array_key_exists('data_limit', $userData)) {
                if ($userData['data_limit'] === null) {
                    $payload['data_limit'] = null; // Unlimited
                } else {
                    $payload['data_limit'] = (int) $userData['data_limit'];
                    $payload['data_limit_unit'] = 'GB';
                }
            }

            // Update expiry date if provided
            if (isset($userData['expire'])) {
                $payload['activation_type'] = 'fixed_date';
                $payload['expiry_date_str'] = date('Y-m-d', $userData['expire']);
                $payload['reset_activation'] = true;
            }

            // Only send request if there's something to update
            if (empty($payload)) {
                return true;
            }

            $response = $this->client()->put($this->baseUrl."/api/v1/users/{$username}", $payload);

            Log::info('Eylandoo Update User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Update User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Enable a user
     *
     * @param  string  $username  Username to enable
     * @return bool Success status
     */
    public function enableUser(string $username): bool
    {
        try {
            // First check current status
            $user = $this->getUser($username);

            if (! $user) {
                Log::warning("Cannot enable Eylandoo user {$username}: user not found");

                return false;
            }

            $currentStatus = $user['data']['status'] ?? 'active';

            // If already enabled, return success
            if ($currentStatus === 'active') {
                Log::info("Eylandoo user {$username} is already enabled");

                return true;
            }

            // Toggle to enable
            $response = $this->client()->post($this->baseUrl."/api/v1/users/{$username}/toggle");

            Log::info('Eylandoo Enable User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Enable User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Disable a user
     *
     * @param  string  $username  Username to disable
     * @return bool Success status
     */
    public function disableUser(string $username): bool
    {
        try {
            // First check current status
            $user = $this->getUser($username);

            if (! $user) {
                Log::warning("Cannot disable Eylandoo user {$username}: user not found");

                return false;
            }

            $currentStatus = $user['data']['status'] ?? 'active';

            // If already disabled, return success
            if ($currentStatus === 'disabled') {
                Log::info("Eylandoo user {$username} is already disabled");

                return true;
            }

            // Toggle to disable
            $response = $this->client()->post($this->baseUrl."/api/v1/users/{$username}/toggle");

            Log::info('Eylandoo Disable User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Disable User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Delete a user
     *
     * @param  string  $username  Username to delete
     * @return bool Success status
     */
    public function deleteUser(string $username): bool
    {
        try {
            $response = $this->client()->delete($this->baseUrl."/api/v1/users/{$username}");

            Log::info('Eylandoo Delete User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Delete User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Reset user usage
     *
     * @param  string  $username  Username to reset
     * @return bool Success status
     */
    public function resetUserUsage(string $username): bool
    {
        try {
            $response = $this->client()->post($this->baseUrl."/api/v1/users/{$username}/reset-usage");

            Log::info('Eylandoo Reset User Usage Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Reset User Usage Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Build absolute subscription URL from API response
     */
    public function buildAbsoluteSubscriptionUrl(array $userApiResponse): string
    {
        // Eylandoo returns config_url in the data
        $configUrl = $userApiResponse['data']['users'][0]['config_url'] ?? $userApiResponse['data']['config_url'] ?? '';

        // If the URL is already absolute, return as is
        if (preg_match('#^https?://#i', $configUrl)) {
            return $configUrl;
        }

        // Use nodeHostname if set, otherwise fall back to baseUrl
        $baseHost = ! empty($this->nodeHostname) ? $this->nodeHostname : $this->baseUrl;

        // Ensure exactly one slash between hostname and path
        return rtrim($baseHost, '/').'/'.ltrim($configUrl, '/');
    }

    /**
     * Generate subscription link message
     */
    public function generateSubscriptionLink(array $userApiResponse): string
    {
        $absoluteUrl = $this->buildAbsoluteSubscriptionUrl($userApiResponse);

        return "لینک سابسکریپشن شما (در تمام برنامه‌ها import کنید):\n".$absoluteUrl;
    }

    /**
     * List all admins from the panel
     */
    public function listAdmins(): array
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/api/v1/admins');

            if ($response->successful()) {
                $data = $response->json();

                return $data['data']['admins'] ?? [];
            }

            Log::warning('Eylandoo List Admins failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Eylandoo List Admins Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * List configs/users created by a specific admin
     */
    public function listConfigsByAdmin(string $adminUsername): array
    {
        try {
            // Eylandoo API might support filtering by admin
            $response = $this->client()->get($this->baseUrl.'/api/v1/users', [
                'admin' => $adminUsername,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $users = $data['data']['users'] ?? [];

                // Map users to our expected format
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

                // Filter by admin username as safety net
                return array_values(array_filter($configs, function ($config) use ($adminUsername) {
                    return $config['admin'] === $adminUsername;
                }));
            }

            Log::warning('Eylandoo List Configs by Admin failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Eylandoo List Configs by Admin Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
