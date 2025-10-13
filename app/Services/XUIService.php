<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class XUIService
{
    protected string $baseUrl;
    protected string $basePath;
    protected string $username;
    protected string $password;
    protected CookieJar $cookieJar;

    public function __construct(string $host, string $username, string $password)
    {
        $parsedUrl = parse_url(rtrim($host, '/'));
        $this->baseUrl = ($parsedUrl['scheme'] ?? 'http') . '://' . $parsedUrl['host'] . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '');
        $this->basePath = $parsedUrl['path'] ?? '';

        $this->username = $username;
        $this->password = $password;
        $this->cookieJar = new CookieJar();
    }

    private function getClient(): PendingRequest
    {
        return Http::withOptions([
            'cookies' => $this->cookieJar,
            'verify' => false,
        ]);
    }

    public function login(): bool
    {
        try {
            $loginApiUrl = $this->baseUrl . $this->basePath . '/login';
            $response = $this->getClient()->asForm()->post($loginApiUrl, [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            $isSuccess = $response->successful() && (
                    $response->json('success') === true ||
                    Str::contains($response->body(), 'Login successful') ||
                    $response->redirect()
                );

            if ($isSuccess) { Log::info('XUI Login successful.'); }
            else { Log::error('XUI Login Failed', ['url' => $loginApiUrl, 'status' => $response->status(), 'body' => $response->body()]); }
            return $isSuccess;
        } catch (\Exception $e) {
            Log::error('XUI Connection Exception:', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function addClient(int $inboundId, array $clientData): ?array
    {
        if (!$this->login()) {
            return ['success' => false, 'msg' => 'Authentication to X-UI panel failed.'];
        }

        try {
            $uuid = Str::uuid()->toString();
            $subId = Str::random(16);

            $clientSettings = [ 'id' => $uuid, 'email' => $clientData['email'], 'totalGB' => $clientData['total'], 'expiryTime' => $clientData['expiryTime'], 'enable' => true, 'tgId' => '', 'subId' => $subId, 'limitIp' => 0, 'flow' => '', ];
            $settings = json_encode(['clients' => [$clientSettings]]);

            $endpointsToTry = [ '/panel/api/inbounds/addClient', '/panel/inbound/addClient' ];
            $response = null;
            foreach ($endpointsToTry as $endpoint) {
                $addClientUrl = $this->baseUrl . $this->basePath . $endpoint;
                $currentResponse = $this->getClient()->asForm()->post($addClientUrl, [ 'id' => $inboundId, 'settings' => $settings, ]);
                Log::info('XUI addClient Attempt', ['url' => $addClientUrl, 'status' => $currentResponse->status()]);
                if ($currentResponse->status() != 404) {
                    $response = $currentResponse;
                    break;
                }
            }

            if (!$response) {
                return ['success' => false, 'msg' => 'Could not find a valid API endpoint to add client.'];
            }

            $responseData = $response->json();

            if (!$responseData || ($responseData['success'] ?? false) !== true) {
                return ['success' => false, 'msg' => 'Failed to create XUI client. Response: ' . ($response->body() ?: 'Empty Response')];
            }

            return array_merge($responseData, [ 'generated_uuid' => $uuid, 'generated_subId' => $subId ]);

        } catch (\Exception $e) {
            Log::error('Error creating XUI client', ['exception' => $e->getMessage()]);
            return ['success' => false, 'msg' => 'Error creating client: ' . $e->getMessage()];
        }
    }

    public function updateClient(int $inboundId, string $clientId, array $clientData): ?array
    {
        if (!$this->login()) {
            return ['success' => false, 'msg' => 'Authentication failed.'];
        }

        try {

            $clientSettings = [
                'id' => $clientId,
                'email' => $clientData['email'],
                'totalGB' => $clientData['total'],
                'expiryTime' => $clientData['expiryTime'],
                'enable' => true,
                'tgId' => '',
                'subId' => $clientData['subId'] ?? Str::random(16),
                'limitIp' => 0,
                'flow' => '',
            ];
            $settings = json_encode(['clients' => [$clientSettings]]);


            $updateClientUrl = $this->baseUrl . $this->basePath . "/panel/inbound/updateClient/{$clientId}";

            $response = $this->getClient()->asForm()->post($updateClientUrl, [
                'id' => $inboundId,
                'settings' => $settings,
            ]);

            Log::info('XUI updateClient response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();

        } catch (\Exception $e) {
            Log::error('Error updating XUI client', ['exception' => $e->getMessage()]);
            return ['success' => false, 'msg' => 'Error updating client: ' . $e->getMessage()];
        }
    }
}
