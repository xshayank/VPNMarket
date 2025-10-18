<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\MarzneshinService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'مدیریت پلن‌ها';

    protected static ?string $navigationLabel = 'پلن‌های سرویس';

    protected static ?string $pluralModelLabel = 'پلن‌های سرویس';

    protected static ?string $modelLabel = 'پلن سرویس';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام پلن')
                    ->required(),
                Forms\Components\TextInput::make('price')
                    ->label('قیمت')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('currency')
                    ->label('واحد پول')
                    ->default('تومان/ماهانه'),
                Forms\Components\Textarea::make('features')
                    ->label('ویژگی‌ها')
                    ->required()
                    ->helperText('هر ویژگی را در یک خط جدید بنویسید.'),

                Forms\Components\TextInput::make('volume_gb')
                    ->label('حجم (GB)')
                    ->numeric()
                    ->required()
                    ->default(30)
                    ->helperText('حجم سرویس را به گیگابایت وارد کنید.'),

                Forms\Components\TextInput::make('duration_days')
                    ->label('مدت زمان (روز)')
                    ->numeric()
                    ->required()
                    ->default(30)
                    ->helperText('مدت زمان اعتبار سرویس را به روز وارد کنید.'),
                // ========================================================

                Forms\Components\Toggle::make('is_popular')
                    ->label('پلن محبوب است؟')
                    ->helperText('این پلن به صورت ویژه نمایش داده خواهد شد.'),
                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true),

                Forms\Components\Section::make('سرویسهای مرزنشین (Marzneshin)')
                    ->description('سرویسهای مرزنشین را که این پلن باید به آنها دسترسی داشته باشد انتخاب کنید.')
                    ->visible(function () {
                        $settings = Setting::pluck('value', 'key');

                        return $settings->get('panel_type') === 'marzneshin';
                    })
                    ->schema([
                        Forms\Components\CheckboxList::make('marzneshin_service_ids')
                            ->label('انتخاب سرویس‌ها')
                            ->options(function () {
                                $settings = Setting::pluck('value', 'key');

                                // Only try to load services if panel type is marzneshin
                                if ($settings->get('panel_type') !== 'marzneshin') {
                                    return [];
                                }

                                try {
                                    $marzneshinHost = $settings->get('marzneshin_host');
                                    $marzneshinUsername = $settings->get('marzneshin_sudo_username');
                                    $marzneshinPassword = $settings->get('marzneshin_sudo_password');
                                    $marzneshinNodeHostname = $settings->get('marzneshin_node_hostname');

                                    if (! $marzneshinHost || ! $marzneshinUsername || ! $marzneshinPassword) {
                                        return [];
                                    }

                                    $marzneshinService = new MarzneshinService(
                                        $marzneshinHost,
                                        $marzneshinUsername,
                                        $marzneshinPassword,
                                        $marzneshinNodeHostname ?? ''
                                    );

                                    $services = $marzneshinService->listServices();
                                    $options = [];

                                    foreach ($services as $service) {
                                        $options[$service['id']] = $service['name'];
                                    }

                                    return $options;
                                } catch (\Exception $e) {
                                    Log::error('Failed to load Marzneshin services: '.$e->getMessage());

                                    return [];
                                }
                            })
                            ->helperText('در صورتی که لیست خالی است، لطفاً اطمینان حاصل کنید که اطلاعات اتصال مرزنشین در تنظیمات به درستی وارد شده است.')
                            ->columns(2),
                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام پلن'),
                Tables\Columns\TextColumn::make('price')->label('قیمت'),
                Tables\Columns\BooleanColumn::make('is_popular')->label('محبوب'),
                Tables\Columns\BooleanColumn::make('is_active')->label('فعال'),
            ])
            ->filters([
                //
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
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
