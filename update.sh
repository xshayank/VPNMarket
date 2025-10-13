#!/bin/bash

# ==============================================================================
# ===              اسکریپت آپدیت پروژه VPNMarket                   ===
# ==============================================================================

set -e # توقف اسکریپت در صورت بروز هرگونه خطا

# --- تعریف متغیرها و رنگ‌ها ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m' # No Color

PROJECT_PATH="/var/www/vpnmarket"
WEB_USER="www-data"

# --- مرحله ۰: بررسی‌های اولیه ---
echo -e "${CYAN}--- شروع فرآیند آپدیت پروژه VPNMarket ---${NC}"

# بررسی اینکه آیا اسکریپت از پوشه صحیح اجرا می‌شود
if [ "$PWD" != "$PROJECT_PATH" ]; then
  echo -e "${RED}خطا: این اسکریپت باید از داخل پوشه پروژه اجرا شود.${NC}"
  echo -e "لطفاً ابتدا دستور 'cd $PROJECT_PATH' را اجرا کرده و سپس دوباره تلاش کنید."
  exit 1
fi

# بررسی وجود فایل .env
if [ ! -f ".env" ]; then
    echo -e "${RED}خطا: فایل .env یافت نشد! فرآیند آپدیت قابل انجام نیست.${NC}"
    exit 1
fi

echo

# --- مرحله ۱: پشتیبان‌گیری و حالت تعمیر ---
echo -e "${YELLOW}مرحله ۱ از ۸: ایجاد نسخه پشتیبان از .env و فعال‌سازی حالت تعمیر...${NC}"
sudo cp .env .env.bak.$(date +%Y-%m-%d_%H-%M-%S)
echo -e "یک نسخه پشتیبان از فایل .env شما ساخته شد."
sudo -u $WEB_USER php artisan down || true

# --- مرحله ۲: دریافت آخرین کدها از گیت‌هاب ---
echo -e "${YELLOW}مرحله ۲ از ۸: دریافت آخرین تغییرات از گیت‌هاب...${NC}"
echo -e "کنار گذاشتن تغییرات محلی (در صورت وجود)..."
sudo git stash || true
echo -e "در حال دریافت آخرین نسخه از برنچ main..."
sudo git pull origin main
echo -e "بازگرداندن تغییرات محلی (در صورت وجود)..."
sudo git stash pop || true # || true باعث می‌شود اگر stash خالی بود، خطا ندهد

# --- مرحله ۳: تنظیم دسترسی‌های صحیح ---
echo -e "${YELLOW}مرحله ۳ از ۸: تنظیم مجدد دسترسی‌های فایل...${NC}"
sudo chown -R $WEB_USER:$WEB_USER .

# --- مرحله ۴: آپدیت وابستگی‌های PHP (Composer) ---
echo -e "${YELLOW}مرحله ۴ از ۸: به‌روزرسانی پکیج‌های PHP با Composer...${NC}"
sudo -u $WEB_USER composer install --no-dev --optimize-autoloader

# --- مرحله ۵: آپدیت وابستگی‌های Frontend (NPM) ---
echo -e "${YELLOW}مرحله ۵ از ۸: به‌روزرسانی پکیج‌های Node.js و کامپایل assets...${NC}"
# نصب مجدد Node modules و کامپایل
sudo -u $WEB_USER HOME=/var/www npm install
sudo -u $WEB_USER HOME=/var/www npm run build
echo "فایل‌های JS/CSS برای محیط Production کامپایل شدند."

# --- مرحله ۶: آپدیت دیتابیس ---
echo -e "${YELLOW}مرحله ۶ از ۸: اجرای مایگریشن‌های جدید دیتابیس (در صورت وجود)...${NC}"
sudo -u $WEB_USER php artisan migrate --force

# --- مرحله ۷: پاکسازی و بهینه‌سازی کش‌ها ---
echo -e "${YELLOW}مرحله ۷ از ۸: پاکسازی و ساخت مجدد کش‌ها برای حداکثر سرعت...${NC}"
# ابتدا همه کش‌ها را پاک می‌کنیم
sudo -u $WEB_USER php artisan optimize:clear
# سپس کش‌های جدید را می‌سازیم (بهتر از پاک کردن تک تک است)
sudo -u $WEB_USER php artisan optimize

# --- مرحله ۸: خروج از حالت تعمیر ---
echo -e "${YELLOW}مرحله ۸ از ۸: فعال‌سازی مجدد سایت...${NC}"
sudo -u $WEB_USER php artisan up

echo
echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN}✅ پروژه با موفقیت به آخرین نسخه آپدیت شد!${NC}"
echo -e "${GREEN}=====================================================${NC}"
