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
        if ($data['type'] === 'traffic' && isset($data['traffic_total_bytes'])) {
            // Already converted in the form
        }

        return $data;
    }
}
