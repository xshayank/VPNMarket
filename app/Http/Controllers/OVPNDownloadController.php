<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ResellerConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OVPNDownloadController extends Controller
{
    /**
     * Download .ovpn file using tokenized URL (public route)
     * GET /ovpn/{token}
     */
    public function downloadByToken(string $token): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        // Find config by token
        $config = ResellerConfig::where('ovpn_token', $token)->first();

        if (!$config) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Check if token is expired
        if (!$config->isOvpnTokenValid()) {
            return response()->json(['error' => 'Token expired'], 403);
        }

        // Check if ovpn file exists
        if (!$config->ovpn_path || !Storage::exists($config->ovpn_path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Log the download
        AuditLog::log(
            action: 'config_ovpn_downloaded',
            targetType: ResellerConfig::class,
            targetId: $config->id,
            meta: [
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_type' => $config->panel_type,
                'filename' => $config->external_username . '.ovpn',
                'token_used' => true,
            ]
        );

        // Stream the file
        return $this->streamOvpnFile($config);
    }

    /**
     * Download .ovpn file for authenticated reseller
     * GET /reseller/configs/{id}/ovpn
     */
    public function downloadForReseller(Request $request, int $id): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $config = ResellerConfig::findOrFail($id);

        // Check authorization - must be config owner or admin
        $user = $request->user();
        
        // If user is not an admin, ensure they own the config through their reseller account
        if (!$user->is_admin) {
            $reseller = $user->reseller ?? null;
            
            if (!$reseller || $config->reseller_id !== $reseller->id) {
                abort(403, 'Unauthorized');
            }
        }

        // Check if this is an ovpanel config
        if (!$config->isOvpanel()) {
            return response()->json(['error' => 'Not an OV-Panel config'], 400);
        }

        // Check if ovpn file exists
        if (!$config->ovpn_path || !Storage::exists($config->ovpn_path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Log the download
        AuditLog::log(
            action: 'config_ovpn_downloaded',
            targetType: ResellerConfig::class,
            targetId: $config->id,
            meta: [
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_type' => $config->panel_type,
                'filename' => $config->external_username . '.ovpn',
                'authenticated' => true,
            ]
        );

        // Stream the file
        return $this->streamOvpnFile($config);
    }

    /**
     * Stream the .ovpn file with appropriate headers
     */
    protected function streamOvpnFile(ResellerConfig $config): StreamedResponse
    {
        $filename = $config->external_username . '.ovpn';
        $path = $config->ovpn_path;

        return Storage::download($path, $filename, [
            'Content-Type' => 'application/x-openvpn-profile',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
