<?php

namespace Modules\Ticketing\Filament\Resources\TicketResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Modules\Ticketing\Filament\Resources\TicketResource;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('همه')
                ->badge(fn () => \Modules\Ticketing\Models\Ticket::count()),
            
            'open' => Tab::make('باز')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'open'))
                ->badge(fn () => \Modules\Ticketing\Models\Ticket::where('status', 'open')->count()),
            
            'reseller' => Tab::make('ریسلر')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('source', 'reseller'))
                ->badge(fn () => \Modules\Ticketing\Models\Ticket::where('source', 'reseller')->count())
                ->badgeColor('info'),
            
            'telegram' => Tab::make('تلگرام')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('source', 'telegram'))
                ->badge(fn () => \Modules\Ticketing\Models\Ticket::where('source', 'telegram')->count())
                ->badgeColor('primary'),
        ];
    }
}
