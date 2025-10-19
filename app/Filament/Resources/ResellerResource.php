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

                        Forms\Components\DateTimePicker::make('window_starts_at')
                            ->label('تاریخ شروع'),

                        Forms\Components\DateTimePicker::make('window_ends_at')
                            ->label('تاریخ پایان'),

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
