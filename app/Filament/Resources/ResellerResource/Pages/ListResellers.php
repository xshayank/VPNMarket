<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use App\Filament\Widgets\ResellerStatsWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListResellers extends ListRecords
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ResellerStatsWidget::class,
        ];
    }
}
