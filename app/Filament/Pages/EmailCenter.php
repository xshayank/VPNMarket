<?php

namespace App\Filament\Pages;

use App\Jobs\SendExpiredNormalUsersEmailsJob;
use App\Jobs\SendExpiredResellerUsersEmailsJob;
use App\Jobs\SendRenewalWalletRemindersJob;
use App\Jobs\SendResellerTrafficTimeRemindersJob;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class EmailCenter extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static string $view = 'filament.pages.email-center';

    protected static ?string $navigationLabel = 'ایمیل';

    protected static ?string $title = 'مرکز مدیریت ایمیل';

    protected static ?string $navigationGroup = 'ایمیل';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = Setting::whereIn('key', [
            'email.auto_remind_renewal_wallet',
            'email.renewal_days_before',
            'email.min_wallet_threshold',
            'email.auto_remind_reseller_traffic_time',
            'email.reseller_days_before_end',
            'email.reseller_traffic_threshold_percent',
        ])->pluck('value', 'key')->toArray();

        $this->data = [
            'email.auto_remind_renewal_wallet' => ($settings['email.auto_remind_renewal_wallet'] ?? 'false') === 'true',
            'email.renewal_days_before' => (int) ($settings['email.renewal_days_before'] ?? 3),
            'email.min_wallet_threshold' => (int) ($settings['email.min_wallet_threshold'] ?? 10000),
            'email.auto_remind_reseller_traffic_time' => ($settings['email.auto_remind_reseller_traffic_time'] ?? 'false') === 'true',
            'email.reseller_days_before_end' => (int) ($settings['email.reseller_days_before_end'] ?? 3),
            'email.reseller_traffic_threshold_percent' => (int) ($settings['email.reseller_traffic_threshold_percent'] ?? 10),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('ارسال دستی ایمیل')
                ->description('ارسال ایمیل به کاربران منقضی شده')
                ->schema([
                    // Manual send actions are in header actions
                ]),

            Section::make('تنظیمات یادآوری خودکار')
                ->description('فعال‌سازی یادآوری‌های خودکار برای کاربران و ریسلرها')
                ->schema([
                    Toggle::make('email.auto_remind_renewal_wallet')
                        ->label('یادآوری تمدید و شارژ کیف پول')
                        ->helperText('ارسال خودکار یادآوری به کاربران عادی قبل از انقضای اشتراک')
                        ->live(),

                    TextInput::make('email.renewal_days_before')
                        ->label('چند روز قبل از انقضا یادآوری شود؟')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(30)
                        ->default(3)
                        ->suffix('روز')
                        ->visible(fn ($get) => $get('email.auto_remind_renewal_wallet')),

                    TextInput::make('email.min_wallet_threshold')
                        ->label('حداقل موجودی کیف پول (تومان)')
                        ->numeric()
                        ->minValue(0)
                        ->default(10000)
                        ->suffix('تومان')
                        ->helperText('کاربرانی که موجودی کمتر از این مقدار دارند، یادآوری دریافت می‌کنند')
                        ->visible(fn ($get) => $get('email.auto_remind_renewal_wallet')),

                    Toggle::make('email.auto_remind_reseller_traffic_time')
                        ->label('یادآوری محدودیت ریسلرها')
                        ->helperText('ارسال خودکار یادآوری به ریسلرهای ترافیکی نزدیک به محدودیت')
                        ->live(),

                    TextInput::make('email.reseller_days_before_end')
                        ->label('چند روز قبل از پایان بازه زمانی یادآوری شود؟')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(30)
                        ->default(3)
                        ->suffix('روز')
                        ->visible(fn ($get) => $get('email.auto_remind_reseller_traffic_time')),

                    TextInput::make('email.reseller_traffic_threshold_percent')
                        ->label('درصد ترافیک باقیمانده برای هشدار')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->default(10)
                        ->suffix('%')
                        ->helperText('وقتی ترافیک باقیمانده به این درصد برسد، یادآوری ارسال می‌شود')
                        ->visible(fn ($get) => $get('email.auto_remind_reseller_traffic_time')),
                ])->columns(2),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendExpiredNormalUsers')
                ->label('ارسال به کاربران عادی منقضی شده')
                ->icon('heroicon-o-envelope')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('ارسال ایمیل به کاربران منقضی شده')
                ->modalDescription(function () {
                    $count = $this->getExpiredNormalUsersCount();

                    return "آیا مطمئن هستید که می‌خواهید به {$count} کاربر منقضی شده ایمیل ارسال کنید؟";
                })
                ->action(function () {
                    $count = $this->getExpiredNormalUsersCount();
                    SendExpiredNormalUsersEmailsJob::dispatch();
                    Notification::make()
                        ->title("ایمیل به {$count} کاربر در صف ارسال قرار گرفت")
                        ->success()
                        ->send();
                }),

            Action::make('sendExpiredResellers')
                ->label('ارسال به ریسلرهای منقضی شده')
                ->icon('heroicon-o-envelope')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('ارسال ایمیل به ریسلرهای منقضی شده')
                ->modalDescription(function () {
                    $count = $this->getExpiredResellersCount();

                    return "آیا مطمئن هستید که می‌خواهید به {$count} ریسلر منقضی شده ایمیل ارسال کنید؟";
                })
                ->action(function () {
                    $count = $this->getExpiredResellersCount();
                    SendExpiredResellerUsersEmailsJob::dispatch();
                    Notification::make()
                        ->title("ایمیل به {$count} ریسلر در صف ارسال قرار گرفت")
                        ->success()
                        ->send();
                }),

            Action::make('runRemindersNow')
                ->label('اجرای یادآوری‌ها الان')
                ->icon('heroicon-o-play')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('اجرای فوری یادآوری‌ها')
                ->modalDescription('یادآوری‌های تمدید و ریسلر اکنون اجرا می‌شوند (بدون توجه به تنظیمات خودکار)')
                ->action(function () {
                    SendRenewalWalletRemindersJob::dispatch();
                    SendResellerTrafficTimeRemindersJob::dispatch();
                    Notification::make()
                        ->title('یادآوری‌ها در صف اجرا قرار گرفتند')
                        ->success()
                        ->send();
                }),

            Action::make('save')
                ->label('ذخیره تنظیمات')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action('submit'),
        ];
    }

    public function submit(): void
    {
        $this->form->validate();

        $formData = $this->form->getState();
        foreach ($formData as $key => $value) {
            // Skip non-setting keys
            if (! str_starts_with($key, 'email.')) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = json_encode($value);
            }
            Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }

        Notification::make()
            ->title('تنظیمات با موفقیت ذخیره شد')
            ->success()
            ->send();
    }

    public function getExpiredNormalUsersCount(): int
    {
        $expiredUserIds = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->where('expires_at', '<=', now())
            ->pluck('user_id')
            ->unique();

        $activeUserIds = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->where('expires_at', '>', now())
            ->pluck('user_id')
            ->unique();

        return $expiredUserIds->diff($activeUserIds)->count();
    }

    public function getExpiredResellersCount(): int
    {
        return Reseller::where('type', 'traffic')
            ->where(function ($query) {
                $query->where('window_ends_at', '<=', now())
                    ->orWhereRaw('traffic_used_bytes >= traffic_total_bytes');
            })
            ->count();
    }
}
