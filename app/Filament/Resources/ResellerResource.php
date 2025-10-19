<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ResellerResource\Pages;
use App\Models\Plan;
use App\Models\Reseller;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ResellerResource extends Resource
{
    protected static ?string $model = Reseller::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'مدیریت کاربران';
    protected static ?string $navigationLabel = 'ریسلرها';
    protected static ?string $pluralModelLabel = 'ریسلرها';
    protected static ?string $modelLabel = 'ریسلر';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'email')
                    ->label('کاربر')
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true),

                Forms\Components\Select::make('type')
                    ->label('نوع ریسلر')
                    ->options([
                        'plan' => 'پلن‌محور',
                        'traffic' => 'ترافیک‌محور',
                    ])
                    ->required()
                    ->live()
                    ->default('plan'),

                Forms\Components\Select::make('status')
                    ->label('وضعیت')
                    ->options([
                        'active' => 'فعال',
                        'suspended' => 'معلق',
                    ])
                    ->required()
                    ->default('active'),

                Forms\Components\TextInput::make('username_prefix')
                    ->label('پیشوند نام کاربری')
                    ->helperText('اگر خالی باشد از پیشوند پیش‌فرض استفاده می‌شود'),

                Forms\Components\Section::make('تنظیمات ترافیک‌محور')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'traffic')
                    ->schema([
                        Forms\Components\TextInput::make('traffic_total_bytes')
                            ->label('ترافیک کل (GB)')
                            ->numeric()
                            ->helperText('مقدار را به گیگابایت وارد کنید')
                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                if ($state) {
                                    $set('traffic_total_bytes', $state * 1024 * 1024 * 1024);
                                }
                            }),

                        Forms\Components\DateTimePicker::make('window_starts_at')
                            ->label('تاریخ شروع'),

                        Forms\Components\DateTimePicker::make('window_ends_at')
                            ->label('تاریخ پایان'),

                        Forms\Components\TagsInput::make('marzneshin_allowed_service_ids')
                            ->label('سرویس‌های مجاز Marzneshin')
                            ->helperText('شناسه سرویس‌های مجاز (فقط برای Marzneshin)'),
                    ]),

                Forms\Components\Section::make('پلن‌های مجاز')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'plan')
                    ->schema([
                        Forms\Components\Repeater::make('allowedPlans')
                            ->relationship('allowedPlans')
                            ->label('پلن‌های مجاز')
                            ->schema([
                                Forms\Components\Select::make('id')
                                    ->label('پلن')
                                    ->options(Plan::where('reseller_visible', true)->pluck('name', 'id'))
                                    ->required(),
                                
                                Forms\Components\Select::make('pivot.override_type')
                                    ->label('نوع تخفیف')
                                    ->options([
                                        'price' => 'قیمت ثابت',
                                        'percent' => 'درصد تخفیف',
                                    ])
                                    ->live(),
                                
                                Forms\Components\TextInput::make('pivot.override_value')
                                    ->label('مقدار')
                                    ->numeric(),
                                
                                Forms\Components\Toggle::make('pivot.active')
                                    ->label('فعال')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->collapsible(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.email')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'plan' => 'پلن‌محور',
                        'traffic' => 'ترافیک‌محور',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
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

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع')
                    ->options([
                        'plan' => 'پلن‌محور',
                        'traffic' => 'ترافیک‌محور',
                    ]),
                
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'active' => 'فعال',
                        'suspended' => 'معلق',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
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
