<?php

namespace App\Filament\Resources\WalletTopUpTransactionResource\Pages;

use App\Filament\Resources\WalletTopUpTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletTopUpTransactions extends ListRecords
{
    protected static string $resource = WalletTopUpTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action needed as transactions are created automatically
        ];
    }
}
