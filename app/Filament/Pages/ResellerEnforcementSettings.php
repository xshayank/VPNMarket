<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ResellerEnforcementSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static string $view = 'filament.pages.reseller-enforcement-settings';
    protected static ?string $navigationLabel = 'تنظیمات اعمال محدودیت فروشندگان';
    protected static ?string $title = 'تنظیمات اعمال محدودیت و نظارت فروشندگان';
    protected static ?string $navigationGroup = 'مدیریت فروشندگان';
    protected static ?int $navigationSort = 99;

    public ?array $data = [];

    public function mount(): void
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();
        
        $defaultData = [
            'reseller.allow_config_overrun' => 'true',
            'reseller.auto_disable_grace_percent' => '2.0',
            'reseller.auto_disable_grace_bytes' => (string)(50 * 1024 * 1024), // 50MB
            'reseller.time_expiry_grace_minutes' => '0',
            'reseller.usage_sync_interval_minutes' => '3',
        ];
        
        $this->data = array_merge($defaultData, $settings);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Tabs')
                ->id('enforcement-tabs')
                ->persistTab()
                ->tabs([
                    Tabs\Tab::make('تنظیمات اصلی')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            Section::make('رفتار اعمال محدودیت')
                                ->description('تنظیمات نحوه اعمال محدودیت‌های ترافیک و زمان')
                                ->schema([
                                    Toggle::make('reseller.allow_config_overrun')
                                        ->label('اجازه تجاوز از محدودیت کانفیگ')
                                        ->helperText('اگر فعال باشد، کانفیگ‌های منفرد تا زمانی که سهمیه کل فروشنده تمام نشده، غیرفعال نمی‌شوند.')
                                        ->default(true)
                                        ->inline(false),
                                    
                                    TextInput::make('reseller.usage_sync_interval_minutes')
                                        ->label('فاصله زمانی همگام‌سازی مصرف (دقیقه)')
                                        ->helperText('هر چند دقیقه یکبار مصرف ترافیک کانفیگ‌ها بررسی شود (۱ تا ۵ دقیقه)')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(5)
                                        ->default(3)
                                        ->required(),
                                ])->columns(2),

                            Section::make('تنظیمات Grace (فرصت مجاز)')
                                ->description('فرصت اضافی برای جلوگیری از غیرفعال‌سازی ناگهانی به دلیل تاخیر در همگام‌سازی')
                                ->schema([
                                    TextInput::make('reseller.auto_disable_grace_percent')
                                        ->label('درصد Grace ترافیک (%)')
                                        ->helperText('مثال: 2.0 به معنی ۲٪ فرصت اضافی بعد از محدودیت')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(10)
                                        ->step(0.1)
                                        ->default(2.0)
                                        ->suffix('%')
                                        ->required(),
                                    
                                    TextInput::make('reseller.auto_disable_grace_bytes')
                                        ->label('حداقل Grace ترافیک (بایت)')
                                        ->helperText('حداقل ترافیک اضافی مجاز (پیش‌فرض: ۵۰ مگابایت = ۵۲,۴۲۸,۸۰۰ بایت)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(50 * 1024 * 1024)
                                        ->required()
                                        ->suffix('bytes')
                                        ->extraInputAttributes(['class' => 'font-mono'])
                                        ->hint(function ($state) {
                                            $bytes = (int)$state;
                                            if ($bytes >= 1024 * 1024 * 1024) {
                                                return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
                                            } elseif ($bytes >= 1024 * 1024) {
                                                return round($bytes / (1024 * 1024), 2) . ' MB';
                                            } elseif ($bytes >= 1024) {
                                                return round($bytes / 1024, 2) . ' KB';
                                            }
                                            return $bytes . ' bytes';
                                        }),
                                    
                                    TextInput::make('reseller.time_expiry_grace_minutes')
                                        ->label('فرصت Grace زمانی (دقیقه) - غیرفعال')
                                        ->helperText('⚠️ این تنظیم غیرفعال است. انقضای زمانی بر اساس مرز روز تقویمی (00:00 تهران) اعمال می‌شود.')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(1440) // Max 1 day
                                        ->default(0)
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->suffix('دقیقه'),
                                ])->columns(3),
                        ]),

                    Tabs\Tab::make('توضیحات و راهنما')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Section::make('درباره Grace Settings')
                                ->description('توضیحات کامل تنظیمات فرصت مجاز')
                                ->schema([
                                    \Filament\Forms\Components\Placeholder::make('grace_explanation')
                                        ->label('')
                                        ->content(new \Illuminate\Support\HtmlString('
                                            <div class="prose dark:prose-invert max-w-none">
                                                <h3>Grace چیست؟</h3>
                                                <p>Grace یک فرصت اضافی است که برای جلوگیری از غیرفعال‌سازی ناگهانی کانفیگ‌ها در نظر گرفته می‌شود.
                                                به دلیل تاخیر در همگام‌سازی مصرف، ممکن است کانفیگ‌ها کمی بیش از محدودیت مصرف کنند.</p>
                                                
                                                <h4>محاسبه محدودیت موثر:</h4>
                                                <pre class="bg-gray-100 dark:bg-gray-800 p-4 rounded">محدودیت_موثر = محدودیت_اصلی + max(محدودیت × درصد_grace / 100, حداقل_grace_بایت)</pre>
                                                
                                                <h4>مثال:</h4>
                                                <ul>
                                                    <li>محدودیت فروشنده: ۱۰۰ گیگابایت</li>
                                                    <li>درصد Grace: ۲٪</li>
                                                    <li>حداقل Grace: ۵۰ مگابایت</li>
                                                    <li>محدودیت موثر = ۱۰۰ + max(۱۰۰ × ۰.۰۲, ۰.۰۵) = ۱۰۰ + ۲ = ۱۰۲ گیگابایت</li>
                                                </ul>
                                                
                                                <div class="bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-400 p-4 my-4">
                                                    <h4 class="text-yellow-800 dark:text-yellow-300 font-semibold">⚠️ تغییر در انقضای زمانی</h4>
                                                    <p class="text-yellow-700 dark:text-yellow-400">
                                                        از نسخه جدید، انقضای زمانی بر اساس <strong>مرز روز تقویمی در منطقه زمانی تهران</strong> اعمال می‌شود:
                                                    </p>
                                                    <ul class="text-yellow-700 dark:text-yellow-400">
                                                        <li>کانفیگ با تاریخ انقضای 2025-11-03 دقیقاً در ساعت 00:00 منقضی می‌شود (نه در ساعت 14:30 یا هر زمان دیگری)</li>
                                                        <li>بازه زمانی فروشنده که در 2025-11-03 تمام می‌شود، دقیقاً در نیمه‌شب معتبر نیست</li>
                                                        <li><strong>Grace زمانی دیگر اعمال نمی‌شود</strong> (همیشه 0)</li>
                                                    </ul>
                                                </div>
                                                
                                                <h4>تنظیمات پیشنهادی:</h4>
                                                <ul>
                                                    <li><strong>درصد Grace:</strong> ۲٪ (برای محدودیت‌های بزرگ ترافیک)</li>
                                                    <li><strong>حداقل Grace:</strong> ۵۰ مگابایت (برای محدودیت‌های کوچک ترافیک)</li>
                                                    <li><strong>Grace زمانی:</strong> غیرفعال شده (انقضای زمانی بر اساس مرز روز)</li>
                                                </ul>
                                            </div>
                                        ')),
                                ]),
                            
                            Section::make('نحوه اعمال تنظیمات')
                                ->description('توضیحات نحوه عملکرد سیستم')
                                ->schema([
                                    \Filament\Forms\Components\Placeholder::make('enforcement_flow')
                                        ->label('')
                                        ->content(new \Illuminate\Support\HtmlString('
                                            <div class="prose dark:prose-invert max-w-none">
                                                <h3>فرآیند نظارت و اعمال محدودیت:</h3>
                                                <ol>
                                                    <li><strong>همگام‌سازی مصرف:</strong> هر چند دقیقه یکبار (تنظیم شده در "فاصله زمانی همگام‌سازی")، سیستم مصرف تمام کانفیگ‌های فعال را از پنل‌ها دریافت می‌کند.</li>
                                                    <li><strong>محاسبه مصرف کل:</strong> مصرف تمام کانفیگ‌های فروشنده (فعال و غیرفعال) جمع می‌شود.</li>
                                                    <li><strong>بررسی محدودیت فروشنده:</strong> اگر مصرف کل + Grace از محدودیت فروشنده بیشتر شود یا پنجره زمانی منقضی شده باشد:
                                                        <ul>
                                                            <li>وضعیت فروشنده به "تعلیق" تغییر می‌کند</li>
                                                            <li>تمام کانفیگ‌های فعال به صورت خودکار غیرفعال می‌شوند</li>
                                                            <li>گزارش‌های audit ثبت می‌شود</li>
                                                        </ul>
                                                    </li>
                                                    <li><strong>فعال‌سازی مجدد:</strong> پس از شارژ مجدد یا تمدید، کانفیگ‌هایی که به دلیل محدودیت فروشنده غیرفعال شده بودند، دوباره فعال می‌شوند.</li>
                                                </ol>
                                                
                                                <h4>توجه:</h4>
                                                <p class="text-yellow-600 dark:text-yellow-400">
                                                    تغییر این تنظیمات بلافاصله اعمال می‌شود و در اجرای بعدی job همگام‌سازی مورد استفاده قرار می‌گیرد.
                                                </p>
                                            </div>
                                        ')),
                                ]),
                        ]),
                ])
                ->columnSpanFull(),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $this->form->validate();

        $formData = $this->form->getState();
        
        foreach ($formData as $key => $value) {
            // Handle boolean toggle specifically
            if ($key === 'reseller.allow_config_overrun') {
                $value = $value ? 'true' : 'false';
            }
            
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        Notification::make()
            ->title('تنظیمات با موفقیت ذخیره شد')
            ->success()
            ->send();
    }
}
