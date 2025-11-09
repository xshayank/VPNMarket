<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletTopUpTransactionResource\Pages;
use App\Models\Transaction;
use App\Models\Reseller;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class WalletTopUpTransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'مدیریت مالی';

    protected static ?string $navigationLabel = 'تاییدیه شارژ کیف پول';

    protected static ?string $pluralModelLabel = 'تاییدیه‌های شارژ کیف پول';

    protected static ?string $modelLabel = 'تاییدیه شارژ';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        // Only show deposit transactions (wallet top-ups)
        return parent::getEloquentQuery()
            ->where('type', Transaction::TYPE_DEPOSIT);
    }

    public static function getNavigationBadge(): ?string
    {
        // Show count of pending wallet top-ups
        return (string) Transaction::where('type', Transaction::TYPE_DEPOSIT)
            ->where('status', Transaction::STATUS_PENDING)
            ->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = Transaction::where('type', Transaction::TYPE_DEPOSIT)
            ->where('status', Transaction::STATUS_PENDING)
            ->count();
            
        return $count > 0 ? 'warning' : 'success';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('اطلاعات تراکنش')
                    ->schema([
                        Forms\Components\TextInput::make('user.name')
                            ->label('کاربر')
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('amount')
                            ->label('مبلغ (تومان)')
                            ->disabled()
                            ->numeric()
                            ->formatStateUsing(fn ($state) => number_format($state)),
                        
                        Forms\Components\Select::make('status')
                            ->label('وضعیت')
                            ->options([
                                Transaction::STATUS_PENDING => 'در انتظار تایید',
                                Transaction::STATUS_COMPLETED => 'تایید شده',
                                Transaction::STATUS_FAILED => 'رد شده',
                            ])
                            ->disabled(),
                        
                        Forms\Components\Textarea::make('description')
                            ->label('توضیحات')
                            ->disabled()
                            ->rows(3),
                        
                        Forms\Components\TextInput::make('created_at')
                            ->label('تاریخ درخواست')
                            ->disabled()
                            ->formatStateUsing(fn ($state) => $state?->format('Y-m-d H:i')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('شناسه')
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('user.name')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Transaction $record): string => $record->user->email ?? ''),
                
                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ (تومان)')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->color('success')
                    ->weight('bold'),
                
                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        Transaction::STATUS_PENDING => 'warning',
                        Transaction::STATUS_COMPLETED => 'success',
                        Transaction::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Transaction::STATUS_PENDING => 'در انتظار تایید',
                        Transaction::STATUS_COMPLETED => 'تایید شده',
                        Transaction::STATUS_FAILED => 'رد شده',
                        default => $state,
                    }),
                
                Tables\Columns\TextColumn::make('description')
                    ->label('توضیحات')
                    ->limit(50)
                    ->wrap(),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ درخواست')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        Transaction::STATUS_PENDING => 'در انتظار تایید',
                        Transaction::STATUS_COMPLETED => 'تایید شده',
                        Transaction::STATUS_FAILED => 'رد شده',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                
                Tables\Actions\Action::make('approve')
                    ->label('تایید')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تایید شارژ کیف پول')
                    ->modalDescription(fn (Transaction $record) => 
                        'آیا از تایید شارژ کیف پول به مبلغ ' . number_format($record->amount) . ' تومان برای کاربر ' . ($record->user->name ?? $record->user->email) . ' اطمینان دارید؟'
                    )
                    ->visible(fn (Transaction $record): bool => $record->status === Transaction::STATUS_PENDING)
                    ->action(function (Transaction $record) {
                        DB::transaction(function () use ($record) {
                            $user = $record->user;
                            $reseller = $user->reseller;
                            
                            // Update transaction status
                            $record->update(['status' => Transaction::STATUS_COMPLETED]);
                            
                            // Credit the appropriate wallet
                            if ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) {
                                // For wallet-based resellers, charge their reseller wallet
                                $reseller->increment('wallet_balance', $record->amount);
                                
                                // Check if reseller was suspended and should be reactivated
                                if (method_exists($reseller, 'isSuspendedWallet') && 
                                    $reseller->isSuspendedWallet() && 
                                    $reseller->wallet_balance > config('billing.wallet.suspension_threshold', -1000)) {
                                    $reseller->update(['status' => 'active']);
                                    Notification::make()
                                        ->title('ریسلر به طور خودکار فعال شد.')
                                        ->success()
                                        ->send();
                                }
                                
                                Notification::make()
                                    ->title('کیف پول ریسلر با موفقیت شارژ شد.')
                                    ->body('مبلغ ' . number_format($record->amount) . ' تومان به کیف پول ریسلر اضافه شد.')
                                    ->success()
                                    ->send();
                            } else {
                                // For regular users, charge their user balance
                                $user->increment('balance', $record->amount);
                                
                                Notification::make()
                                    ->title('کیف پول کاربر با موفقیت شارژ شد.')
                                    ->body('مبلغ ' . number_format($record->amount) . ' تومان به کیف پول کاربر اضافه شد.')
                                    ->success()
                                    ->send();
                            }
                            
                            // Send Telegram notification
                            if ($user->telegram_chat_id) {
                                try {
                                    $settings = Setting::all()->pluck('value', 'key');
                                    $balance = ($reseller && method_exists($reseller, 'isWalletBased') && $reseller->isWalletBased()) 
                                        ? $reseller->fresh()->wallet_balance 
                                        : $user->fresh()->balance;
                                    
                                    $telegramMessage = '✅ کیف پول شما به مبلغ *'.number_format($record->amount)." تومان* با موفقیت شارژ شد.\n\n";
                                    $telegramMessage .= 'موجودی جدید شما: *'.number_format($balance).' تومان*';

                                    Telegram::setAccessToken($settings->get('telegram_bot_token'));
                                    Telegram::sendMessage([
                                        'chat_id' => $user->telegram_chat_id,
                                        'text' => $telegramMessage,
                                        'parse_mode' => 'Markdown',
                                    ]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to send wallet charge notification via Telegram: '.$e->getMessage());
                                }
                            }
                        });
                    }),
                
                Tables\Actions\Action::make('reject')
                    ->label('رد')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('رد شارژ کیف پول')
                    ->modalDescription(fn (Transaction $record) => 
                        'آیا از رد شارژ کیف پول به مبلغ ' . number_format($record->amount) . ' تومان برای کاربر ' . ($record->user->name ?? $record->user->email) . ' اطمینان دارید؟'
                    )
                    ->visible(fn (Transaction $record): bool => $record->status === Transaction::STATUS_PENDING)
                    ->action(function (Transaction $record) {
                        $record->update(['status' => Transaction::STATUS_FAILED]);
                        
                        Notification::make()
                            ->title('درخواست شارژ رد شد.')
                            ->body('درخواست شارژ کیف پول به مبلغ ' . number_format($record->amount) . ' تومان رد شد.')
                            ->warning()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTopUpTransactions::route('/'),
            'view' => Pages\ViewWalletTopUpTransaction::route('/{record}'),
        ];
    }
}
