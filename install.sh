#!/bin/bash

# --- نصب خودکار پروژه VPNMarket روی Ubuntu 22.04 (نسخه نهایی قطعی) ---
# نویسنده: Arvin Vahed
# https://github.com/arvinvahed/VPNMarket

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}--- خوش آمدید! در حال آماده‌سازی برای نصب پروژه VPNMarket ---${NC}"
echo

read -p "🌐 لطفا دامنه خود را وارد کنید (مثال: vpn.example.com): " DOMAIN
read -p "🗃 یک نام برای دیتابیس انتخاب کنید (مثال: vpnmarket): " DB_NAME
read -p "👤 یک نام کاربری برای دیتابیس انتخاب کنید (مثال: vpnuser): " DB_USER
read -s -p "🔑 یک رمز عبور قوی برای کاربر دیتابیس وارد کنید: " DB_PASS
echo
echo

PROJECT_PATH="/var/www/vpnmarket"
GITHUB_REPO="https://github.com/arvinvahed/VPNMarket.git"

echo -e "${YELLOW}📦 مرحله ۱ از ۷: به‌روزرسانی سیستم و نصب پیش‌نیازها...${NC}"
sudo apt-get update -y
sudo apt-get install -y git curl nginx certbot python3-certbot-nginx mysql-server composer unzip software-properties-common

echo -e "${YELLOW}☕ مرحله ۲ از ۷: افزودن مخزن PHP و نصب PHP 8.3...${NC}"
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y
sudo apt-get install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl

echo -e "${YELLOW}⬇️ مرحله ۳ از ۷: دانلود سورس پروژه از گیت‌هاب...${NC}"
if [ -d "$PROJECT_PATH" ]; then
    sudo rm -rf "$PROJECT_PATH"
fi
sudo git clone $GITHUB_REPO $PROJECT_PATH
cd $PROJECT_PATH

echo -e "${YELLOW}🧩 مرحله ۴ از ۷: ساخت دیتابیس و تنظیم فایل .env...${NC}"
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

sudo cp .env.example .env

# === تغییر کلیدی: استفاده از جداکننده "|" برای دستورات sed برای جلوگیری از خطا ===
sudo sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" .env
sudo sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|" .env
sudo sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env
sudo sed -i "s|APP_URL=.*|APP_URL=http://$DOMAIN|" .env
sudo sed -i "s|APP_ENV=.*|APP_ENV=production|" .env

echo -e "${YELLOW}🧰 مرحله ۵ از ۷: تنظیم دسترسی‌ها و نصب وابستگی‌های پروژه...${NC}"
sudo chown -R www-data:www-data $PROJECT_PATH
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan package:discover --ansi
sudo -u www-data php artisan filament:upgrade

echo -e "${YELLOW}🔗 مرحله ۶ از ۷: اجرای مایگریشن‌ها و لینک کردن Storage...${NC}"
sudo -u www-data php artisan migrate --seed --force
sudo -u www-data php artisan storage:link

echo -e "${YELLOW}🌍 مرحله ۷ از ۷: پیکربندی وب‌سرور (Nginx)...${NC}"
# ... (بقیه اسکریپت بدون تغییر) ...
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
        fastcgi_pass unix:/var/run/php/8.3-fpm.sock;
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
fi
sudo nginx -t && sudo systemctl restart nginx

echo
read -p "🔒 آیا مایل به فعال‌سازی HTTPS رایگان با Certbot هستید؟ (پیشنهاد می‌شود) (y/n): " ENABLE_SSL
if [[ "$ENABLE_SSL" == "y" || "$ENABLE_SSL" == "Y" ]]; then
    echo -e "${YELLOW}در حال نصب گواهی SSL برای $DOMAIN ...${NC}"
    sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m admin@$DOMAIN
fi

echo
echo -e "${GREEN}✅ نصب با موفقیت کامل شد!${NC}"
echo -e "--------------------------------------------------"
echo -e "🌐 آدرس وب‌سایت شما: ${CYAN}https://$DOMAIN${NC}"
echo -e "📂 مسیر فایل‌های پروژه: ${CYAN}$PROJECT_PATH${NC}"
echo -e "🔑 برای ورود به پنل مدیریت، به آدرس ${CYAN}https://$DOMAIN/admin${NC} بروید."
echo -e "--------------------------------------------------"
