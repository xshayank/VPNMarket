<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ResellerResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Reseller\Models\Reseller;

class ResellerResource extends Resource
{
    protected static ?string $model = Reseller::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'مدیریت کاربران';

    protected static ?string $navigationLabel = 'ریسلرها';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('نوع')
                    ->options([
                        'plan' => 'پلن',
                        'traffic' => 'ترافیک',
                    ])
                    ->required()
                    ->reactive(),
                Forms\Components\Select::make('status')
                    ->label('وضعیت')
                    ->options([
                        'active' => 'فعال',
                        'suspended' => 'مسدود',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('username_prefix')
                    ->label('پیشوند نام کاربری')
                    ->maxLength(50),
                Forms\Components\TextInput::make('traffic_total_bytes')
                    ->label('سهمیه ترافیک (GB)')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (callable $get) => $get('type') === 'traffic')
                    ->formatStateUsing(function ($state) {
                        if ($state === null) {
                            return null;
                        }

                        return round($state / (1024 ** 3), 2);
                    })
                    ->dehydrateStateUsing(function ($state) {
                        if ($state === null || $state === '') {
                            return null;
                        }

                        return (int) ($state * 1024 * 1024 * 1024);
                    }),
                Forms\Components\DateTimePicker::make('window_starts_at')
                    ->label('شروع پنجره')
                    ->seconds(false)
                    ->visible(fn (callable $get) => $get('type') === 'traffic'),
                Forms\Components\DateTimePicker::make('window_ends_at')
                    ->label('پایان پنجره')
                    ->seconds(false)
                    ->visible(fn (callable $get) => $get('type') === 'traffic'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('کاربر')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('type')->label('نوع'),
                Tables\Columns\TextColumn::make('status')->label('وضعیت'),
                Tables\Columns\TextColumn::make('traffic_total_bytes')
                    ->label('سهمیه (GB)')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / (1024 ** 3), 2) : '-'),
                Tables\Columns\TextColumn::make('window_ends_at')->label('پایان پنجره')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResellers::route('/'),
            'create' => Pages\CreateReseller::route('/create'),
            'edit' => Pages\EditReseller::route('/{record}/edit'),
        ];
    }
}
