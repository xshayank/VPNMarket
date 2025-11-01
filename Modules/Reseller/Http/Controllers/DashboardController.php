<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $reseller = $request->user()->reseller;

        if ($reseller->isPlanBased()) {
            // Optimize: Use single query with aggregation instead of multiple queries
            $orderStats = $reseller->orders()
                ->selectRaw('COUNT(*) as total_orders')
                ->selectRaw('COUNT(CASE WHEN status = "fulfilled" THEN 1 END) as fulfilled_orders')
                ->selectRaw('SUM(CASE WHEN status = "fulfilled" THEN quantity ELSE 0 END) as total_accounts')
                ->first();

            $stats = [
                'balance' => $request->user()->balance,
                'total_orders' => $orderStats->total_orders ?? 0,
                'fulfilled_orders' => $orderStats->fulfilled_orders ?? 0,
                'total_accounts' => $orderStats->total_accounts ?? 0,
                'recent_orders' => $reseller->orders()->latest()->take(5)->with('plan')->get(),
            ];
        } else {
            // Optimize: Use single query with aggregation for counts
            $configStats = $reseller->configs()
                ->selectRaw('COUNT(*) as total_configs')
                ->selectRaw('COUNT(CASE WHEN status = "active" THEN 1 END) as active_configs')
                ->first();

            $totalConfigs = $configStats->total_configs ?? 0;
            $configLimit = $reseller->config_limit;
            $isUnlimitedLimit = is_null($configLimit) || $configLimit === 0;
            $configsRemaining = $isUnlimitedLimit ? null : max($configLimit - $totalConfigs, 0);

            $stats = [
                'traffic_total_gb' => $reseller->traffic_total_bytes ? round($reseller->traffic_total_bytes / (1024 * 1024 * 1024), 2) : 0,
                'traffic_used_gb' => round($reseller->traffic_used_bytes / (1024 * 1024 * 1024), 2),
                'traffic_remaining_gb' => $reseller->traffic_total_bytes ? round(($reseller->traffic_total_bytes - $reseller->traffic_used_bytes) / (1024 * 1024 * 1024), 2) : 0,
                'window_starts_at' => $reseller->window_starts_at,
                'window_ends_at' => $reseller->window_ends_at,
                'days_remaining' => $reseller->window_ends_at ? now()->diffInDays($reseller->window_ends_at, false) : null,
                'active_configs' => $configStats->active_configs ?? 0,
                'total_configs' => $totalConfigs,
                'recent_configs' => $reseller->configs()->latest()->take(10)->get(),
                'config_limit' => $configLimit,
                'configs_remaining' => $configsRemaining,
                'is_unlimited_limit' => $isUnlimitedLimit,
            ];
        }

        return view('reseller::dashboard', [
            'reseller' => $reseller,
            'stats' => $stats,
        ]);
    }
}
