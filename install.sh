#!/bin/bash

# --- نصب خودکار پروژه Laravel + Filament (VPNMarket) روی Ubuntu 22.04 ---
# نویسنده: Arvin Vahed
# https://github.com/arvinvahed

set -e


GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}--- شروع نصب پروژه VPNMarket ---${NC}"


read -p "🌐 دامنه (مثال: vpn.example.com): " DOMAIN
read -p "🗃 نام دیتابیس (مثال: vpnmarket): " DB_NAME
read -p "👤 نام کاربر دیتابیس (مثال: vpnuser): " DB_USER
read -s -p "🔑 رمز عبور دیتابیس: " DB_PASS
echo


PROJECT_PATH="/var/www/vpnmarket"
GITHUB_REPO="https://github.com/arvinvahed/VPNMarket.git"

echo -e "${YELLOW}📦 در حال نصب پیش‌نیازها...${NC}"
sudo apt-get update -y
sudo apt-get install -y git curl nginx certbot python3-certbot-nginx
sudo apt-get install -y php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath
sudo apt-get install -y mysql-server composer unzip

sudo systemctl enable mysql
sudo systemctl start mysql


echo -e "${YELLOW}⬇️ کلون پروژه از گیت‌هاب...${NC}"
sudo git clone $GITHUB_REPO $PROJECT_PATH
cd $PROJECT_PATH


echo -e "${YELLOW}⚙️ تنظیم لاراول...${NC}"
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate


echo -e "${YELLOW}🧩 تنظیم دیتابیس MySQL...${NC}"
sudo mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sudo mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"


sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env
sed -i "s|APP_URL=.*|APP_URL=http://$DOMAIN|" .env
sed -i "s/APP_ENV=.*/APP_ENV=production/" .env


echo -e "${YELLOW}🔗 اجرای مایگریشن و لینک Storage...${NC}"
php artisan migrate --seed --force
php artisan storage:link

# سطح دسترسی‌ها
echo -e "${YELLOW}🧰 تنظیم دسترسی فایل‌ها...${NC}"
sudo chown -R www-data:www-data $PROJECT_PATH
sudo chmod -R 775 $PROJECT_PATH/storage
sudo chmod -R 775 $PROJECT_PATH/bootstrap/cache

# پیکربندی Nginx
echo -e "${YELLOW}🌍 پیکربندی Nginx...${NC}"
sudo tee /etc/nginx/sites-available/vpnmarket >/dev/null <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $PROJECT_PATH/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

sudo ln -sf /etc/nginx/sites-available/vpnmarket /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl restart nginx

# SSL اختیاری
echo
read -p "🔒 آیا مایل به فعال‌سازی HTTPS با Certbot هستید؟ (y/n): " ENABLE_SSL
if [[ "$ENABLE_SSL" == "y" || "$ENABLE_SSL" == "Y" ]]; then
    sudo certbot --nginx -d $DOMAIN
fi

echo -e "${GREEN}✅ نصب کامل شد!${NC}"
echo -e "🌐 آدرس سایت: http://$DOMAIN"
echo -e "📁 مسیر پروژه: $PROJECT_PATH"
echo -e "⚙️ برای ورود به پنل Filament از مسیر /admin استفاده کنید."
