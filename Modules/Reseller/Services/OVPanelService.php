<?php

namespace Modules\Reseller\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class OVPanelService
{
    /**
     * Magic date used to disable users in OV-Panel
     */
    private const DISABLE_DATE = '2000-01-01';

    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected ?string $token = null;

    protected Client $client;

    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'verify' => false, // Consider making this configurable
        ]);
    }

    /**
     * Authenticate and obtain JWT token
     */
    public function login(): bool
    {
        try {
            $response = $this->client->post('/api/login', [
                'form_params' => [
                    'username' => $this->username,
                    'password' => $this->password,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['access_token'])) {
                $this->token = $data['access_token'];

                return true;
            }

            Log::error('OVPanel login failed: No access token in response');

            return false;
        } catch (GuzzleException $e) {
            Log::error('OVPanel login error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Create a new user and retrieve .ovpn content
     *
     * @param  array  $payload  ['name' => string, 'expiry_date' => string (ISO)]
     * @return array ['success' => bool, 'user_id' => ?string, 'ovpn_content' => ?string, 'error' => ?string]
     */
    public function createUser(array $payload): array
    {
        if (! $this->token && ! $this->login()) {
            return ['success' => false, 'user_id' => null, 'ovpn_content' => null, 'error' => 'Authentication failed'];
        }

        try {
            // Create user
            $response = $this->client->post('/api/user/create', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'name' => $payload['name'],
                    'expiry_date' => $payload['expiry_date'],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (! isset($result['success']) || ! $result['success']) {
                return [
                    'success' => false,
                    'user_id' => null,
                    'ovpn_content' => null,
                    'error' => $result['msg'] ?? 'User creation failed',
                ];
            }

            $username = $result['data'] ?? $payload['name'];

            // Download .ovpn file
            $ovpnContent = $this->downloadOvpn($username);

            if (! $ovpnContent) {
                return [
                    'success' => false,
                    'user_id' => $username,
                    'ovpn_content' => null,
                    'error' => 'Failed to download .ovpn file',
                ];
            }

            return [
                'success' => true,
                'user_id' => $username,
                'ovpn_content' => $ovpnContent,
                'error' => null,
            ];
        } catch (GuzzleException $e) {
            Log::error('OVPanel createUser error: '.$e->getMessage());

            return [
                'success' => false,
                'user_id' => null,
                'ovpn_content' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enable a user
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function enableUser(string $userId): array
    {
        if (! $this->token && ! $this->login()) {
            return ['success' => false, 'attempts' => 1, 'last_error' => 'Authentication failed'];
        }

        try {
            $response = $this->client->put('/api/user/update', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'name' => $userId,
                    'expiry_date' => null, // null keeps existing expiry according to OV-Panel API
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => isset($result['success']) && $result['success'],
                'attempts' => 1,
                'last_error' => $result['msg'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('OVPanel enableUser error: '.$e->getMessage());

            return [
                'success' => false,
                'attempts' => 1,
                'last_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Disable a user
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function disableUser(string $userId): array
    {
        // OV-Panel doesn't have a direct disable endpoint
        // We simulate disable by setting expiry to past date
        if (! $this->token && ! $this->login()) {
            return ['success' => false, 'attempts' => 1, 'last_error' => 'Authentication failed'];
        }

        try {
            $response = $this->client->put('/api/user/update', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'name' => $userId,
                    'expiry_date' => self::DISABLE_DATE, // Set to past date to disable
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => isset($result['success']) && $result['success'],
                'attempts' => 1,
                'last_error' => $result['msg'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('OVPanel disableUser error: '.$e->getMessage());

            return [
                'success' => false,
                'attempts' => 1,
                'last_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a user
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function deleteUser(string $userId): array
    {
        if (! $this->token && ! $this->login()) {
            return ['success' => false, 'attempts' => 1, 'last_error' => 'Authentication failed'];
        }

        try {
            $response = $this->client->delete("/api/user/delete/{$userId}", [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => isset($result['success']) && $result['success'],
                'attempts' => 1,
                'last_error' => $result['msg'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('OVPanel deleteUser error: '.$e->getMessage());

            return [
                'success' => false,
                'attempts' => 1,
                'last_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get usage for a user
     *
     * @return int|null Total bytes used (up + down) or null on failure
     */
    public function getUsage(string $userId): ?int
    {
        if (! $this->token && ! $this->login()) {
            return null;
        }

        try {
            // Get all users and find the specific one
            $response = $this->client->get('/api/user/all', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (! isset($result['success']) || ! $result['success'] || ! isset($result['data'])) {
                return null;
            }

            foreach ($result['data'] as $user) {
                if ($user['name'] === $userId) {
                    // OV-Panel doesn't seem to have usage in the API doc
                    // Return 0 for now or implement if available in actual API
                    return 0;
                }
            }

            return null;
        } catch (GuzzleException $e) {
            Log::error('OVPanel getUsage error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Refresh .ovpn file for a user
     *
     * @return array ['success' => bool, 'ovpn_content' => ?string, 'error' => ?string]
     */
    public function refreshOvpn(string $userId): array
    {
        if (! $this->token && ! $this->login()) {
            return ['success' => false, 'ovpn_content' => null, 'error' => 'Authentication failed'];
        }

        $ovpnContent = $this->downloadOvpn($userId);

        if (! $ovpnContent) {
            return ['success' => false, 'ovpn_content' => null, 'error' => 'Failed to download .ovpn file'];
        }

        return ['success' => true, 'ovpn_content' => $ovpnContent, 'error' => null];
    }

    /**
     * Download .ovpn file content for a user
     */
    protected function downloadOvpn(string $username): ?string
    {
        if (! $this->token) {
            return null;
        }

        try {
            $response = $this->client->get("/api/user/download/ovpn/{$username}", [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                ],
            ]);

            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            Log::error('OVPanel downloadOvpn error: '.$e->getMessage());

            return null;
        }
    }
}
