<?php

namespace App\Filament\Resources;

use App\Events\OrderPaid;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\XUIService;
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
                ImageColumn::make('card_payment_receipt')->label('رسید')->disk('public')->toggleable()->size(60)->circular()->url(fn (Order $record): ?string => $record->card_payment_receipt ? Storage::disk('public')->url($record->card_payment_receipt) : null)->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('user.name')->label('کاربر')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('plan.name')->label('پلن / آیتم')->default(fn (Order $record): string => $record->plan_id ? $record->plan->name : 'شارژ کیف پول')->description(function (Order $record): string {
                    if ($record->renews_order_id) {
                        return ' (تمدید سفارش #'.$record->renews_order_id.')';
                    }
                    if (! $record->plan_id) {
                        return number_format($record->amount).' تومان';
                    }

                    return '';
                })->color(fn (Order $record) => $record->renews_order_id ? 'primary' : 'gray'),
                IconColumn::make('source')->label('منبع')->icon(fn (?string $state): string => match ($state) {
                    'web' => 'heroicon-o-globe-alt', 'telegram' => 'heroicon-o-paper-airplane', default => 'heroicon-o-question-mark-circle'
                })->color(fn (?string $state): string => match ($state) {
                    'web' => 'primary', 'telegram' => 'info', default => 'gray'
                }),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()->color(fn (string $state): string => match ($state) {
                    'pending' => 'warning', 'paid' => 'success', 'expired' => 'danger', default => 'gray'
                })->formatStateUsing(fn (string $state): string => match ($state) {
                    'pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده', default => $state
                }),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ سفارش')->dateTime('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->label('تاریخ انقضا')->dateTime('Y-m-d')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده']),
                Tables\Filters\SelectFilter::make('source')->label('منبع')->options(['web' => 'وب‌سایت', 'telegram' => 'تلگرام']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('approve')->label('تایید و اجرا')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()->modalHeading('تایید پرداخت سفارش')->modalDescription('آیا از تایید این پرداخت اطمینان دارید؟')->visible(fn (Order $order): bool => $order->status === 'pending')
                    ->action(function (Order $order) {
                        DB::transaction(function () use ($order) {
                            $settings = Setting::all()->pluck('value', 'key');
                            $user = $order->user;
                            $plan = $order->plan;

                            if (! $plan) {
                                $order->update(['status' => 'paid']);
                                $user->increment('balance', $order->amount);
                                Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $order->amount, 'type' => 'deposit', 'status' => 'completed', 'description' => 'شارژ کیف پول (تایید دستی فیش)']);
                                Notification::make()->title('کیف پول کاربر با موفقیت شارژ شد.')->success()->send();

                                if ($user->telegram_chat_id) {
                                    try {
                                        $telegramMessage = '✅ کیف پول شما به مبلغ *'.number_format($order->amount)." تومان* با موفقیت شارژ شد.\n\n";
                                        $telegramMessage .= 'موجودی جدید شما: *'.number_format($user->fresh()->balance).' تومان*';

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

                                return;
                            }

                            // Get panel from plan
                            $panel = $plan->panel;
                            if (!$panel) {
                                Notification::make()->title('خطا')->body('هیچ پنلی به این پلن مرتبط نیست.')->danger()->send();
                                return;
                            }

                            $panelType = $panel->panel_type;
                            $credentials = $panel->getCredentials();
                            $success = false;
                            $finalConfig = '';
                            $isRenewal = (bool) $order->renews_order_id;

                            $originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;
                            if ($isRenewal && ! $originalOrder) {
                                Notification::make()->title('خطا')->body('سفارش اصلی جهت تمدید یافت نشد.')->danger()->send();

                                return;
                            }
                            $uniqueUsername = "user_{$user->id}_order_".($isRenewal ? $originalOrder->id : $order->id);
                            $newExpiresAt = $isRenewal
                                ? (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days")
                                : now()->addDays($plan->duration_days);

                            if ($panelType === 'marzban') {
                                $configUrl = $panel->config_url ?: ($credentials['extra']['node_hostname'] ?? '');
                                $marzbanService = new MarzbanService(
                                    $credentials['url'],
                                    $credentials['username'],
                                    $credentials['password'],
                                    $configUrl
                                );
                                $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->volume_gb * 1073741824];
                                $response = $isRenewal ? $marzbanService->updateUser($uniqueUsername, $userData) : $marzbanService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

                                if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                                    $finalConfig = $marzbanService->generateSubscriptionLink($response);
                                    $success = true;
                                } else {
                                    Notification::make()->title('خطا در ارتباط با مرزبان')->body($response['detail'] ?? 'پاسخ نامعتبر.')->danger()->send();

                                    return;
                                }
                            } elseif ($panelType === 'marzneshin') {
                                $configUrl = $panel->config_url ?: ($credentials['extra']['node_hostname'] ?? '');
                                $marzneshinService = new MarzneshinService(
                                    $credentials['url'],
                                    $credentials['username'],
                                    $credentials['password'],
                                    $configUrl
                                );
                                $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->volume_gb * 1073741824];

                                // Add plan-specific service_ids if available
                                if ($plan->marzneshin_service_ids && is_array($plan->marzneshin_service_ids) && count($plan->marzneshin_service_ids) > 0) {
                                    $userData['service_ids'] = $plan->marzneshin_service_ids;
                                }

                                $response = $isRenewal ? $marzneshinService->updateUser($uniqueUsername, $userData) : $marzneshinService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

                                if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                                    $finalConfig = $marzneshinService->generateSubscriptionLink($response);
                                    $success = true;
                                } else {
                                    Notification::make()->title('خطا در ارتباط با مرزنشین')->body($response['detail'] ?? 'پاسخ نامعتبر.')->danger()->send();

                                    return;
                                }
                            } elseif ($panelType === 'xui') {
                                if ($isRenewal) {
                                    Notification::make()->title('خطا')->body('تمدید خودکار برای پنل سنایی هنوز پیاده‌سازی نشده است.')->danger()->send();

                                    return;
                                }
                                $xuiService = new XUIService(
                                    $credentials['url'],
                                    $credentials['username'],
                                    $credentials['password']
                                );
                                
                                $defaultInboundId = $credentials['extra']['default_inbound_id'] ?? null;
                                $inbound = $defaultInboundId ? Inbound::find($defaultInboundId) : null;
                                
                                if (! $inbound || ! $inbound->inbound_data) {
                                    Notification::make()->title('خطا')->body('اطلاعات اینباند پیش‌فرض برای X-UI یافت نشد.')->danger()->send();

                                    return;
                                }
                                if (! $xuiService->login()) {
                                    Notification::make()->title('خطا')->body('خطا در لاگین به پنل X-UI.')->danger()->send();

                                    return;
                                }

                                $inboundData = json_decode($inbound->inbound_data, true);
                                $clientData = ['email' => $uniqueUsername, 'total' => $plan->volume_gb * 1073741824, 'expiryTime' => $newExpiresAt->timestamp * 1000];
                                $response = $xuiService->addClient($inboundData['id'], $clientData);

                                if ($response && isset($response['success']) && $response['success']) {
                                    $linkType = $credentials['extra']['link_type'] ?? 'single';
                                    if ($linkType === 'subscription') {
                                        $subId = $response['generated_subId'];
                                        $subBaseUrl = $panel->config_url ?: rtrim($credentials['extra']['subscription_url_base'] ?? '', '/');
                                        if ($subBaseUrl && $subId) {
                                            $finalConfig = rtrim($subBaseUrl, '/').'/sub/'.$subId;
                                            $success = true;
                                        }
                                    } else {
                                        $uuid = $response['generated_uuid'];
                                        $streamSettings = json_decode($inboundData['streamSettings'], true);
                                        $parsedUrl = parse_url($credentials['url']);
                                        $serverIpOrDomain = ! empty($inboundData['listen']) ? $inboundData['listen'] : $parsedUrl['host'];
                                        $port = $inboundData['port'];
                                        $remark = $inboundData['remark'];
                                        $paramsArray = ['type' => $streamSettings['network'] ?? null, 'security' => $streamSettings['security'] ?? null, 'path' => $streamSettings['wsSettings']['path'] ?? ($streamSettings['grpcSettings']['serviceName'] ?? null), 'sni' => $streamSettings['tlsSettings']['serverName'] ?? null, 'host' => $streamSettings['wsSettings']['headers']['Host'] ?? null];
                                        $params = http_build_query(array_filter($paramsArray));
                                        $fullRemark = $uniqueUsername.'|'.$remark;
                                        $finalConfig = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#".urlencode($fullRemark);
                                        $success = true;
                                    }
                                } else {
                                    Notification::make()->title('خطا در ساخت کاربر در پنل سنایی')->body($response['msg'] ?? 'پاسخ نامعتبر')->danger()->send();

                                    return;
                                }
                            } else {
                                Notification::make()->title('خطا')->body('نوع پنل نامشخص است.')->danger()->send();

                                return;
                            }

                            if ($success) {
                                if ($isRenewal) {
                                    $originalOrder->update(['config_details' => $finalConfig, 'expires_at' => $newExpiresAt->format('Y-m-d H:i:s')]);
                                    $user->update(['show_renewal_notification' => true]);
                                } else {
                                    $order->update(['config_details' => $finalConfig, 'expires_at' => $newExpiresAt]);
                                }

                                $order->update(['status' => 'paid']);
                                $description = ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس')." {$plan->name}";
                                Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $plan->price, 'type' => 'purchase', 'status' => 'completed', 'description' => $description]);
                                OrderPaid::dispatch($order);
                                Notification::make()->title('عملیات با موفقیت انجام شد.')->success()->send();

                                if ($user->telegram_chat_id) {
                                    try {
                                        $telegramMessage = $isRenewal
                                            ? "✅ سرویس شما (*{$plan->name}*) با موفقیت تمدید شد.\n\n❗️*نکته مهم:* لینک اشتراک شما تغییر کرده است. لطفاً لینک جدید زیر را کپی و در نرم‌افزار خود آپدیت کنید:\n\n`".$finalConfig.'`'
                                            : "✅ سرویس شما (*{$plan->name}*) با موفقیت فعال شد.\n\nاطلاعات کانفیگ شما:\n`".$finalConfig."`\n\nمی‌توانید لینک بالا را کپی کرده و در نرم‌افزار خود import کنید.";
                                        Telegram::setAccessToken($settings->get('telegram_bot_token'));
                                        Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $telegramMessage, 'parse_mode' => 'Markdown']);
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send Telegram notification: '.$e->getMessage());
                                    }
                                }
                            }
                        });
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
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
