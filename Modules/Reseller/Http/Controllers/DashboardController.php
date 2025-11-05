<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Fetch reseller with fresh data from DB to ensure traffic_used_bytes is current
        $reseller = $request->user()->reseller()->first();

        if ($reseller->isPlanBased()) {
            $stats = [
                'balance' => $request->user()->balance,
                'total_orders' => $reseller->orders()->count(),
                'fulfilled_orders' => $reseller->orders()->where('status', 'fulfilled')->count(),
                'total_accounts' => $reseller->orders()->where('status', 'fulfilled')->sum('quantity'),
                'recent_orders' => $reseller->orders()->latest()->take(5)->with('plan')->get(),
            ];
        } else {
            $totalConfigs = $reseller->configs()->count();
            $configLimit = $reseller->config_limit;
            $isUnlimitedLimit = is_null($configLimit) || $configLimit === 0;
            $configsRemaining = $isUnlimitedLimit ? null : max($configLimit - $totalConfigs, 0);

            // Use getCurrentTrafficUsedBytes() for display (excludes settled usage)
            // This shows current cycle usage to resellers
            // Note: traffic_used_bytes includes settled and is used for quota enforcement
            $currentUsageBytes = $reseller->getCurrentTrafficUsedBytes();

            $stats = [
                'traffic_total_gb' => $reseller->traffic_total_bytes ? round($reseller->traffic_total_bytes / (1024 * 1024 * 1024), 2) : 0,
                'traffic_used_gb' => round($currentUsageBytes / (1024 * 1024 * 1024), 2),
                'traffic_remaining_gb' => $reseller->traffic_total_bytes ? round(($reseller->traffic_total_bytes - $reseller->traffic_used_bytes) / (1024 * 1024 * 1024), 2) : 0,
                'window_starts_at' => $reseller->window_starts_at,
                'window_ends_at' => $reseller->window_ends_at,
                'days_remaining' => $reseller->window_ends_at ? now()->diffInDays($reseller->window_ends_at, false) : null,
                'active_configs' => $reseller->configs()->where('status', 'active')->count(),
                'total_configs' => $totalConfigs,
                'recent_configs' => $reseller->configs()->latest()->take(10)->get(),
                'config_limit' => $configLimit,
                'configs_remaining' => $configsRemaining,
                'is_unlimited_limit' => $isUnlimitedLimit,
            ];

            Log::info('Reseller dashboard loaded', [
                'reseller_id' => $reseller->id,
                'current_usage_bytes' => $currentUsageBytes,
                'current_usage_gb' => $stats['traffic_used_gb'],
                'total_usage_with_settled_bytes' => $reseller->traffic_used_bytes,
            ]);
        }

        return view('reseller::dashboard', [
            'reseller' => $reseller,
            'stats' => $stats,
        ]);
    }
}
