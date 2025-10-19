<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users'; // آیکون مناسب‌تر
    protected static ?string $navigationGroup = 'مدیریت کاربران';

    protected static ?string $navigationLabel = 'کاربران سایت';
    protected static ?string $pluralModelLabel = 'کاربران سایت';
    protected static ?string $modelLabel = 'کاربر';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('ایمیل')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->label('رمز عبور جدید')
                    ->password()
                    // --- تغییرات کلیدی در این بخش ---
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->maxLength(255),
                // ------------------------------------
                Forms\Components\Toggle::make('is_admin')
                    ->label('کاربر ادمین است؟'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('ایمیل')->searchable(),
                Tables\Columns\IconColumn::make('is_admin')->label('ادمین')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت‌نام')->dateTime('Y-m-d')->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('convertToReseller')
                    ->label('تبدیل به ریسلر')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn ($record) => !$record->isReseller())
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('نوع ریسلر')
                            ->options([
                                'plan' => 'پلن‌محور',
                                'traffic' => 'ترافیک‌محور',
                            ])
                            ->required()
                            ->live()
                            ->default('plan'),

                        Forms\Components\TextInput::make('username_prefix')
                            ->label('پیشوند نام کاربری (اختیاری)')
                            ->helperText('اگر خالی باشد از پیشوند پیش‌فرض استفاده می‌شود'),

                        Forms\Components\Section::make('تنظیمات ترافیک‌محور')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'traffic')
                            ->schema([
                                Forms\Components\TextInput::make('traffic_total_gb')
                                    ->label('ترافیک کل (GB)')
                                    ->numeric()
                                    ->required()
                                    ->default(100),

                                Forms\Components\TextInput::make('window_days')
                                    ->label('مدت زمان (روز)')
                                    ->numeric()
                                    ->required()
                                    ->default(30),

                                Forms\Components\TagsInput::make('marzneshin_allowed_service_ids')
                                    ->label('سرویس‌های مجاز Marzneshin (اختیاری)')
                                    ->helperText('شناسه سرویس‌های مجاز (فقط برای Marzneshin)'),
                            ]),
                    ])
                    ->action(function ($record, array $data) {
                        \App\Models\Reseller::create([
                            'user_id' => $record->id,
                            'type' => $data['type'],
                            'status' => 'active',
                            'username_prefix' => $data['username_prefix'] ?? null,
                            'traffic_total_bytes' => $data['type'] === 'traffic' ? ($data['traffic_total_gb'] * 1024 * 1024 * 1024) : null,
                            'traffic_used_bytes' => 0,
                            'window_starts_at' => $data['type'] === 'traffic' ? now() : null,
                            'window_ends_at' => $data['type'] === 'traffic' ? now()->addDays($data['window_days']) : null,
                            'marzneshin_allowed_service_ids' => $data['marzneshin_allowed_service_ids'] ?? null,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('کاربر با موفقیت به ریسلر تبدیل شد')
                            ->send();
                    }),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
