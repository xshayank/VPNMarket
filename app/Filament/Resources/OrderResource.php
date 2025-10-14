<?php

namespace App\Filament\Resources;

use App\Events\OrderPaid;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Laravel\Facades\Telegram;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'سفارشات';
    protected static ?string $modelLabel = 'سفارش';
    protected static ?string $pluralModelLabel = 'سفارشات';
    protected static ?string $navigationGroup = 'مدیریت سفارشات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')->relationship('user', 'name')->label('کاربر')->disabled(),
                Forms\Components\Select::make('plan_id')->relationship('plan', 'name')->label('پلن')->disabled(),
                Forms\Components\Select::make('status')->label('وضعیت سفارش')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده'])->required(),
                Forms\Components\Textarea::make('config_details')->label('اطلاعات کانفیگ سرویس')->rows(10),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('card_payment_receipt')
                    ->label('رسید')
                    ->disk('public')
                    ->toggleable()
                    ->size(60)
                    ->circular()
                    ->url(fn (Order $record): ?string => $record->card_payment_receipt ? Storage::disk('public')->url($record->card_payment_receipt) : null)
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('user.name')->label('کاربر')->searchable()->sortable(),

                Tables\Columns\TextColumn::make('plan.name')
                    ->label('پلن / آیتم')
                    ->default(fn (Order $record): string => $record->plan_id ? $record->plan->name : "شارژ کیف پول")
                    ->description(function (Order $record): string {
                        if ($record->renews_order_id) {
                            return " (تمدید سفارش #" . $record->renews_order_id . ")";
                        }
                        if (!$record->plan_id) {
                            return number_format($record->amount) . ' تومان';
                        }
                        return '';
                    })->color(fn(Order $record) => $record->renews_order_id ? 'primary' : 'gray'),


                IconColumn::make('source')
                    ->label('منبع')
                    ->icon(fn (string $state): string => match ($state) {
                        'web' => 'heroicon-o-globe-alt',
                        'telegram' => 'heroicon-o-paper-airplane',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'web' => 'primary',
                        'telegram' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()->color(fn (string $state): string => match ($state) {
                    'pending' => 'warning', 'paid' => 'success', 'expired' => 'danger', default => 'gray',
                })->formatStateUsing(fn (string $state): string => match ($state) {
                    'pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده', default => $state,
                }),

                Tables\Columns\TextColumn::make('created_at')->label('تاریخ سفارش')->dateTime('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->label('تاریخ انقضا')->dateTime('Y-m-d')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده',])
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Action::make('approve')
                    ->label('تایید و اجرا')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تایید پرداخت سفارش')
                    ->modalDescription('آیا از تایید این پرداخت اطمینان دارید؟')
                    ->visible(fn (Order $order): bool => $order->status === 'pending')
                    ->action(function (Order $order) {
                        DB::transaction(function () use ($order) {
                            $settings = Setting::all()->pluck('value', 'key');
                            $user = $order->user;
                            $plan = $order->plan;

                            // ** منطق اصلی برای شارژ کیف پول **
                            if (!$plan) {
                                $order->update(['status' => 'paid']);
                                $user->increment('balance', $order->amount);

                                Transaction::create([
                                    'user_id' => $user->id,
                                    'order_id' => $order->id,
                                    'amount' => $order->amount,
                                    'type' => Transaction::TYPE_DEPOSIT,
                                    'status' => Transaction::STATUS_COMPLETED,
                                    'description' => "شارژ کیف پول (تایید دستی فیش)",
                                ]);

                                Notification::make()->title('کیف پول کاربر با موفقیت شارژ شد.')->success()->send();
                                return;
                            }

                            // ** منطق برای خرید و تمدید سرویس **
                            if ($settings->get('panel_type') !== 'marzban') {
                                Notification::make()->title('خطا')->body("این عملیات فقط برای پنل مرزبان تعریف شده است.")->danger()->send();
                                return;
                            }

                            $marzbanService = new MarzbanService(
                                $settings->get('marzban_host'),
                                $settings->get('marzban_sudo_username'),
                                $settings->get('marzban_sudo_password'),
                                $settings->get('marzban_node_hostname')
                            );

                            $success = false;

                            if ($order->renews_order_id) {
                                $originalOrder = Order::find($order->renews_order_id);
                                if (!$originalOrder) {
                                    Notification::make()->title('خطا')->body('سفارش اصلی جهت تمدید یافت نشد.')->danger()->send();
                                    return;
                                }

                                $username = "user-{$originalOrder->user_id}-order-{$originalOrder->id}";
                                $newExpiresAt = (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days");
                                $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->volume_gb * 1073741824];
                                $response = $marzbanService->updateUser($username, $userData);

                                if ($response && isset($response['subscription_url'])) {
                                    $config = $marzbanService->generateSubscriptionLink($response);
                                    $originalOrder->update(['config_details' => $config, 'expires_at' => $newExpiresAt->format('Y-m-d H:i:s')]);
                                    $user->update(['show_renewal_notification' => true]);
                                    $success = true;
                                } else {
                                    Notification::make()->title('خطا در تمدید کاربر')->body($response['detail'] ?? 'پاسخ نامعتبر.')->danger()->send();
                                    return;
                                }
                            } else {
                                $uniqueUsername = "user-{$user->id}-order-{$order->id}";
                                $userData = ['username' => $uniqueUsername, 'data_limit' => $plan->volume_gb * 1073741824, 'expire' => now()->addDays($plan->duration_days)->timestamp];
                                $response = $marzbanService->createUser($userData);

                                if ($response && isset($response['username'])) {
                                    $config = $marzbanService->generateSubscriptionLink($response);
                                    $order->update(['config_details' => $config, 'expires_at' => now()->addDays($plan->duration_days)]);
                                    $success = true;
                                } else {
                                    Notification::make()->title('خطا در ساخت کاربر')->body($response['detail'] ?? 'پاسخ نامعتبر.')->danger()->send();
                                    return;
                                }
                            }

                            if ($success) {
                                $order->update(['status' => 'paid']);
                                $isRenewal = (bool)$order->renews_order_id;
                                $description = ($isRenewal ? "تمدید سرویس" : "خرید سرویس") . " {$plan->name}";

                                Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $plan->price, 'type' => Transaction::TYPE_PURCHASE, 'status' => Transaction::STATUS_COMPLETED, 'description' => $description]);

                                OrderPaid::dispatch($order);

                                Notification::make()->title('عملیات با موفقیت انجام شد.')->success()->send();

                                if ($user->telegram_chat_id) {
                                    try {
                                        $telegramMessage = $isRenewal
                                            ? "✅ سرویس شما (*{$plan->name}*) با موفقیت تمدید شد.\n\n❗️*نکته مهم:* لینک اشتراک شما تغییر کرده است. لطفاً به پنل کاربری خود مراجعه کرده و لینک جدید را در نرم‌افزار خود آپدیت کنید."
                                            : "✅ سرویس شما (*{$plan->name}*) با موفقیت فعال شد.\n\nشما می‌توانید کانفیگ خود را از بخش 'سرویس‌های من' دریافت کنید.";

                                        Telegram::setAccessToken($settings->get('telegram_bot_token'));
                                        Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $telegramMessage, 'parse_mode' => 'Markdown']);
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send Telegram notification: ' . $e->getMessage());
                                    }
                                }
                            }
                        });
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOrders::route('/'), 'create' => Pages\CreateOrder::route('/create'), 'edit' => Pages\EditOrder::route('/{record}/edit')];
    }
}

