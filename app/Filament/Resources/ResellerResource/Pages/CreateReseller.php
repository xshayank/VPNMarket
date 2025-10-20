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

        // Treat config_limit of 0 as null (unlimited)
        if (isset($data['config_limit']) && $data['config_limit'] === 0) {
            $data['config_limit'] = null;
        }

        return $data;
    }
}
