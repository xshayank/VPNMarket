<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReseller extends CreateRecord
{
    protected static string $resource = ResellerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Convert traffic GB to bytes if type is traffic
        if ($data['type'] === 'traffic' && isset($data['traffic_total_gb'])) {
            $data['traffic_total_bytes'] = (int) ($data['traffic_total_gb'] * 1024 * 1024 * 1024);
            unset($data['traffic_total_gb']);
        }

        // Handle window_days - calculate window dates automatically
        if ($data['type'] === 'traffic' && isset($data['window_days']) && $data['window_days'] > 0) {
            $windowDays = (int) $data['window_days'];
            $data['window_starts_at'] = now();
            $data['window_ends_at'] = now()->addDays($windowDays);
            unset($data['window_days']); // Remove virtual field
        }

        // Treat config_limit of 0 as null (unlimited)
        if (isset($data['config_limit']) && $data['config_limit'] === 0) {
            $data['config_limit'] = null;
        }

        return $data;
    }
}
