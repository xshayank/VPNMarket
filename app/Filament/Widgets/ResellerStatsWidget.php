<?php

namespace App\Filament\Widgets;

use App\Models\Reseller;
use App\Models\ResellerConfig;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ResellerStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $totalResellers = Reseller::count();
        $activeResellers = Reseller::where('status', 'active')->count();
        $suspendedResellers = Reseller::where('status', 'suspended')->count();

        // Calculate traffic statistics for traffic-based resellers
        $trafficResellers = Reseller::where('type', 'traffic')->get();
        $totalTrafficBytes = $trafficResellers->sum('traffic_total_bytes');
        $usedTrafficBytes = $trafficResellers->sum('traffic_used_bytes');
        $remainingTrafficBytes = $totalTrafficBytes - $usedTrafficBytes;

        // Format bytes to GB
        $totalTrafficGB = round($totalTrafficBytes / (1024 * 1024 * 1024), 2);
        $usedTrafficGB = round($usedTrafficBytes / (1024 * 1024 * 1024), 2);
        $remainingTrafficGB = round($remainingTrafficBytes / (1024 * 1024 * 1024), 2);

        // Count active configs
        $activeConfigs = ResellerConfig::where('status', 'active')->count();
        $totalConfigs = ResellerConfig::count();

        return [
            Stat::make('کل ریسلرها', $totalResellers)
                ->description("فعال: {$activeResellers} | معلق: {$suspendedResellers}")
                ->descriptionIcon('heroicon-m-user-group')
                ->color('info'),

            Stat::make('ترافیک استفاده شده', number_format($usedTrafficGB, 2).' GB')
                ->description("از کل: ".number_format($totalTrafficGB, 2).' GB')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning'),

            Stat::make('ترافیک باقیمانده', number_format($remainingTrafficGB, 2).' GB')
                ->description('ترافیک قابل استفاده')
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color('success'),

            Stat::make('کانفیگ‌های فعال', $activeConfigs)
                ->description("از کل: {$totalConfigs}")
                ->descriptionIcon('heroicon-m-signal')
                ->color('success'),
        ];
    }
}
