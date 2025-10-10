<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

class XUIService
{
    protected string $panelFullUrl;
    protected string $username;
    protected string $password;
    protected ?PendingRequest $client = null;

    public function __construct(string $host, string $username, string $password)
    {
        $this->panelFullUrl = rtrim($host, '/');
        $this->username = $username;
        $this->password = $password;

        $this->client = Http::withOptions([
            'cookies' => new CookieJar(),
            'verify' => false
        ]);
    }

    public function login(): bool
    {
        try {
            $loginApiUrl = $this->panelFullUrl . '/login';
            $response = $this->client->asForm()->post($loginApiUrl, [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            $result = $response->successful() && $response->json('success');

            if (!$result) {
                Log::error('XUI Login Failed', [
                    'response' => $response->json(),
                    'url' => $loginApiUrl
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('XUI Connection Exception:', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function addClient(int $inboundId, array $clientData): ?array
    {
        if (!$this->login()) {
            return ['success' => false, 'msg' => 'Authentication failed.'];
        }

        // بررسی وجود همه پارامترهای ضروری
        $requiredFields = ['email', 'expiryTime', 'total'];
        foreach ($requiredFields as $field) {
            if (!isset($clientData[$field])) {
                Log::error("Missing required field: $field", [
                    'client_data' => $clientData,
                    'required_fields' => $requiredFields
                ]);
                return ['success' => false, 'msg' => "Missing required field: $field"];
            }
        }

        $uuid = Str::uuid()->toString();
        $subId = Str::random(16);



        $clientSettings = [
            'id' => $uuid,
            'email' => $clientData['email'],
            'totalGB' => $clientData['total'],
            'expiryTime' => $clientData['expiryTime'],
            'enable' => true,
            'tgId' => '',
            'subId' => $subId,
            'limitIp' => 0,
            'flow' => '',
        ];

        Log::info('Creating XUI client', [
            'client_settings' => $clientSettings,

            'volume_gb' => isset($clientData['total']) ? $clientData['total'] / 1073741824 : null,
        ]);


        $settings = json_encode(['clients' => [$clientSettings]]);

        $addClientUrl = $this->panelFullUrl . '/panel/inbound/addClient';

        try {
            $response = $this->client->asForm()->post($addClientUrl, [
                'id' => $inboundId,
                'settings' => $settings,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Invalid JSON response', [
                    'response_body' => $response->getBody()->getContents(),
                    'json_error' => json_last_error_msg()
                ]);
                return ['success' => false, 'msg' => 'Invalid response from X-UI'];
            }

            if (!isset($responseData['success']) || !$responseData['success']) {
                Log::error('Failed to create XUI client', [
                    'response' => $responseData,
                    'request_data' => [
                        'id' => $inboundId,
                        'settings' => $settings
                    ]
                ]);
                return $responseData;
            }

            Log::info('XUI client created successfully', [
                'client_id' => $uuid,
                'response' => $responseData
            ]);

            return array_merge($responseData, [
                'generated_uuid' => $uuid,
                'generated_subId' => $subId
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating XUI client', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'client_data' => $clientData,
                'settings' => $settings
            ]);
            return ['success' => false, 'msg' => 'Error creating client: ' . $e->getMessage()];
        }
    }


    public function updateClient(int $inboundId, string $clientId, array $clientData): ?array
    {
        if (!$this->login()) {
            return ['success' => false, 'msg' => 'Authentication failed.'];
        }


        $clientSettings = [
            'id' => $clientId, // UUID کلاینت موجود
            'email' => $clientData['email'],
            'total' => $clientData['total'],
            'expiryTime' => $clientData['expiryTime'],
            'enable' => true,
            'tgId' => '',
            'subId' => $clientData['subId'] ?? Str::random(16),
            'limitIp' => 0,
            'flow' => '',
        ];

        $settings = json_encode(['clients' => [$clientSettings]]);

        // آدرس API برای آپدیت کلاینت
        $updateClientUrl = $this->panelFullUrl . "/panel/inbound/updateClient/{$clientId}";

        $response = $this->client->asForm()->post($updateClientUrl, [
            'id' => $inboundId,
            'settings' => $settings,
        ]);

        return $response->json();
    }



}
