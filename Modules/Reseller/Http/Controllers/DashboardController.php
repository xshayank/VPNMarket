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
        } elseif ($reseller->isWalletBased()) {
            // Wallet-based reseller stats
            $totalConfigs = $reseller->configs()->count();
            $configLimit = $reseller->config_limit;
            $isUnlimitedLimit = is_null($configLimit) || $configLimit === 0;
            $configsRemaining = $isUnlimitedLimit ? null : max($configLimit - $totalConfigs, 0);

            // Calculate total traffic consumed
            $configs = $reseller->configs()->get();
            $trafficConsumedBytes = $configs->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

            $stats = [
                'wallet_balance' => $reseller->wallet_balance,
                'wallet_price_per_gb' => $reseller->getWalletPricePerGb(),
                'traffic_consumed_bytes' => $trafficConsumedBytes,
                'traffic_consumed_gb' => round($trafficConsumedBytes / (1024 * 1024 * 1024), 2),
                'active_configs' => $reseller->configs()->where('status', 'active')->count(),
                'total_configs' => $totalConfigs,
                'recent_configs' => $reseller->configs()->latest()->take(10)->get(),
                'config_limit' => $configLimit,
                'configs_remaining' => $configsRemaining,
                'is_unlimited_limit' => $isUnlimitedLimit,
            ];

            Log::info('Wallet-based reseller dashboard loaded', [
                'reseller_id' => $reseller->id,
                'wallet_balance' => $reseller->wallet_balance,
                'traffic_consumed_gb' => $stats['traffic_consumed_gb'],
            ]);
        } else {
            $totalConfigs = $reseller->configs()->count();
            $configLimit = $reseller->config_limit;
            $isUnlimitedLimit = is_null($configLimit) || $configLimit === 0;
            $configsRemaining = $isUnlimitedLimit ? null : max($configLimit - $totalConfigs, 0);

            // Compute single traffic consumed value: current + settled usage
            $configs = $reseller->configs()->get();
            $trafficCurrentBytes = $configs->sum('usage_bytes');
            $trafficSettledBytes = $configs->sum(function ($config) {
                return (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });
            $trafficConsumedBytes = $trafficCurrentBytes + $trafficSettledBytes;

            $stats = [
                'traffic_total_gb' => $reseller->traffic_total_bytes ? round($reseller->traffic_total_bytes / (1024 * 1024 * 1024), 2) : 0,
                'traffic_consumed_bytes' => $trafficConsumedBytes,
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
                'traffic_current_bytes' => $trafficCurrentBytes,
                'traffic_settled_bytes' => $trafficSettledBytes,
                'traffic_consumed_bytes' => $trafficConsumedBytes,
                'traffic_consumed_gb' => round($trafficConsumedBytes / (1024 * 1024 * 1024), 2),
            ]);
        }

        return view('reseller::dashboard', [
            'reseller' => $reseller,
            'stats' => $stats,
        ]);
    }
}
