#!/bin/bash

# ==================================================================================
# === اسکریپت نصب نهایی، هوشمند و ضد خطا برای پروژه VPNMarket روی Ubuntu 22.04    ===
# === نویسنده: Arvin Vahed                                                       ===
# === https://github.com/arvinvahed/VPNMarket                                    ===
# ==================================================================================

set -e


GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'
PROJECT_PATH="/var/www/vpnmarket"
GITHUB_REPO="https://github.com/arvinvahed/VPNMarket.git"
PHP_VERSION="8.3"

echo -e "${CYAN}--- خوش آمدید! در حال آماده‌سازی برای نصب پروژه VPNMarket ---${NC}"
echo

# --- دریافت اطلاعات از کاربر ---
read -p "🌐 لطفا دامنه خود را وارد کنید (مثال: market.example.com): " DOMAIN
DOMAIN=$(echo $DOMAIN | sed 's|http[s]*://||g' | sed 's|/.*||g')

read -p "🗃 یک نام برای دیتابیس انتخاب کنید (مثال: vpnmarket): " DB_NAME
read -p "👤 یک نام کاربری برای دیتابیس انتخاب کنید (مثال: vpnuser): " DB_USER
while true; do
    read -s -p "🔑 یک رمز عبور قوی برای کاربر دیتابیس وارد کنید: " DB_PASS
    echo
    if [ -z "$DB_PASS" ]; then
        echo -e "${RED}رمز عبور نمی‌تواند خالی باشد. لطفا دوباره وارد کنید.${NC}"
    else
        break
    fi
done

read -p "✉️ ایمیل شما برای گواهی SSL و اخطارهای Certbot: " ADMIN_EMAIL
echo
echo

# --- مرحله ۱: نصب پیش‌نیازهای اولیه ---
echo -e "${YELLOW}📦 مرحله ۱ از ۹: به‌روزرسانی سیستم و نصب پیش‌نیازهای اولیه...${NC}"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y git curl composer unzip software-properties-common gpg

# --- مرحله ۲: نصب Node.js نسخه LTS ---
echo -e "${YELLOW}📦 مرحله ۲ از ۹: نصب نسخه جدید Node.js...${NC}"
curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
sudo apt-get install -y nodejs
echo -e "${GREEN}Node.js $(node -v) و npm $(npm -v) با موفقیت نصب شدند.${NC}"

# --- مرحله ۳: نصب PHP 8.3 ---
echo -e "${YELLOW}☕ مرحله ۳ از ۹: افزودن مخزن PHP و نصب PHP ${PHP_VERSION}...${NC}"
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y
sudo apt-get install -y php${PHP_VERSION}-fpm php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath php${PHP_VERSION}-intl php${PHP_VERSION}-gd php${PHP_VERSION}-dom

# --- مرحله ۴: تنظیم نسخه پیش‌فرض PHP ---
echo -e "${YELLOW}🔧 مرحله ۴ از ۹: تنظیم نسخه پیش‌فرض PHP به ${PHP_VERSION}...${NC}"
sudo update-alternatives --set php /usr/bin/php${PHP_VERSION}

# --- مرحله ۵: نصب و فعال‌سازی سرویس‌های اصلی ---
echo -e "${YELLOW}🚀 مرحله ۵ از ۹: نصب و فعال‌سازی سرویس‌های Nginx و MySQL...${NC}"
sudo apt-get install -y nginx certbot python3-certbot-nginx mysql-server
sudo systemctl enable php${PHP_VERSION}-fpm
sudo systemctl start php${PHP_VERSION}-fpm
sudo systemctl enable nginx
sudo systemctl start nginx
sudo systemctl enable mysql
sudo systemctl start mysql

# --- مرحله ۶: دانلود پروژه ---
echo -e "${YELLOW}⬇️ مرحله ۶ از ۹: دانلود سورس پروژه از گیت‌הاب...${NC}"
if [ -d "$PROJECT_PATH" ]; then
    sudo rm -rf "$PROJECT_PATH"
fi
sudo git clone $GITHUB_REPO $PROJECT_PATH
cd $PROJECT_PATH

# --- مرحله ۷: تنظیم دیتابیس و .env ---
echo -e "${YELLOW}🧩 مرحله ۷ از ۹: ساخت دیتابیس و تنظیم فایل .env...${NC}"
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

# --- مرحله ۸: نصب وابستگی‌های Backend و Frontend ---
echo -e "${YELLOW}🧰 مرحله ۸ از ۹: تنظیم دسترسی‌ها و نصب وابستگی‌های پروژه...${NC}"
sudo chown -R www-data:www-data $PROJECT_PATH

echo "نصب پکیج‌های PHP با Composer..."
sudo -u www-data composer install --no-dev --optimize-autoloader

echo "نصب پکیج‌های Node.js با npm..."
sudo -u www-data npm install --cache .npm --prefer-offline

echo "کامپایل کردن فایل‌های CSS/JS برای تولید..."
sudo -u www-data npm run build

sudo rm -rf .npm

sudo -u www-data php artisan key:generate
sudo -u www-data php artisan package:discover --ansi
sudo -u www-data php artisan filament:upgrade
sudo -u www-data php artisan migrate --seed --force
sudo -u www-data php artisan storage:link



# --- مرحله ۹: پیکربندی نهایی Nginx ---
echo -e "${YELLOW}🌍 مرحله ۹ از ۹: پیکربندی نهایی وب‌سرور (Nginx) و بهینه‌سازی نهایی...${NC}"
PHP_FPM_SOCK_PATH=$(grep -oP 'listen\s*=\s*\K.*' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf | head -n 1 | sed 's/;//g' | xargs)
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

# --- نصب SSL (اختیاری) ---
echo
read -p "🔒 آیا مایل به فعال‌سازی HTTPS رایگان با Certbot هستید؟ (پیشنهاد می‌شود) (y/n): " ENABLE_SSL
if [[ "$ENABLE_SSL" == "y" || "$ENABLE_SSL" == "Y" ]]; then
    echo -e "${YELLOW}در حال نصب گواهی SSL برای $DOMAIN ...${NC}"
    sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m $ADMIN_EMAIL
fi

# --- بهینه‌سازی نهایی بعد از راه‌اندازی کامل سرور ---
echo -e "${YELLOW}🚀 در حال بهینه‌سازی نهایی برنامه برای حداکثر سرعت...${NC}"
sudo -u www-data php artisan optimize

# --- پیام نهایی ---
echo
echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN}✅ نصب با موفقیت کامل شد!${NC}"
echo -e "--------------------------------------------------"
echo -e "🌐 آدرس وب‌سایت شما: ${CYAN}https://$DOMAIN${NC}"
echo -e "🔑 پنل مدیریت: ${CYAN}https://$DOMAIN/admin${NC}"
echo
echo -e "   - ایمیل ورود: ${YELLOW}admin@example.com${NC}"
echo -e "   - رمز عبور: ${YELLOW}password${NC}"
echo
echo -e "${RED}⚠️ اقدام فوری: لطفاً بلافاصله پس از اولین ورود، رمز عبور کاربر ادمین را تغییر دهید!${NC}"
echo -e "${GREEN}=====================================================${NC}"
