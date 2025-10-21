<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ResellerResource\Pages;
use App\Filament\Resources\ResellerResource\RelationManagers;
use App\Models\Plan;
use App\Models\Reseller;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
                    ->relationship('user', 'name')
                    ->label('کاربر')
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->getSearchResultsUsing(fn (string $search) => \App\Models\User::query()
                        ->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                        ->orWhere('email', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn ($user) => [
                            $user->id => ($user->name ?? 'بدون نام').' ('.$user->email.')',
                        ])
                    )
                    ->getOptionLabelFromRecordUsing(fn ($record) => ($record->name ?? 'بدون نام').' ('.$record->email.')'
                    )
                    ->preload()
                    ->loadingMessage('در حال بارگذاری کاربران...')
                    ->noSearchResultsMessage('کاربری یافت نشد')
                    ->searchPrompt('جستجو بر اساس نام یا ایمیل'),

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
                        Forms\Components\Select::make('panel_id')
                            ->label('پنل')
                            ->relationship('panel', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required()
                            ->helperText('پنل V2Ray که این ریسلر از آن استفاده می‌کند'),

                        Forms\Components\TextInput::make('traffic_total_gb')
                            ->label('ترافیک کل (GB)')
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->maxValue(10000000)
                            ->helperText('مقدار را به گیگابایت وارد کنید (حداکثر: 10,000,000 GB)'),

                        Forms\Components\TextInput::make('config_limit')
                            ->label('محدودیت تعداد کانفیگ')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('تعداد کانفیگ‌هایی که می‌توان ایجاد کرد. 0 یا خالی = نامحدود'),

                        Forms\Components\DateTimePicker::make('window_starts_at')
                            ->label('تاریخ شروع (اختیاری)')
                            ->helperText('اگر خالی باشد، محدودیت زمانی ندارد'),

                        Forms\Components\DateTimePicker::make('window_ends_at')
                            ->label('تاریخ پایان (اختیاری)')
                            ->helperText('اگر خالی باشد، محدودیت زمانی ندارد'),

                        Forms\Components\Section::make('سرویسهای مرزنشین (Marzneshin)')
                            ->description('سرویسهای مرزنشین مجاز برای این ریسلر')
                            ->collapsed()
                            ->visible(function (Forms\Get $get) {
                                $panelId = $get('panel_id');
                                if (! $panelId) {
                                    return false;
                                }

                                $panel = \App\Models\Panel::find($panelId);

                                return $panel && $panel->panel_type === 'marzneshin';
                            })
                            ->schema([
                                Forms\Components\CheckboxList::make('marzneshin_allowed_service_ids')
                                    ->label('انتخاب سرویس‌ها')
                                    ->options(function (Forms\Get $get) {
                                        $panelId = $get('panel_id');
                                        if (! $panelId) {
                                            return [];
                                        }

                                        $panel = \App\Models\Panel::find($panelId);
                                        if (! $panel || $panel->panel_type !== 'marzneshin') {
                                            return [];
                                        }

                                        try {
                                            $credentials = $panel->getCredentials();
                                            $nodeHostname = $credentials['extra']['node_hostname'] ?? '';

                                            $marzneshinService = new \App\Services\MarzneshinService(
                                                $credentials['url'],
                                                $credentials['username'],
                                                $credentials['password'],
                                                $nodeHostname
                                            );

                                            $services = $marzneshinService->listServices();
                                            $options = [];

                                            foreach ($services as $service) {
                                                $options[$service['id']] = $service['name'];
                                            }

                                            return $options;
                                        } catch (\Exception $e) {
                                            \Illuminate\Support\Facades\Log::error('Failed to load Marzneshin services: '.$e->getMessage());

                                            return [];
                                        }
                                    })
                                    ->helperText('در صورتی که لیست خالی است، لطفاً اطمینان حاصل کنید که اطلاعات اتصال پنل به درستی وارد شده است.')
                                    ->columns(2),
                            ]),
                    ]),

                Forms\Components\Section::make('پلن‌های مجاز')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'plan')
                    ->schema([
                        Forms\Components\Repeater::make('allowedPlans')
                            ->relationship('allowedPlans')
                            ->label('پلن‌های مجاز')
                            ->schema([
                                Forms\Components\Select::make('plan_id')
                                    ->label('پلن')
                                    ->options(Plan::where('reseller_visible', true)->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->distinct()
                                    ->disableOptionWhen(function ($value, $state, Forms\Get $get) {
                                        // Disable options that are already selected in other items
                                        $selectedPlans = collect($get('../../allowedPlans'))
                                            ->pluck('plan_id')
                                            ->filter()
                                            ->toArray();

                                        return in_array($value, $selectedPlans) && $value != $state;
                                    }),

                                Forms\Components\Select::make('override_type')
                                    ->label('نوع تخفیف')
                                    ->options([
                                        'price' => 'قیمت ثابت',
                                        'percent' => 'درصد تخفیف',
                                    ])
                                    ->live(),

                                Forms\Components\TextInput::make('override_value')
                                    ->label('مقدار')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(fn (Forms\Get $get) => $get('override_type') === 'percent' ? 100 : null),

                                Forms\Components\Toggle::make('active')
                                    ->label('فعال')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => Plan::find($state['plan_id'] ?? null)?->name ?? 'پلن جدید'
                            )
                            ->defaultItems(0)
                            ->addActionLabel('افزودن پلن'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('کاربر')
                    ->description(fn (Reseller $record): string => $record->user->email ?? '')
                    ->searchable(['name', 'email'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'plan' => 'info',
                        'traffic' => 'warning',
                        default => 'gray',
                    })
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

                Tables\Columns\TextColumn::make('traffic')
                    ->label('ترافیک')
                    ->visible(fn ($record): bool => $record && $record->type === 'traffic')
                    ->formatStateUsing(function (Reseller $record): string {
                        if ($record->type !== 'traffic') {
                            return '-';
                        }
                        $usedGB = round($record->traffic_used_bytes / (1024 * 1024 * 1024), 2);
                        $totalGB = round($record->traffic_total_bytes / (1024 * 1024 * 1024), 2);
                        $percent = $totalGB > 0 ? round(($record->traffic_used_bytes / $record->traffic_total_bytes) * 100, 1) : 0;

                        return "{$usedGB} / {$totalGB} GB ({$percent}%)";
                    })
                    ->description(function (Reseller $record): ?string {
                        if ($record->type !== 'traffic' || ! $record->traffic_total_bytes) {
                            return null;
                        }
                        $percent = round(($record->traffic_used_bytes / $record->traffic_total_bytes) * 100, 1);

                        return "استفاده شده: {$percent}%";
                    }),

                Tables\Columns\TextColumn::make('window')
                    ->label('بازه زمانی')
                    ->visible(fn ($record): bool => $record && $record->type === 'traffic')
                    ->formatStateUsing(function (Reseller $record): string {
                        if ($record->type !== 'traffic' || ! $record->window_starts_at) {
                            return '-';
                        }

                        return $record->window_starts_at->format('Y-m-d').' تا '.$record->window_ends_at->format('Y-m-d');
                    }),

                Tables\Columns\TextColumn::make('panel.name')
                    ->label('پنل')
                    ->description(fn (Reseller $record): string => $record->panel ? ($record->panel->panel_type ?? '-') : '-')
                    ->visible(fn ($record): bool => $record && $record->type === 'traffic'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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

                Tables\Filters\SelectFilter::make('panel_type')
                    ->label('نوع پنل')
                    ->relationship('panel', 'panel_type')
                    ->options([
                        'marzban' => 'Marzban',
                        'marzneshin' => 'Marzneshin',
                        'xui' => 'X-UI',
                    ])
                    ->visible(fn (): bool => Reseller::where('type', 'traffic')->exists()),
            ])
            ->actions([
                Tables\Actions\Action::make('users')
                    ->label('کاربران')
                    ->icon('heroicon-o-users')
                    ->color('info')
                    ->url(fn (Reseller $record): string => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Reseller $record): bool => $record->type === 'traffic'),

                Tables\Actions\Action::make('suspend')
                    ->label('تعلیق')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Reseller $record): bool => $record->status === 'active')
                    ->action(function (Reseller $record) {
                        $record->update(['status' => 'suspended']);
                        Notification::make()
                            ->success()
                            ->title('ریسلر با موفقیت معلق شد')
                            ->send();
                    }),

                Tables\Actions\Action::make('activate')
                    ->label('فعال‌سازی')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Reseller $record): bool => $record->status === 'suspended')
                    ->action(function (Reseller $record) {
                        $record->update(['status' => 'active']);
                        Notification::make()
                            ->success()
                            ->title('ریسلر با موفقیت فعال شد')
                            ->send();
                    }),

                Tables\Actions\Action::make('topup')
                    ->label('افزایش ترافیک')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('warning')
                    ->visible(fn (Reseller $record): bool => $record->type === 'traffic')
                    ->form([
                        Forms\Components\TextInput::make('traffic_gb')
                            ->label('مقدار ترافیک (GB)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->helperText('مقدار ترافیک که می‌خواهید به ریسلر اضافه کنید'),
                    ])
                    ->action(function (Reseller $record, array $data) {
                        $additionalBytes = $data['traffic_gb'] * 1024 * 1024 * 1024;
                        $record->update([
                            'traffic_total_bytes' => $record->traffic_total_bytes + $additionalBytes,
                        ]);

                        // Dispatch job to re-enable configs if reseller recovered
                        \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch();

                        Notification::make()
                            ->success()
                            ->title('ترافیک با موفقیت افزایش یافت')
                            ->body("{$data['traffic_gb']} گیگابایت به ترافیک ریسلر اضافه شد")
                            ->send();
                    }),

                Tables\Actions\Action::make('extend')
                    ->label('تمدید بازه')
                    ->icon('heroicon-o-calendar')
                    ->color('info')
                    ->visible(fn (Reseller $record): bool => $record->type === 'traffic')
                    ->form([
                        Forms\Components\TextInput::make('days')
                            ->label('تعداد روز')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(365)
                            ->helperText('تعداد روزی که می‌خواهید به بازه زمانی اضافه کنید'),
                    ])
                    ->action(function (Reseller $record, array $data) {
                        $newEndDate = $record->window_ends_at->addDays($data['days']);
                        $record->update([
                            'window_ends_at' => $newEndDate,
                        ]);

                        // Dispatch job to re-enable configs if reseller recovered
                        \Modules\Reseller\Jobs\ReenableResellerConfigsJob::dispatch();

                        Notification::make()
                            ->success()
                            ->title('بازه زمانی با موفقیت تمدید شد')
                            ->body("{$data['days']} روز به بازه زمانی ریسلر اضافه شد")
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('suspend')
                        ->label('تعلیق گروهی')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $records->each(fn (Reseller $record) => $record->update(['status' => 'suspended']));
                            Notification::make()
                                ->success()
                                ->title('ریسلرهای انتخاب شده با موفقیت معلق شدند')
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('activate')
                        ->label('فعال‌سازی گروهی')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $records->each(fn (Reseller $record) => $record->update(['status' => 'active']));
                            Notification::make()
                                ->success()
                                ->title('ریسلرهای انتخاب شده با موفقیت فعال شدند')
                                ->send();
                        }),

                    Tables\Actions\ExportBulkAction::make()
                        ->label('خروجی CSV'),
                ]),
            ])
            ->searchable()
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ConfigsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResellers::route('/'),
            'create' => Pages\CreateReseller::route('/create'),
            'view' => Pages\ViewReseller::route('/{record}'),
            'edit' => Pages\EditReseller::route('/{record}/edit'),
        ];
    }
}
