#!/bin/bash

# ==============================================================================
# ===              اسکریپت آپدیت هوشمند و امن پروژه VPNMarket                ===
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

if [ "$PWD" != "$PROJECT_PATH" ]; then
  echo -e "${RED}خطا: این اسکریپت باید از داخل پوشه پروژه ('cd $PROJECT_PATH') اجرا شود.${NC}"
  exit 1
fi

if [ ! -f ".env" ]; then
    echo -e "${RED}خطا: فایل .env یافت نشد!${NC}"
    exit 1
fi

echo

# --- مرحله ۱: پشتیبان‌گیری و حالت تعمیر ---
echo -e "${YELLOW}مرحله ۱ از ۷: ایجاد نسخه پشتیبان از .env و فعال‌سازی حالت تعمیر...${NC}"
sudo cp .env .env.bak.$(date +%Y-%m-%d_%H-%M-%S)
echo "یک نسخه پشتیبان از فایل .env شما در همین مسیر ساخته شد."
sudo -u $WEB_USER php artisan down || true

# --- مرحله ۲: دریافت آخرین کدها از گیت‌هاب ---
echo -e "${YELLOW}مرحله ۲ از ۷: دریافت آخرین تغییرات از گیت‌هاب...${NC}"
echo "کنار گذاشتن تغییرات محلی (در صورت وجود)..."
sudo git stash push --include-untracked
echo "در حال دریافت آخرین نسخه از برنچ main..."
sudo git pull origin main
# (ما دیگر stash pop را اجرا نمی‌کنیم تا از تداخل جلوگیری شود. تغییرات کاربر کنار گذاشته می‌شود)

# --- مرحله ۳: تنظیم دسترسی‌های صحیح ---
echo -e "${YELLOW}مرحله ۳ از ۷: تنظیم مجدد دسترسی‌های فایل...${NC}"
sudo chown -R $WEB_USER:$WEB_USER .

# --- مرحله ۴: آپدیت وابستگی‌های PHP (Composer) ---
echo -e "${YELLOW}مرحله ۴ از ۷: به‌روزرسانی پکیج‌های PHP...${NC}"
sudo -u $WEB_USER composer install --no-dev --optimize-autoloader

# --- مرحله ۵: آپدیت وابستگی‌های Frontend (NPM) ---
echo -e "${YELLOW}مرحله ۵ از ۷: به‌روزرسانی پکیج‌های Node.js و کامپایل assets...${NC}"
# استفاده از --cache برای جلوگیری از مشکلات دسترسی
sudo -u $WEB_USER npm install --cache .npm --prefer-offline
sudo -u $WEB_USER npm run build
sudo rm -rf .npm
echo "فایل‌های JS/CSS برای محیط Production کامپایل شدند."

# --- مرحله ۶: آپدیت دیتابیس ---
echo -e "${YELLOW}مرحله ۶ از ۷: اجرای مایگریشن‌های جدید دیتابیس...${NC}"
sudo -u $WEB_USER php artisan migrate --force

# --- مرحله ۷: پاکسازی کش‌ها و خروج از حالت تعمیر ---
echo -e "${YELLOW}مرحله ۷ از ۷: پاکسازی کش‌ها و فعال‌سازی مجدد سایت...${NC}"
# ===> تغییر کلیدی: جایگزینی optimize با optimize:clear برای جلوگیری از خطا <===
sudo -u $WEB_USER php artisan optimize:clear
sudo -u $WEB_USER php artisan up

echo
echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN}✅ پروژه با موفقیت به آخرین نسخه آپدیت شد!${NC}"
echo -e "${GREEN}=====================================================${NC}"
