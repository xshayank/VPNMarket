#!/bin/bash

# ==================================================================================
# === اسکریپت نصب نهایی، هوشمند و ضد خطا برای پروژه VPNMarket روی Ubuntu 22.04    ===
# === نویسنده: Arvin Vahed                                                       ===
# === https://github.com/arvinvahed/VPNMarket                                    ===
# === این اسکریپت برای حداکثر پایداری و جلوگیری از خطاهای رایج بهینه‌سازی شده است. ===
# ==================================================================================

set -e # توقف اسکریپت در صورت بروز هرگونه خطا

# --- تعریف متغیرها و رنگ‌ها ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${CYAN}--- خوش آمدید! در حال آماده‌سازی برای نصب پروژه VPNMarket ---${NC}"
echo

# --- دریافت اطلاعات از کاربر ---
read -p "🌐 لطفا دامنه خود را وارد کنید (مثال: market.example.com): " DOMAIN
DOMAIN=$(echo $DOMAIN | sed 's|http[s]*://||g' | sed 's|/.*||g')

read -p "🗃 یک نام برای دیتابیس انتخاب کنید (مثال: vpnmarket): " DB_NAME
read -p "👤 یک نام کاربری برای دیتابیس انتخاب کنید (مثال: vpnuser): " DB_USER
read -s -p "🔑 یک رمز عبور قوی برای کاربر دیتابیس وارد کنید: " DB_PASS
echo
echo

# --- متغیرهای پروژه ---
PROJECT_PATH="/var/www/vpnmarket"
GITHUB_REPO="https://github.com/arvinvahed/VPNMarket.git"

# --- مرحله ۱: نصب تمام پیش‌نیازها ---
echo -e "${YELLOW}📦 مرحله ۱ از ۸: به‌روزرسانی سیستم و نصب تمام پیش‌نیازها...${NC}"
# --->>> اضافه کردن DEBIAN_FRONTEND=noninteractive برای جلوگیری از سوالات در حین نصب <<<---
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y git curl nginx certbot python3-certbot-nginx mysql-server composer unzip software-properties-common gpg

# --- مرحله ۲: نصب PHP ---
echo -e "${YELLOW}☕ مرحله ۲ از ۸: افزودن مخزن PHP و نصب PHP 8.3...${NC}"
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y
# --->>> لیست کامل افزونه‌های PHP برای لاراول و فیلامنت <<<---
sudo apt-get install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd php8.3-dom

# --- مرحله ۳: فعال‌سازی سرویس‌ها ---
echo -e "${YELLOW}🚀 مرحله ۳ از ۸: فعال‌سازی سرویس‌های PHP-FPM و MySQL...${NC}"
sudo systemctl enable php8.3-fpm
sudo systemctl start php8.3-fpm
sudo systemctl enable mysql
sudo systemctl start mysql

# --- (بقیه اسکریپت بدون تغییر باقی می‌ماند) ---
echo -e "${YELLOW}⬇️ مرحله ۴ از ۸: دانلود سورس پروژه از گیت‌هاب...${NC}"
if [ -d "$PROJECT_PATH" ]; then
    sudo rm -rf "$PROJECT_PATH"
fi
sudo git clone $GITHUB_REPO $PROJECT_PATH
cd $PROJECT_PATH

echo -e "${YELLOW}🧩 مرحله ۵ از ۸: ساخت دیتابیس و تنظیم فایل .env...${NC}"
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

sudo cp .env.example .env
sudo sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" .env
sudo sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|" .env
sudo sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env
sudo sed -i "s|APP_URL=.*|APP_URL=https://$DOMAIN|" .env
sudo sed -i "s|APP_ENV=.*|APP_ENV=production|" .env

echo -e "${YELLOW}🧰 مرحله ۶ از ۸: تنظیم دسترسی‌ها و نصب وابستگی‌های پروژه...${NC}"
sudo chown -R www-data:www-data $PROJECT_PATH
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan package:discover --ansi
sudo -u www-data php artisan filament:upgrade

echo -e "${YELLOW}🔗 مرحله ۷ از ۸: اجرای مایگریشن‌ها و لینک کردن Storage...${NC}"
sudo -u www-data php artisan migrate --seed --force
sudo -u www-data php artisan storage:link

echo -e "${YELLOW}🌍 مرحله ۸ از ۸: پیکربندی نهایی وب‌سرور (Nginx)...${NC}"
PHP_FPM_SOCK_PATH=$(grep -oP 'listen\s*=\s*\K.*' /etc/php/8.3/fpm/pool.d/www.conf | head -n 1 | sed 's/;//g' | xargs)
echo "مسیر سوکت PHP-FPM با موفقیت پیدا شد: $PHP_FPM_SOCK_PATH"

sudo tee /etc/nginx/sites-available/vpnmarket >/dev/null <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $PROJECT_PATH/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:$PHP_FPM_SOCK_PATH;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

sudo ln -sf /etc/nginx/sites-available/vpnmarket /etc/nginx/sites-enabled/
if [ -f "/etc/nginx/sites-enabled/default" ]; then
    sudo rm /etc/nginx/sites-enabled/default
    echo "فایل کانفیگ پیش‌فرض Nginx حذف شد."
fi
sudo nginx -t && sudo systemctl restart nginx
echo "کانفیگ Nginx با موفقیت تست و بارگذاری شد."

echo
read -p "🔒 آیا مایل به فعال‌سازی HTTPS رایگان با Certbot هستید؟ (پیشنهاد می‌شود) (y/n): " ENABLE_SSL
if [[ "$ENABLE_SSL" == "y" || "$ENABLE_SSL" == "Y" ]]; then
    echo -e "${YELLOW}در حال نصب گواهی SSL برای $DOMAIN ...${NC}"
    sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m admin@$DOMAIN
fi

echo
echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN}✅ نصب با موفقیت کامل شد!${NC}"
echo -e "--------------------------------------------------"
echo -e "🌐 آدرس وب‌سایت شما: ${CYAN}https://$DOMAIN${NC}"
echo -e "🔑 برای ورود به پنل مدیریت، به آدرس ${CYAN}https://$DOMAIN/admin${NC} بروید."
echo -e "   - ایمیل: admin@example.com"
echo -e "   - رمز عبور: password"
echo -e "${GREEN}=====================================================${NC}"
