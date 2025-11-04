<?php

namespace App\Services;

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
     * @param  array  $userData  User data with keys: username, data_limit, expire, max_clients (connections), nodes
     * @return array|null Response from API or null on failure
     */
    public function createUser(array $userData): ?array
    {
        try {
            $payload = [
                'username' => $userData['username'],
                'activation_type' => 'fixed_date',
            ];

            // Add max_clients (connections) if provided
            if (isset($userData['max_clients'])) {
                $payload['max_clients'] = (int) $userData['max_clients'];
            }

            // Add data_limit - Eylandoo expects the limit in the specified unit
            if (isset($userData['data_limit']) && $userData['data_limit'] !== null) {
                // Convert bytes to GB for Eylandoo
                $dataLimitGB = round($userData['data_limit'] / (1024 * 1024 * 1024), 2);
                $payload['data_limit'] = $dataLimitGB;
                $payload['data_limit_unit'] = 'GB';
            }

            // Add expiry date if provided
            if (isset($userData['expire'])) {
                $payload['expiry_date_str'] = date('Y-m-d', $userData['expire']);
            }

            // Add nodes if provided and non-empty (array of node IDs)
            if (isset($userData['nodes']) && is_array($userData['nodes']) && ! empty($userData['nodes'])) {
                $payload['nodes'] = array_map('intval', $userData['nodes']);
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
            $encodedUsername = rawurlencode($username);
            $endpoint = "/api/v1/users/{$encodedUsername}";

            Log::debug('Eylandoo Get User: requesting endpoint', [
                'username' => $username,
                'endpoint' => $endpoint,
            ]);

            $response = $this->client()->get($this->baseUrl.$endpoint);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Eylandoo Get User failed:', [
                'status' => $response->status(),
                'username' => $username,
                'endpoint' => $endpoint,
                'body_preview' => substr($response->body(), 0, 500),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Eylandoo Get User Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    /**
     * Get user usage in bytes
     *
     * @param  string  $username  Username to fetch usage for
     * @return int|null Usage in bytes, or null on hard failure (HTTP error/exception)
     */
    public function getUserUsageBytes(string $username): ?int
    {
        try {
            Log::info('Eylandoo usage fetch: calling endpoint', [
                'username' => $username,
                'endpoint' => "/api/v1/users/{$username}",
            ]);

            $userResponse = $this->getUser($username);

            if ($userResponse === null) {
                Log::warning('Eylandoo Get User Usage: failed to retrieve user data', ['username' => $username]);

                return null;
            }

            // Parse usage from response using enhanced parser
            $parseResult = $this->parseUsageFromResponse($userResponse);

            if ($parseResult['bytes'] === null) {
                // Hard failure or success:false
                Log::warning('Eylandoo usage parse failed', [
                    'username' => $username,
                    'reason' => $parseResult['reason'],
                    'available_keys' => $parseResult['keys'],
                ]);

                return null;
            }

            // Log success with matched path
            Log::info('Eylandoo usage parsed successfully', [
                'username' => $username,
                'usage_bytes' => $parseResult['bytes'],
                'matched_path' => $parseResult['reason'],
            ]);

            return $parseResult['bytes'];
        } catch (\Exception $e) {
            Log::error('Eylandoo Get User Usage Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    /**
     * Parse usage from Eylandoo API response
     * Handles multiple wrapper keys and usage field combinations
     * Collects all viable candidates and chooses the maximum non-null value
     *
     * @param  array  $resp  API response
     * @return array ['bytes' => int|null, 'reason' => string, 'keys' => array]
     */
    private function parseUsageFromResponse(array $resp): array
    {
        // Check for success:false flag (treat as hard failure)
        if (isset($resp['success']) && $resp['success'] === false) {
            return [
                'bytes' => null,
                'reason' => 'API returned success:false',
                'keys' => array_keys($resp),
            ];
        }

        // Collect wrappers to search (include root and known wrapper keys)
        $wrappers = ['root' => $resp];
        $wrapperKeys = ['userInfo', 'data', 'user', 'result', 'stats'];

        foreach ($wrapperKeys as $key) {
            if (isset($resp[$key]) && is_array($resp[$key])) {
                $wrappers[$key] = $resp[$key];
            }
        }

        // Single field keys to check
        $singleKeys = [
            'total_traffic_bytes',
            'traffic_total_bytes',
            'total_bytes',
            'used_traffic',
            'usage_bytes',
            'bytes_used',
            'data_used',
            'data_used_bytes',
            'data_usage_bytes',
            'traffic_used_bytes',
            'totalDataBytes',
            'totalTrafficBytes',
            'trafficBytes',
        ];

        // Pairs to sum (upload + download combinations)
        $pairs = [
            ['upload_bytes', 'download_bytes'],
            ['upload', 'download'],
            ['up', 'down'],
            ['uploaded', 'downloaded'],
            ['uplink', 'downlink'],
        ];

        // Collect all viable candidates
        $candidates = [];

        // Check single fields in each wrapper
        foreach ($wrappers as $wrapperName => $wrapper) {
            foreach ($singleKeys as $key) {
                if (isset($wrapper[$key])) {
                    $value = $wrapper[$key];
                    // Handle string numbers safely - accept only valid integer representations
                    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                        $bytes = max(0, (int) $value);
                        $candidates[] = [
                            'bytes' => $bytes,
                            'reason' => "{$wrapperName}.{$key}",
                            'keys' => array_keys($wrapper),
                        ];
                    } elseif (is_numeric($value) && $value >= 0) {
                        // Fallback for float/scientific notation, but ensure non-negative
                        $bytes = max(0, (int) $value);
                        $candidates[] = [
                            'bytes' => $bytes,
                            'reason' => "{$wrapperName}.{$key}",
                            'keys' => array_keys($wrapper),
                        ];
                    }
                }
            }
        }

        // Check pairs to sum in each wrapper
        foreach ($wrappers as $wrapperName => $wrapper) {
            foreach ($pairs as $pair) {
                [$upKey, $downKey] = $pair;
                if (isset($wrapper[$upKey]) && isset($wrapper[$downKey])) {
                    $up = $wrapper[$upKey];
                    $down = $wrapper[$downKey];
                    // Handle string numbers safely - accept only valid integer representations
                    $upValid = is_int($up) || (is_string($up) && ctype_digit($up)) || (is_numeric($up) && $up >= 0);
                    $downValid = is_int($down) || (is_string($down) && ctype_digit($down)) || (is_numeric($down) && $down >= 0);

                    if ($upValid && $downValid) {
                        $bytes = max(0, (int) $up + (int) $down);
                        $candidates[] = [
                            'bytes' => $bytes,
                            'reason' => "{$wrapperName}.{$upKey}+{$downKey}",
                            'keys' => array_keys($wrapper),
                        ];
                    }
                }
            }
        }

        // Choose the maximum non-null value from all candidates
        if (! empty($candidates)) {
            usort($candidates, function ($a, $b) {
                return $b['bytes'] <=> $a['bytes'];
            });

            return $candidates[0]; // Return the candidate with the maximum bytes
        }

        // No usage fields found - valid response but no traffic yet (return 0)
        $availableKeys = [];
        foreach ($wrappers as $wrapperName => $wrapper) {
            $availableKeys[$wrapperName] = array_keys($wrapper);
        }

        return [
            'bytes' => 0,
            'reason' => 'no traffic fields found (valid response, no usage)',
            'keys' => $availableKeys,
        ];
    }

    /**
     * Update user details
     *
     * @param  string  $username  Username to update
     * @param  array  $userData  Data to update (data_limit, expire, max_clients, nodes, etc.)
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
                    // Convert bytes to GB for Eylandoo
                    $dataLimitGB = round($userData['data_limit'] / (1024 * 1024 * 1024), 2);
                    $payload['data_limit'] = $dataLimitGB;
                    $payload['data_limit_unit'] = 'GB';
                }
            }

            // Update expiry date if provided
            if (isset($userData['expire'])) {
                $payload['activation_type'] = 'fixed_date';
                $payload['expiry_date_str'] = date('Y-m-d', $userData['expire']);
            }

            // Update nodes if provided
            if (isset($userData['nodes']) && is_array($userData['nodes'])) {
                $payload['nodes'] = array_map('intval', $userData['nodes']);
            }

            // Only send request if there's something to update
            if (empty($payload)) {
                return true;
            }

            $encodedUsername = rawurlencode($username);
            $response = $this->client()->put($this->baseUrl."/api/v1/users/{$encodedUsername}", $payload);

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
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->post($this->baseUrl."/api/v1/users/{$encodedUsername}/toggle");

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
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->post($this->baseUrl."/api/v1/users/{$encodedUsername}/toggle");

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
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->delete($this->baseUrl."/api/v1/users/{$encodedUsername}");

            Log::info('Eylandoo Delete User Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Delete User Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Reset user traffic usage
     *
     * @param  string  $username  Username to reset
     * @return bool Success status
     */
    public function resetUserUsage(string $username): bool
    {
        try {
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->post($this->baseUrl."/api/v1/users/{$encodedUsername}/reset_traffic");

            Log::info('Eylandoo Reset User Usage Response:', $response->json() ?? ['raw' => $response->body()]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Eylandoo Reset User Usage Exception:', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get user subscription details from the dedicated subscription endpoint
     *
     * @param  string  $username  Username to fetch subscription for
     * @return array|null Subscription data or null on failure
     */
    public function getUserSubscription(string $username): ?array
    {
        try {
            $encodedUsername = rawurlencode($username);
            $response = $this->client()->get($this->baseUrl."/api/v1/users/{$encodedUsername}/sub");

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Eylandoo Get User Subscription Response:', ['username' => $username, 'response' => $data]);

                return $data;
            }

            Log::warning('Eylandoo Get User Subscription failed:', ['status' => $response->status(), 'username' => $username]);

            return null;
        } catch (\Exception $e) {
            Log::error('Eylandoo Get User Subscription Exception:', ['message' => $e->getMessage(), 'username' => $username]);

            return null;
        }
    }

    /**
     * Extract subscription URL from the subscription endpoint response
     *
     * @param  array|null  $subResponse  Response from /api/v1/users/{username}/sub endpoint
     * @return string|null The extracted subscription URL or null if not found
     */
    public function extractSubscriptionUrlFromSub(?array $subResponse): ?string
    {
        if (! $subResponse) {
            return null;
        }

        // Try various known response shapes for subscription URL
        $configUrl = null;

        // Shape 1: subResponse.sub_url (production shape)
        if (isset($subResponse['subResponse']['sub_url'])) {
            $configUrl = $subResponse['subResponse']['sub_url'];
        }
        // Shape 2: sub_url at root
        elseif (isset($subResponse['sub_url'])) {
            $configUrl = $subResponse['sub_url'];
        }
        // Shape 3: subscription_url at root
        elseif (isset($subResponse['subscription_url'])) {
            $configUrl = $subResponse['subscription_url'];
        }
        // Shape 4: data.sub_url
        elseif (isset($subResponse['data']['sub_url'])) {
            $configUrl = $subResponse['data']['sub_url'];
        }
        // Shape 5: data.subscription_url
        elseif (isset($subResponse['data']['subscription_url'])) {
            $configUrl = $subResponse['data']['subscription_url'];
        }
        // Shape 6: url field
        elseif (isset($subResponse['url'])) {
            $configUrl = $subResponse['url'];
        }

        return $this->makeAbsoluteUrl($configUrl);
    }

    /**
     * Extract subscription/config URL from API response (various shapes)
     *
     * @param  array  $userApiResponse  API response from Eylandoo
     * @return string|null The extracted URL or null if not found
     */
    public function extractSubscriptionUrl(array $userApiResponse): ?string
    {
        // Try various known response shapes for subscription URL
        $configUrl = null;

        // Shape 1: data.subscription_url
        if (isset($userApiResponse['data']['subscription_url'])) {
            $configUrl = $userApiResponse['data']['subscription_url'];
        }
        // Shape 2: data.users[0].config_url
        elseif (isset($userApiResponse['data']['users'][0]['config_url'])) {
            $configUrl = $userApiResponse['data']['users'][0]['config_url'];
        }
        // Shape 3: data.config_url
        elseif (isset($userApiResponse['data']['config_url'])) {
            $configUrl = $userApiResponse['data']['config_url'];
        }
        // Shape 4: data.users[0].subscription_url
        elseif (isset($userApiResponse['data']['users'][0]['subscription_url'])) {
            $configUrl = $userApiResponse['data']['users'][0]['subscription_url'];
        }

        return $configUrl;
    }

    /**
     * Build absolute subscription URL from API response
     */
    public function buildAbsoluteSubscriptionUrl(array $userApiResponse): string
    {
        $configUrl = $this->extractSubscriptionUrl($userApiResponse) ?? '';

        return $this->makeAbsoluteUrl($configUrl) ?? '';
    }

    /**
     * Convert a relative URL to absolute URL using base hostname
     *
     * @param  string|null  $url  URL to convert (can be relative or absolute)
     * @return string|null Absolute URL or null if input is null/empty
     */
    protected function makeAbsoluteUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        // If the URL is already absolute, return as is
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Use nodeHostname if set, otherwise fall back to baseUrl
        $baseHost = ! empty($this->nodeHostname) ? $this->nodeHostname : $this->baseUrl;

        // Ensure exactly one slash between hostname and path
        return rtrim($baseHost, '/').'/'.ltrim($url, '/');
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
     * List all available nodes
     *
     * @return array Array of nodes
     */
    public function listNodes(): array
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/api/v1/nodes');

            if ($response->successful()) {
                $data = $response->json();
                $nodes = $data['data']['nodes'] ?? [];

                // Map to simple format: id => name
                return array_map(function ($node) {
                    return [
                        'id' => $node['id'],
                        'name' => $node['name'],
                    ];
                }, $nodes);
            }

            Log::warning('Eylandoo List Nodes failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Eylandoo List Nodes Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * List all users
     *
     * @return array Array of users
     */
    public function listUsers(): array
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/api/v1/users/list_all');

            if ($response->successful()) {
                $data = $response->json();

                return $data['data']['users'] ?? [];
            }

            Log::warning('Eylandoo List Users failed:', ['status' => $response->status()]);

            return [];
        } catch (\Exception $e) {
            Log::error('Eylandoo List Users Exception:', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * List configs/users created by a specific admin (sub-admin)
     */
    public function listConfigsByAdmin(string $adminUsername): array
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/api/v1/users/list_all');

            if ($response->successful()) {
                $data = $response->json();
                $users = $data['data']['users'] ?? [];

                // Map users to our expected format
                $configs = array_map(function ($user) {
                    return [
                        'id' => $user['id'] ?? null,
                        'username' => $user['username'],
                        'status' => $user['status'] ?? 'active',
                        'used_traffic' => $user['data_used'] ?? 0,
                        'data_limit' => $user['data_limit'] ?? null,
                        'admin' => $user['sub_admin'] ?? null,
                        'owner_username' => $user['sub_admin'] ?? null,
                    ];
                }, $users);

                // Filter by admin username
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
