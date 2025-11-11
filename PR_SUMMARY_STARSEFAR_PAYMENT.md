# StarsEfar Wallet Charge Integration

## Endpoints
- `POST /wallet/charge/starsefar/initiate` — ایجاد لینک پرداخت استارز (نیاز به احراز هویت).
- `GET /wallet/charge/starsefar/status/{orderId}` — استعلام وضعیت تراکنش (نیاز به احراز هویت).
- `POST /webhooks/Stars-Callback` — وب‌هوک استارز برای تایید پرداخت (عمومی).

## تنظیمات
- فایل پیکربندی: `config/starsefar.php` با متغیرهای ENV جدید مانند `STARSEFAR_API_KEY` و `STARSEFAR_ENABLE`.
- بخش جدید در صفحه «تنظیمات پرداخت» پنل ادمین برای فعال‌سازی و مدیریت کلید، آدرس API، مسیر callback و هدف پیش‌فرض.

## تست‌ها
- تست‌های فیچر `tests/Feature/StarsefarControllerTest.php` سناریوهای اصلی (ایجاد لینک، بررسی وضعیت، وب‌هوک) را پوشش می‌دهند.
- برای اجرای تست‌ها: `php artisan test --filter=StarsefarControllerTest`.
