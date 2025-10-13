<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Inbound;
use App\Services\MarzbanService;
use App\Services\XUIService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Str;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
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
                Textarea::make('config_details')->label('اطلاعات کانفیگ سرویس')->rows(10)->helperText('در این بخش می‌توانید کانفیگ تولید شده را ویرایش کرده یا به صورت دستی یک کانفیگ جدید وارد کنید.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('card_payment_receipt')->label('رسید')->disk('public')->toggleable()->size(60)->circular()->url(fn (Order $record): ?string => $record->card_payment_receipt ? Storage::disk('public')->url($record->card_payment_receipt) : null)->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('user.name')->label('کاربر')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('plan.name')->label('پلن'),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()->color(fn (string $state): string => match ($state) {'pending' => 'warning', 'paid' => 'success', 'expired' => 'danger', default => 'gray',})->formatStateUsing(fn (string $state): string => match ($state) {'pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده', default => $state,}),



                Tables\Columns\TextColumn::make('plan.name')
                    ->label('پلن / آیتم')

                    ->default(fn (Order $record): string => $record->plan_id ? $record->plan->name : "شارژ کیف پول")
                    ->description(fn (Order $record): string => !$record->plan_id ? number_format($record->amount) . ' تومان' : ''),


                Tables\Columns\IconColumn::make('source')
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

                Tables\Columns\TextColumn::make('created_at')->label('تاریخ سفارش')->dateTime('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->label('تاریخ انقضا')->dateTime('Y-m-d')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->label('وضعیت')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده',])])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),

                Action::make('approve')
                    ->label('تایید و ساخت سرویس')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تایید پرداخت سفارش')
                    ->modalDescription('آیا از تایید این پرداخت و فعالسازی سرویس کاربر اطمینان دارید؟')
                    ->visible(fn (Order $order): bool => $order->status === 'pending')
                    ->action(function (Order $order) {
                        try {

                            DB::transaction(function () use ($order) {
                                $settings = Setting::all()->pluck('value', 'key');
                                $user = $order->user;
                                $plan = $order->plan;


                                if ($plan) {
                                    $panelType = $settings->get('panel_type');
                                    if (!$panelType) {
                                        Notification::make()->title('خطا')->body('لطفاً ابتدا نوع پنل را در تنظیمات سایت مشخص کنید.')->danger()->send();
                                        return; // لغو عملیات
                                    }

                                    $volumeInGB = $plan->volume_gb;
                                    $durationInDays = $plan->duration_days;
                                    $uniqueUsername = "user-{$user->id}-order-{$order->id}";
                                    $config = null;
                                    $success = false;


                                    if ($panelType === 'marzban') {
                                        $trafficInBytes = $volumeInGB * 1073741824;
                                        $marzbanService = new MarzbanService(
                                            $settings->get('marzban_host'),
                                            $settings->get('marzban_sudo_username'),
                                            $settings->get('marzban_sudo_password'),
                                           $settings->get('marzban_node_hostname')
                                        );
                                        $expireTimestamp = now()->addDays($durationInDays)->timestamp;
                                        $userData = ['username' => $uniqueUsername, 'data_limit' => $trafficInBytes, 'expire' => $expireTimestamp];
                                        $response = $marzbanService->createUser($userData);
                                        if ($response && isset($response['username'])) {
                                            $config = $marzbanService->generateSubscriptionLink($response);
                                            $success = true;
                                        } else {
                                            Notification::make()->title('خطا در ساخت کاربر در مرزبان')->body($response['detail'] ?? 'پاسخ نامعتبر.')->danger()->send();
                                            return;
                                        }
                                    }
                                    elseif ($panelType === 'xui') {
                                        $inboundSettingId = $settings->get('xui_default_inbound_id');
                                        if (!$inboundSettingId) {
                                            Notification::make()->title('خطا')->body('اینباند پیش‌فرض مشخص نشده است.')->danger()->send();
                                            return;
                                        }
                                        $inbound = Inbound::find($inboundSettingId);
                                        if (!$inbound || !$inbound->inbound_data) {
                                            Notification::make()->title('خطا')->body('اطلاعات JSON برای اینباند انتخاب شده ثبت نشده است.')->danger()->send();
                                            return;
                                        }
                                        $inboundData = json_decode($inbound->inbound_data, true);
                                        $xuiService = new XUIService($settings->get('xui_host'), $settings->get('xui_user'), $settings->get('xui_pass'));
                                        if (!$xuiService->login()) {
                                            Notification::make()->title('خطای لاگین به X-UI')->body('اطلاعات اتصال صحیح نیست.')->danger()->send();
                                            return;
                                        }
                                        $expireTime = now()->addDays($durationInDays)->timestamp * 1000;
                                        $volumeBytes = $volumeInGB * 1073741824;
                                        $clientData = ['email' => $uniqueUsername, 'total' => $volumeBytes, 'expiryTime' => $expireTime];
                                        Log::info("Sending clientData to X-UI", $clientData);
                                        $response = $xuiService->addClient($inboundData['id'], $clientData);
                                        if ($response && isset($response['success']) && $response['success']) {
                                            $linkType = $settings->get('xui_link_type', 'single');
                                            if ($linkType === 'subscription') {
                                                $subId = $response['generated_subId'];
                                                $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                                                if (!$subBaseUrl) {
                                                    Notification::make()->title('خطا')->body('آدرس پایه لینک سابسکریپشن در تنظیمات سایت وارد نشده است.')->danger()->send();
                                                    return;
                                                }
                                                $config = $subBaseUrl . '/sub/' . $subId;


                                            } else {
                                                $uuid = $response['generated_uuid'];
                                                $streamSettings = json_decode($inboundData['streamSettings'], true);
                                                $parsedUrl = parse_url($settings->get('xui_host'));
                                                $serverIpOrDomain = !empty($inboundData['listen']) ? $inboundData['listen'] : $parsedUrl['host'];
                                                $port = $inboundData['port'];
                                                $remark = $inboundData['remark'];
                                                $paramsArray = ['type' => $streamSettings['network'] ?? null, 'security' => $streamSettings['security'] ?? null, 'path' => $streamSettings['wsSettings']['path'] ?? ($streamSettings['grpcSettings']['serviceName'] ?? null), 'sni' => $streamSettings['tlsSettings']['serverName'] ?? null, 'host' => $streamSettings['wsSettings']['headers']['Host'] ?? null];
                                                $params = http_build_query(array_filter($paramsArray));
                                                $fullRemark = $uniqueUsername . '|' . $remark;
                                                $config = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark);
                                            }
                                            $success = true;
                                        } else {
                                            Notification::make()->title('خطا در ساخت کاربر در X-UI')->body($response['msg'] ?? 'پاسخ نامعتبر.')->danger()->send();
                                            return;
                                        }
                                    }


                                    if ($success && $config) {
                                        $order->update(['status' => 'paid', 'config_details' => $config, 'expires_at' => now()->addDays($durationInDays)]);


                                        Transaction::create([
                                            'user_id' => $user->id,
                                            'order_id' => $order->id,
                                            'amount' => $plan->price, // مبلغ واقعی پلن
                                            'type' => Transaction::TYPE_PURCHASE,
                                            'status' => Transaction::STATUS_COMPLETED,
                                            'description' => "خرید سرویس {$plan->name} (پرداخت با فیش)",
                                        ]);

                                        Notification::make()->title('سرویس با موفقیت ساخته شد.')->success()->send();
                                    }

                                }

                                else {

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
                                }





                                if ($user->telegram_chat_id) {
                                    try {
                                        Telegram::setAccessToken($settings->get('telegram_bot_token'));


                                        $telegramMessage = $plan
                                            ? "✅ سرویس شما (*{$plan->name}*) با موفقیت فعال شد.\n\nشما می‌توانید کانفیگ خود را از بخش 'سرویس‌های من' دریافت کنید."
                                            : "✅ کیف پول شما به مبلغ *" . number_format($order->amount) . " تومان* با موفقیت شارژ شد.";

                                        Telegram::sendMessage([
                                            'chat_id' => $user->telegram_chat_id,
                                            'text' => $telegramMessage,
                                            'parse_mode' => 'Markdown'
                                        ]);
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send Telegram notification after approval: ' . $e->getMessage());
                                    }
                                }

                            });

                        } catch (\Exception $e) {
                            Log::error('Approve Order Failed: '. $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                            Notification::make()->title('خطای پیش‌بینی نشده')->body('یک خطای داخلی رخ داد. لطفاً لاگ‌ها را بررسی کنید.')->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make(),]),]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array { return [
        'index' => Pages\ListOrders::route('/'),
        'create' => Pages\CreateOrder::route('/create'),
        'edit' => Pages\EditOrder::route('/{record}/edit'),
    ]; }
}
