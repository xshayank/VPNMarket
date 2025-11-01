<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static ?string $modelLabel = 'Audit Log';

    protected static ?string $pluralModelLabel = 'Audit Logs';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 99;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date/Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'created') => 'success',
                        str_contains($state, 'disabled') => 'warning',
                        str_contains($state, 'enabled') => 'success',
                        str_contains($state, 'suspended') => 'danger',
                        str_contains($state, 'deleted') => 'danger',
                        str_contains($state, 'recharged') => 'info',
                        str_contains($state, 'extended') => 'info',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('actor_id')
                    ->label('Actor')
                    ->formatStateUsing(function (AuditLog $record): string {
                        if ($record->actor_id && $record->actor_type) {
                            $actorClass = class_basename($record->actor_type);
                            return "{$actorClass} #{$record->actor_id}";
                        }
                        return 'System';
                    })
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_type')
                    ->label('Target Type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_id')
                    ->label('Target ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'traffic_exceeded' => 'danger',
                        'time_expired' => 'danger',
                        'reseller_quota_exhausted' => 'warning',
                        'reseller_window_expired' => 'warning',
                        'reseller_recovered' => 'success',
                        'admin_action' => 'info',
                        default => 'gray',
                    })
                    ->searchable(),

                Tables\Columns\IconColumn::make('meta.remote_success')
                    ->label('Remote OK')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('meta.attempts')
                    ->label('Attempts')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('ip')
                    ->label('IP Address')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Action')
                    ->options([
                        'reseller_created' => 'Reseller Created',
                        'reseller_suspended' => 'Reseller Suspended',
                        'reseller_activated' => 'Reseller Activated',
                        'reseller_recharged' => 'Reseller Recharged',
                        'reseller_window_extended' => 'Reseller Window Extended',
                        'config_manual_disabled' => 'Config Manual Disabled',
                        'config_auto_disabled' => 'Config Auto Disabled',
                        'config_manual_enabled' => 'Config Manual Enabled',
                        'config_auto_enabled' => 'Config Auto Enabled',
                        'config_expired' => 'Config Expired',
                        'config_deleted' => 'Config Deleted',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('target_type')
                    ->label('Target Type')
                    ->options([
                        'reseller' => 'Reseller',
                        'config' => 'Config',
                        'panel' => 'Panel',
                        'plan' => 'Plan',
                    ]),

                Tables\Filters\SelectFilter::make('reason')
                    ->label('Reason')
                    ->options([
                        'traffic_exceeded' => 'Traffic Exceeded',
                        'time_expired' => 'Time Expired',
                        'reseller_quota_exhausted' => 'Reseller Quota Exhausted',
                        'reseller_window_expired' => 'Reseller Window Expired',
                        'reseller_recovered' => 'Reseller Recovered',
                        'admin_action' => 'Admin Action',
                    ])
                    ->multiple(),

                Tables\Filters\TernaryFilter::make('meta->remote_success')
                    ->label('Remote Success')
                    ->queries(
                        true: fn (Builder $query) => $query->whereJsonContains('meta->remote_success', true),
                        false: fn (Builder $query) => $query->whereJsonContains('meta->remote_success', false),
                    ),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('From Date'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('Until Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalContent(function (AuditLog $record) {
                        return view('filament.resources.audit-log.view-modal', [
                            'record' => $record,
                        ]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\ExportBulkAction::make()
                        ->label('Export to CSV'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
