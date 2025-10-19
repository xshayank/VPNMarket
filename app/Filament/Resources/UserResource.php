<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Reseller\Models\Reseller;

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
                Tables\Actions\Action::make('convert-to-reseller')
                    ->label('تبدیل به ریسلر')
                    ->visible(fn (User $record): bool => $record->reseller === null)
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('نوع ریسلر')
                            ->options([
                                'plan' => 'پلن',
                                'traffic' => 'ترافیک',
                            ])
                            ->required()
                            ->reactive(),
                        Forms\Components\TextInput::make('username_prefix')
                            ->label('پیشوند نام کاربری')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('traffic_total_gb')
                            ->label('سهمیه ترافیک (GB)')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (callable $get) => $get('type') === 'traffic'),
                        Forms\Components\DateTimePicker::make('window_starts_at')
                            ->label('شروع پنجره')
                            ->seconds(false)
                            ->visible(fn (callable $get) => $get('type') === 'traffic'),
                        Forms\Components\DateTimePicker::make('window_ends_at')
                            ->label('پایان پنجره')
                            ->seconds(false)
                            ->visible(fn (callable $get) => $get('type') === 'traffic'),
                    ])
                    ->action(function (User $record, array $data): void {
                        DB::transaction(function () use ($record, $data): void {
                            $payload = [
                                'type' => $data['type'],
                                'status' => 'active',
                                'username_prefix' => $data['username_prefix'] ?? null,
                            ];

                            if ($data['type'] === 'traffic') {
                                $payload['traffic_total_bytes'] = isset($data['traffic_total_gb'])
                                    ? (int) $data['traffic_total_gb'] * 1024 * 1024 * 1024
                                    : null;
                                $payload['window_starts_at'] = $data['window_starts_at'] ?? null;
                                $payload['window_ends_at'] = $data['window_ends_at'] ?? null;
                            }

                            Reseller::updateOrCreate(
                                ['user_id' => $record->getKey()],
                                $payload
                            );
                        });
                    })
                    ->successNotificationTitle('کاربر با موفقیت به ریسلر تبدیل شد'),
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
