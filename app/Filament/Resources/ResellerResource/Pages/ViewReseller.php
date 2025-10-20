<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components;

class ViewReseller extends ViewRecord
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Components\Section::make('اطلاعات کلی')
                    ->schema([
                        Components\TextEntry::make('user.name')
                            ->label('نام کاربر'),
                        Components\TextEntry::make('user.email')
                            ->label('ایمیل کاربر'),
                        Components\TextEntry::make('type')
                            ->label('نوع ریسلر')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'plan' => 'پلن‌محور',
                                'traffic' => 'ترافیک‌محور',
                                default => $state,
                            }),
                        Components\TextEntry::make('status')
                            ->label('وضعیت')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'suspended' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'active' => 'فعال',
                                'suspended' => 'معلق',
                                default => $state,
                            }),
                        Components\TextEntry::make('username_prefix')
                            ->label('پیشوند نام کاربری'),
                    ])
                    ->columns(2),

                Components\Section::make('تنظیمات ترافیک')
                    ->visible(fn ($record): bool => $record->type === 'traffic')
                    ->schema([
                        Components\TextEntry::make('panel.name')
                            ->label('پنل'),
                        Components\TextEntry::make('panel.panel_type')
                            ->label('نوع پنل')
                            ->badge(),
                        Components\TextEntry::make('traffic_total_bytes')
                            ->label('ترافیک کل')
                            ->formatStateUsing(fn ($state): string => $state ? round($state / (1024 * 1024 * 1024), 2).' GB' : '-'),
                        Components\TextEntry::make('traffic_used_bytes')
                            ->label('ترافیک استفاده شده')
                            ->formatStateUsing(fn ($state): string => $state ? round($state / (1024 * 1024 * 1024), 2).' GB' : '0 GB'),
                        Components\TextEntry::make('window_starts_at')
                            ->label('شروع بازه')
                            ->dateTime('Y-m-d H:i'),
                        Components\TextEntry::make('window_ends_at')
                            ->label('پایان بازه')
                            ->dateTime('Y-m-d H:i'),
                    ])
                    ->columns(2),

                Components\Section::make('تاریخچه')
                    ->schema([
                        Components\TextEntry::make('created_at')
                            ->label('تاریخ ایجاد')
                            ->dateTime('Y-m-d H:i:s'),
                        Components\TextEntry::make('updated_at')
                            ->label('آخرین ویرایش')
                            ->dateTime('Y-m-d H:i:s'),
                    ])
                    ->columns(2),
            ]);
    }
}
