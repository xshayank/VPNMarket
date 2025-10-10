<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Models\Inbound;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Nwidart\Modules\Facades\Module;

use Filament\Pages\Page;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\FileUpload;

class ThemeSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string $view = 'filament.pages.theme-settings';
    protected static ?string $navigationLabel = 'تنظیمات سایت';
    protected static ?string $title = 'تنظیمات و محتوای سایت';

    public ?array $data = [];



    public function mount(): void
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();
        $this->form->fill($settings);
    }


    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Tabs')->tabs([

                Tabs\Tab::make('تنظیمات قالب')
                    ->icon('heroicon-o-swatch')
                    ->schema([
                        Select::make('active_theme')->label('قالب اصلی سایت')->options([
                            'welcome' => 'قالب خوش‌آمدگویی',
                            'cyberpunk' => 'قالب سایبرپانک',
                            'dragon' => 'قالب اژدها', // <-- اینجا اضافه شد
                            'arcane' => 'قالب آرکین (جادوی تکنولوژی)',
                        ])->default('welcome')->live()->required(),




                        Select::make('active_auth_theme')
                            ->label('قالب صفحات ورود/ثبت‌نام')
                            ->options([
                                'default' => 'قالب پیش‌فرض (Breeze)',
                                'cyberpunk' => 'قالب سایبرپانک',
                                'dragon' => 'قالب اژدها',

                            ])
                            ->default('cyberpunk')->live()->required(),


                        FileUpload::make('site_logo')
                            ->label('لوگوی سایت')
                            ->image()
                            ->directory('logos')
                            ->visibility('public')
                            ->helperText('بهترین اندازه: ارتفاع ۴۰ پیکسل. پس‌زمینه شفاف (PNG) توصیه می‌شود.'),

                   ]),




                Tabs\Tab::make('محتوای قالب اژدها')->icon('heroicon-o-fire')->visible(fn (Get $get) => $get('active_theme') === 'dragon')->schema([
                    Section::make('عمومی')->schema([
                        TextInput::make('dragon_navbar_brand')->label('نام برند در Navbar')->placeholder('EZHDEHA VPN'),
                        TextInput::make('dragon_footer_text')->label('متن فوتر')->placeholder('© 2025 Ezhdeha Networks. قدرت آتشین.'),
                    ])->columns(2),

                    Section::make('بخش اصلی (Hero Section)')->schema([
                        TextInput::make('dragon_hero_title')->label('تیتر اصلی')->placeholder('مرزهای دیجیتال را بسوزان'),
                        Textarea::make('dragon_hero_subtitle')->label('زیرتیتر')->rows(2)->placeholder('سرعتی افسانه‌ای و امنیتی نفوذناپذیر. سلطه بر اینترنت.'),
                        TextInput::make('dragon_hero_button_text')->label('متن دکمه اصلی')->placeholder('فتح شبکه'),
                    ]),

                    Section::make('بخش ویژگی‌ها (Features)')->schema([
                        TextInput::make('dragon_features_title')->label('عنوان بخش')->placeholder('عناصر قدرت اژدها'),

                        TextInput::make('dragon_feature1_title')->label('عنوان ویژگی ۱')->placeholder('نفس آتشین (سرعت)'),
                        Textarea::make('dragon_feature1_desc')->label('توضیح ویژگی ۱')->rows(2)->helperText('سریع‌تر از یک بال اژدها. داده‌های شما با بالاترین سرعت منتقل می‌شوند.'),

                        TextInput::make('dragon_feature2_title')->label('عنوان ویژگی ۲')->placeholder('زره فلس‌دار (امنیت)'),
                        Textarea::make('dragon_feature2_desc')->label('توضیح ویژگی ۲')->rows(2)->helperText('دیوارهای فیلترینگ شکسته می‌شوند. هویت شما همیشه پنهان می‌ماند.'),

                        TextInput::make('dragon_feature3_title')->label('عنوان ویژگی ۳')->placeholder('بینایی فراتر (آزادی)'),
                        Textarea::make('dragon_feature3_desc')->label('توضیح ویژگی ۳')->rows(2)->helperText('کل شبکه در اختیار شماست. هیچ مرزی وجود نخواهد داشت.'),
                    ])->columns(3),

                    Section::make('بخش قیمت‌گذاری (Pricing)')->schema([
                        TextInput::make('dragon_pricing_title')->label('عنوان بخش')->placeholder('پیمان خون'),
                    ]),

                    Section::make('بخش سوالات متداول (FAQ)')->schema([
                        TextInput::make('dragon_faq_title')->label('عنوان بخش')->placeholder('طومارهای باستانی'),

                        TextInput::make('dragon_faq1_q')->label('سوال اول')->placeholder('آیا این سرویس باستانی است؟'),
                        Textarea::make('dragon_faq1_a')->label('پاسخ اول')->rows(2)->helperText('خیر، این شبکه باستانی نیست اما از قدرت‌های افسانه‌ای اژدهایان برای شکستن سدها و اتصال شما استفاده می‌کند. سریع، امن و پایدار.'),

                        TextInput::make('dragon_faq2_q')->label('سوال دوم')->placeholder('چگونه قدرت اژدها را فعال کنم؟'),
                        Textarea::make('dragon_faq2_a')->label('پاسخ دوم')->rows(2)->helperText('کافیست پلن مورد نظر خود را انتخاب کرده و مراحل پرداخت را طی کنید. کانفیگ نهایی به شما تحویل داده می‌شود تا به راحتی از تمام قدرت اژدها استفاده کنید.'),
                    ]),
                ]),



                // --- تب محتوای قالب آرکین ---
                Tabs\Tab::make('محتوای قالب آرکین')->icon('heroicon-o-sparkles')->visible(fn (Get $get) => $get('active_theme') === 'arcane')->schema([
                    Section::make('عمومی')->schema([
                        TextInput::make('arcane_navbar_brand')->label('نام برند')->placeholder('ARCANE'),
                        TextInput::make('arcane_footer_text')->label('متن فوتر')->placeholder('© 2025 Arcane Networks'),
                    ]),
                    Section::make('بخش اصلی (Hero Section)')->schema([
                        TextInput::make('arcane_hero_title')->label('تیتر اصلی')->placeholder('کدگشایی اینترنت آزاد'),
                        Textarea::make('arcane_hero_subtitle')->label('زیرتیتر')->rows(2)->placeholder('با پروتکل‌های رمزنگاری شده ما، به شبکه‌ای فراتر از محدودیت‌ها متصل شوید...'),
                        TextInput::make('arcane_hero_button')->label('متن دکمه')->placeholder('دسترسی به شبکه'),
                    ]),
                    Section::make('بخش ویژگی‌ها (Features)')->schema([
                        TextInput::make('arcane_features_title')->label('عنوان بخش')->placeholder('اصول جادوی دیجیتال'),
                        TextInput::make('arcane_feature1_title')->label('عنوان ویژگی ۱')->placeholder('پروتکل‌های کوانتومی'),
                        Textarea::make('arcane_feature1_desc')->label('توضیح ویژگی ۱')->rows(2),
                        TextInput::make('arcane_feature2_title')->label('عنوان ویژگی ۲')->placeholder('پنهان‌سازی هویت'),
                        Textarea::make('arcane_feature2_desc')->label('توضیح ویژگی ۲')->rows(2),
                        TextInput::make('arcane_feature3_title')->label('عنوان ویژگی ۳')->placeholder('اتصال بی‌پایان'),
                        Textarea::make('arcane_feature3_desc')->label('توضیح ویژگی ۳')->rows(2),
                    ])->columns(3),
                    Section::make('بخش قیمت‌گذاری (Pricing)')->schema([
                        TextInput::make('arcane_pricing_title')->label('عنوان بخش')->placeholder('انتخاب دسترسی'),
                    ]),
                    Section::make('بخش سوالات متداول (FAQ)')->schema([
                        TextInput::make('arcane_faq_title')->label('عنوان بخش')->placeholder('سوالات متداول'),
                        TextInput::make('arcane_faq1_q')->label('سوال اول')->placeholder('آیا اطلاعات کاربران ذخیره می‌شود؟'),
                        Textarea::make('arcane_faq1_a')->label('پاسخ اول')->rows(2),
                        TextInput::make('arcane_faq2_q')->label('سوال دوم')->placeholder('چگونه می‌توانم سرویس را روی چند دستگاه استفاده کنم؟'),
                        Textarea::make('arcane_faq2_a')->label('پاسخ دوم')->rows(2),
                    ]),
                ]),


                Tabs\Tab::make('محتوای قالب سایبرپانک')
                    ->icon('heroicon-o-bolt')
                    ->visible(fn (Get $get) => $get('active_theme') === 'cyberpunk')
                    ->schema([
                        Section::make('عمومی')->schema([
                            TextInput::make('cyberpunk_navbar_brand')->label('نام برند در Navbar')->placeholder('VPN Market'),
                            TextInput::make('cyberpunk_footer_text')->label('متن فوتر')->placeholder('© 2025 Quantum Network. اتصال برقرار شد.'),
                        ])->columns(2),
                        Section::make('بخش اصلی (Hero Section)')->schema([
                            TextInput::make('cyberpunk_hero_title')->label('تیتر اصلی')->placeholder('واقعیت را هک کن'),
                            Textarea::make('cyberpunk_hero_subtitle')->label('زیرتیتر')->rows(3)->helperText("متن پیش‌فرض: با پروتکل‌های کوانتومی ما، مرزهای دیجیتال را جابجا کنید. اتصال شما، قلمرو شماست."),
                            TextInput::make('cyberpunk_hero_button_text')->label('متن دکمه اصلی')->placeholder('دریافت دسترسی'),
                        ]),
                        Section::make('بخش ویژگی‌ها (Features)')->schema([
                            TextInput::make('cyberpunk_features_title')->label('عنوان بخش')->placeholder('سیستم‌عامل آزادی دیجیتال شما'),
                            TextInput::make('cyberpunk_feature1_title')->label('عنوان ویژگی ۱')->placeholder('پروتکل Warp'),
                            Textarea::make('cyberpunk_feature1_desc')->label('توضیح ویژگی ۱')->rows(2)->helperText('متن پیش‌فرض: تکنولوژی انحصاری برای عبور از شدیدترین فیلترینگ‌ها با سرعت نور.'),
                            TextInput::make('cyberpunk_feature2_title')->label('عنوان ویژگی ۲')->placeholder('حالت Ghost'),
                            Textarea::make('cyberpunk_feature2_desc')->label('توضیح ویژگی ۲')->rows(2)->helperText('متن پیش‌فرض: ناشناس بمانید. ترافیک شما غیرقابل شناسایی و ردیابی خواهد بود.'),
                            TextInput::make('cyberpunk_feature3_title')->label('عنوان ویژگی ۳')->placeholder('اتصال پایدار'),
                            Textarea::make('cyberpunk_feature3_desc')->label('توضیح ویژگی ۳')->rows(2)->helperText('متن پیش‌فرض: بدون قطعی. سرورهای ما همیشه برای ارائه بهترین پایداری آنلاین هستند.'),
                            TextInput::make('cyberpunk_feature4_title')->label('عنوان ویژگی ۴')->placeholder('پشتیبانی Elite'),
                            Textarea::make('cyberpunk_feature4_desc')->label('توضیح ویژگی ۴')->rows(2)->helperText('متن پیش‌فرض: تیم پشتیبانی متخصص ما، آماده حل چالش‌های فنی شما هستند.'),
                        ])->columns(2),
                        Section::make('بخش قیمت‌گذاری (Pricing)')->schema([
                            TextInput::make('cyberpunk_pricing_title')->label('عنوان بخش')->placeholder('انتخاب پلن اتصال'),
                        ]),
                        Section::make('بخش سوالات متداول (FAQ)')->schema([
                            TextInput::make('cyberpunk_faq_title')->label('عنوان بخش')->placeholder('اطلاعات طبقه‌بندی شده'),
                            TextInput::make('cyberpunk_faq1_q')->label('سوال اول')->placeholder('آیا اطلاعات کاربران ذخیره می‌شود؟'),
                            Textarea::make('cyberpunk_faq1_a')->label('پاسخ اول')->rows(2)->helperText('متن پیش‌فرض: خیر. ما به حریم خصوصی شما متعهدیم و هیچ‌گونه گزارشی از فعالیت‌های شما (No-Log Policy) ذخیره نمی‌کنیم.'),
                            TextInput::make('cyberpunk_faq2_q')->label('سوال دوم')->placeholder('چگونه می‌توانم سرویس را روی چند دستگاه استفاده کنم؟'),
                            Textarea::make('cyberpunk_faq2_a')->label('پاسخ دوم')->rows(2)->helperText('متن پیش‌فرض: پس از خرید، یک کانفیگ دریافت می‌کنید که می‌توانید بر اساس پلن خود، آن را همزمان روی چند دستگاه (موبایل، کامپیوتر و...) استفاده کنید.'),
                        ]),
                    ]),



                Tabs\Tab::make('محتوای صفحات ورود')
                    ->icon('heroicon-o-key')
                    ->schema([
                        Section::make('متن‌های عمومی')->schema([TextInput::make('auth_brand_name')->label('نام برند')->placeholder('VPNMarket'),]),
                        Section::make('صفحه ورود (Login)')->schema([
                            TextInput::make('auth_login_title')->label('عنوان فرم ورود')->placeholder('ورود به حساب کاربری'),
                            TextInput::make('auth_login_email_placeholder')->label('متن داخل فیلد ایمیل')->placeholder('ایمیل خود را وارد کنید'),
                            TextInput::make('auth_login_password_placeholder')->label('متن داخل فیلد رمز عبور')->placeholder('رمز عبور'),
                            TextInput::make('auth_login_remember_me_label')->label('متن "مرا به خاطر بسپار"')->placeholder('مرا به خاطر بسپار'),
                            TextInput::make('auth_login_forgot_password_link')->label('متن لینک "فراموشی رمز"')->placeholder('فراموشی رمز عبور'),
                            TextInput::make('auth_login_submit_button')->label('متن دکمه ورود')->placeholder('ورود'),
                            TextInput::make('auth_login_register_link')->label('متن لینک ثبت‌نام')->placeholder('حساب کاربری ندارید؟ یک حساب بسازید'),
                        ])->columns(2),
                        Section::make('صفحه ثبت‌نام (Register)')->schema([
                            TextInput::make('auth_register_title')->label('عنوان فرم ثبت‌نام')->placeholder('ایجاد حساب کاربری جدید'),
                            TextInput::make('auth_register_name_placeholder')->label('متن داخل فیلد نام')->placeholder('نام کامل'),
                            TextInput::make('auth_register_password_confirm_placeholder')->label('متن داخل فیلد تکرار رمز')->placeholder('تکرار رمز عبور'),
                            TextInput::make('auth_register_submit_button')->label('متن دکمه ثبت‌نام')->placeholder('ثبت نام'),
                            TextInput::make('auth_register_login_link')->label('متن لینک ورود')->placeholder('قبلاً ثبت‌نام کرده‌اید؟ وارد شوید'),
                        ])->columns(2),
                    ]),

                Tabs\Tab::make('تنظیمات پنل V2Ray')
                    ->icon('heroicon-o-server-stack')
                    ->schema([
                        Radio::make('panel_type')->label('نوع پنل')->options(['marzban' => 'مرزبان', 'xui' => 'سنایی / X-UI'])->live()->required(),
                        Section::make('تنظیمات پنل مرزبان')->visible(fn (Get $get) => $get('panel_type') === 'marzban')->schema([
                            TextInput::make('marzban_host')->label('آدرس پنل مرزبان')->required(),
                            TextInput::make('marzban_sudo_username')->label('نام کاربری ادمین')->required(),
                            TextInput::make('marzban_sudo_password')->label('رمز عبور ادمین')->password()->required(),
                            TextInput::make('marzban_node_hostname')->label('آدرس دامنه/سرور برای کانفیگ')->required(),
                        ]),
                        Section::make('تنظیمات پنل سنایی / X-UI')->visible(fn (Get $get) => $get('panel_type') === 'xui')->schema([
                            TextInput::make('xui_host')->label('آدرس کامل پنل سنایی')->required(),
                            TextInput::make('xui_user')->label('نام کاربری')->required(),
                            TextInput::make('xui_pass')->label('رمز عبور')->password()->required(),
                            Select::make('xui_default_inbound_id')->label('اینباند پیش‌فرض')
                                ->options(Inbound::all()->pluck('title', 'id'))
                                ->searchable()
                                ->required()
                                ->helperText('این لیست از بخش "اینباندها" خوانده می‌شود.'),

                            Radio::make('xui_link_type')
                                ->label('نوع لینک تحویلی به مشتری')
                                ->options(['single' => 'لینک تکی (VLESS/VMESS)', 'subscription' => 'لینک سابسکریپشن'])
                                ->default('single')
                                ->required(),

                            TextInput::make('xui_subscription_url_base')
                                ->label('آدرس پایه لینک سابسکریپشن')
                                ->placeholder('https://panel.hosthubpanel.ir:2083')
                                ->helperText('آدرس اصلی دامنه و پورت سابسکریپشن را وارد کنید (بدون /sub/).'),


                        ]),
                    ]),
                Tabs\Tab::make('تنظیمات پرداخت')
                    ->icon('heroicon-o-credit-card')
                    ->schema([
                        Section::make('پرداخت کارت به کارت')->schema([
                            TextInput::make('payment_card_number')->label('شماره کارت'),
                            TextInput::make('payment_card_holder_name')->label('نام صاحب حساب'),
                            Textarea::make('payment_card_instructions')->label('توضیحات اضافی')->rows(3),
                        ]),
                    ]),


                Tabs\Tab::make('تنظیمات ربات تلگرام')
                    ->icon('heroicon-o-paper-airplane')
                    ->schema([
                        Section::make('اطلاعات اتصال ربات')
                            ->schema([
                                TextInput::make('telegram_bot_token')
                                    ->label('توکن ربات تلگرام (Bot Token)')
                                    ->password()
                                    ->required()
                                    ->helperText('توکن را از BotFather در تلگرام دریافت کنید.'),

                                TextInput::make('telegram_admin_chat_id')
                                    ->label('چت آی‌دی ادمین')
                                    ->numeric()
                                    ->required()
                                    ->helperText('آی‌دی عددی اکانت تلگرام ادمین برای دریافت نوتیفیکیشن‌ها.'),

                            ]),
                    ]),
            ])->columnSpanFull(),
        ])->statePath('data');
    }


    public function submit(): void
    {
        $formData = $this->form->getState();
        foreach ($formData as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }
        Notification::make()->title('تنظیمات با موفقیت ذخیره شد.')->success()->send();
    }
}
