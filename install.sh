#!/bin/bash

# --- نصب خودکار پروژه VPNMarket روی Ubuntu 22.04 ---
# نویسنده: Arvin Vahed
# https://github.com/arvinvahed/VPNMarket

# توقف اسکریپت در صورت بروز هرگونه خطا
set -e

# تعریف رنگ‌ها برای خروجی زیباتر
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}--- خوش آمدید! در حال آماده‌سازی برای نصب پروژه VPNMarket ---${NC}"
echo

# دریافت اطلاعات لازم از کاربر
read -p "🌐 لطفا دامنه خود را وارد کنید (مثال: vpn.example.com): " DOMAIN
read -p "🗃 یک نام برای دیتابیس انتخاب کنید (مثال: vpnmarket): " DB_NAME
read -p "👤 یک نام کاربری برای دیتابیس انتخاب کنید (مثال: vpnuser): " DB_USER
read -s -p "🔑 یک رمز عبور قوی برای کاربر دیتابیس وارد کنید: " DB_PASS
echo
echo

# متغیرهای ثابت پروژه
PROJECT_PATH="/var/www/vpnmarket"
GITHUB_REPO="https://github.com/arvinvahed/VPNMarket.git"

# --- مرحله ۱: نصب پیش‌نیازهای اصلی سیستم ---
echo -e "${YELLOW}📦 مرحله ۱ از ۸: به‌روزرسانی سیستم و نصب پیش‌نیازهای اصلی...${NC}"
sudo apt-get update -y
sudo apt-get install -y git curl nginx certbot python3-certbot-nginx mysql-server composer unzip software-properties-common

# --- مرحله ۲: افزودن مخزن PHP و نصب PHP ---
echo -e "${YELLOW}☕ مرحله ۲ از ۸: افزودن مخزن PHP برای دریافت آخرین نسخه...${NC}"
# این بخش مشکل عدم شناسایی پکیج PHP را حل می‌کند
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y

echo -e "${YELLOW}🐘 مرحله ۳ از ۸: نصب PHP 8.2 و افزونه‌های مورد نیاز...${NC}"
sudo apt-get install -y php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath

# --- مرحله ۴: کلون کردن پروژه و تنظیم لاراول ---
echo -e "${YELLOW}⬇️ مرحله ۴ از ۸: دانلود سورس پروژه از گیت‌هاب...${NC}"
# اگر پوشه از قبل وجود داشت، آن را حذف می‌کنیم تا از بروز خطا جلوگیری شود
if [ -d "$PROJECT_PATH" ]; then
    sudo rm -rf "$PROJECT_PATH"
fi
sudo git clone $GITHUB_REPO $PROJECT_PATH
cd $PROJECT_PATH

echo -e "${YELLOW}⚙️ مرحله ۵ از ۸: نصب وابستگی‌ها و تنظیمات اولیه لاراول...${NC}"
sudo cp .env.example .env
# اجرای Composer با کاربر www-data برای جلوگیری از مشکلات دسترسی
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo php artisan key:generate

# --- مرحله ۵: راه‌اندازی دیتابیس ---
echo -e "${YELLOW}🧩 مرحله ۶ از ۸: ساخت دیتابیس و کاربر MySQL...${NC}"
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# جایگزینی اطلاعات دیتابیس در فایل .env
sudo sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
sudo sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
sudo sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env
sudo sed -i "s|APP_URL=.*|APP_URL=http://$DOMAIN|" .env
sudo sed -i "s/APP_ENV=.*/APP_ENV=production/" .env

echo -e "${YELLOW}🔗 در حال اجرای مایگریشن‌ها و ساخت جداول دیتابیس...${NC}"
sudo php artisan migrate --seed --force
sudo php artisan storage:link

# --- مرحله ۶: تنظیم دسترسی‌ها و وب‌سرور ---
echo -e "${YELLOW}🧰 مرحله ۷ از ۸: تنظیم دسترسی‌های صحیح فایل‌ها...${NC}"
sudo chown -R www-data:www-data $PROJECT_PATH
sudo chmod -R 775 $PROJECT_PATH/storage $PROJECT_PATH/bootstrap/cache

echo -e "${YELLOW}🌍 مرحله ۸ از ۸: پیکربندی وب‌سرور (Nginx)...${NC}"
sudo tee /etc/nginx/sites-available/vpnmarket >/dev/null <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $PROJECT_PATH/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosiff";

    index index.php;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

# فعال‌سازی کانفیگ و ریستارت Nginx
sudo ln -sf /etc/nginx/sites-available/vpnmarket /etc/nginx/sites-enabled/
# حذف کانفیگ پیش‌فرض برای جلوگیری از تداخل
if [ -f "/etc/nginx/sites-enabled/default" ]; then
    sudo rm /etc/nginx/sites-enabled/default
fi
sudo nginx -t && sudo systemctl restart nginx

# --- مرحله نهایی: نصب SSL (اختیاری) ---
echo
read -p "🔒 آیا مایل به فعال‌سازی HTTPS رایگان با Certbot هستید؟ (پیشنهاد می‌شود) (y/n): " ENABLE_SSL
if [[ "$ENABLE_SSL" == "y" || "$ENABLE_SSL" == "Y" ]]; then
    echo -e "${YELLOW}در حال نصب گواهی SSL برای $DOMAIN ...${NC}"
    sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m admin@$DOMAIN
    # --non-interactive: از پرسیدن سوالات اضافه جلوگیری می‌کند
    # -m: یک ایمیل برای اطلاع‌رسانی‌های Certbot (می‌توانید تغییر دهید)
fi

echo
echo -e "${GREEN}✅ نصب با موفقیت کامل شد!${NC}"
echo -e "--------------------------------------------------"
echo -e "🌐 آدرس وب‌سایت شما: ${CYAN}https://$DOMAIN${NC}"
echo -e "📂 مسیر فایل‌های پروژه: ${CYAN}$PROJECT_PATH${NC}"
echo -e "🔑 برای ورود به پنل مدیریت، به آدرس ${CYAN}https://$DOMAIN/admin${NC} بروید."
echo -e "--------------------------------------------------"
