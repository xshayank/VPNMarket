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
            $stats = [
                'balance' => $request->user()->balance,
                'total_orders' => $reseller->orders()->count(),
                'fulfilled_orders' => $reseller->orders()->where('status', 'fulfilled')->count(),
                'total_accounts' => $reseller->orders()->where('status', 'fulfilled')->sum('quantity'),
                'recent_orders' => $reseller->orders()->latest()->take(5)->with('plan')->get(),
            ];
        } else {
            $stats = [
                'traffic_total_gb' => $reseller->traffic_total_bytes ? round($reseller->traffic_total_bytes / (1024 * 1024 * 1024), 2) : 0,
                'traffic_used_gb' => round($reseller->traffic_used_bytes / (1024 * 1024 * 1024), 2),
                'traffic_remaining_gb' => $reseller->traffic_total_bytes ? round(($reseller->traffic_total_bytes - $reseller->traffic_used_bytes) / (1024 * 1024 * 1024), 2) : 0,
                'window_starts_at' => $reseller->window_starts_at,
                'window_ends_at' => $reseller->window_ends_at,
                'days_remaining' => $reseller->window_ends_at ? now()->diffInDays($reseller->window_ends_at, false) : null,
                'active_configs' => $reseller->configs()->where('status', 'active')->count(),
                'total_configs' => $reseller->configs()->count(),
                'recent_configs' => $reseller->configs()->latest()->take(10)->get(),
            ];
        }

        return view('reseller::dashboard', [
            'reseller' => $reseller,
            'stats' => $stats,
        ]);
    }
}
