#!/bin/bash

# ==================================================================================
# === Final, smart, and error-safe installation script for VPNMarket on Ubuntu 22.04 ===
# === Author: Arvin Vahed                                                           ===
# === https://github.com/arvinvahed/VPNMarket                                       ===
# ==================================================================================

set -e # Exit immediately if any command exits with a non-zero status

# --- Variables and colors ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'
PROJECT_PATH="/var/www/FalcoMarket"
GITHUB_REPO="https://github.com/xshayank/VPNMarket.git"
PHP_VERSION="8.3"

echo -e "${CYAN}--- Welcome! Preparing to install the VPNMarket project ---${NC}"
echo

# --- Gather information from user ---
read -p "🌐 Please enter your domain (example: market.example.com): " DOMAIN
DOMAIN=$(echo $DOMAIN | sed 's|http[s]*://||g' | sed 's|/.*||g')

read -p "🗃 Choose a database name (example: vpnmarket): " DB_NAME
read -p "👤 Choose a database username (example: vpnuser): " DB_USER
while true; do
    read -s -p "🔑 Enter a strong password for the database user: " DB_PASS
    echo
    if [ -z "$DB_PASS" ]; then
        echo -e "${RED}Password cannot be empty. Please try again.${NC}"
    else
        break
    fi
done

read -p "✉️ Your email for SSL certificate and Certbot notifications: " ADMIN_EMAIL
echo
echo

# --- Step 1: Install all system prerequisites ---
echo -e "${YELLOW}📦 Step 1 of 10: Updating the system and installing all prerequisites...${NC}"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y git curl composer unzip software-properties-common gpg nodejs nginx certbot python3-certbot-nginx mysql-server redis-server supervisor ufw

# --- Step 2: Install Node.js (only if a newer LTS is needed) ---
echo -e "${YELLOW}📦 Step 2 of 10: Checking and installing a newer Node.js LTS if needed...${NC}"
if ! command -v node > /dev/null || [[ $(node -v | cut -d. -f1 | sed 's/v//') -lt 18 ]]; then
    echo "Installing/upgrading Node.js to the LTS version..."
    curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
    sudo apt-get install -y nodejs
fi
echo -e "${GREEN}Node.js $(node -v) and npm $(npm -v) installed successfully.${NC}"

# --- Step 3: Install PHP 8.3 ---
echo -e "${YELLOW}☕ Step 3 of 10: Adding PHP repository and installing PHP ${PHP_VERSION}...${NC}"
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y
sudo apt-get install -y \
  php${PHP_VERSION}-fpm \
  php${PHP_VERSION}-mysql \
  php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-xml \
  php${PHP_VERSION}-curl \
  php${PHP_VERSION}-zip \
  php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-intl \
  php${PHP_VERSION}-readline \
  php${PHP_VERSION}-redis

# --- Step 4: Set the default PHP version ---
echo -e "${YELLOW}🔧 Step 4 of 10: Setting default PHP to ${PHP_VERSION}...${NC}"
sudo update-alternatives --set php /usr/bin/php${PHP_VERSION}

# --- Step 5: Enable core services ---
echo -e "${YELLOW}🚀 Step 5 of 10: Enabling core services...${NC}"
sudo systemctl enable --now php${PHP_VERSION}-fpm nginx mysql redis-server supervisor

# --- Step 6: Configure the firewall ---
echo -e "${YELLOW}🛡️ Step 6 of 10: Configuring the server firewall...${NC}"
sudo ufw allow 'OpenSSH'
sudo ufw allow 'Nginx Full'
echo "y" | sudo ufw enable
sudo ufw status
echo -e "${GREEN}Firewall enabled and configured successfully.${NC}"

# --- Step 7: Download the project and initial setup ---
echo -e "${YELLOW}⬇️ Step 7 of 10: Cloning and setting up the project...${NC}"
if [ -d "$PROJECT_PATH" ]; then
    sudo rm -rf "$PROJECT_PATH"
fi
sudo git clone $GITHUB_REPO $PROJECT_PATH
cd $PROJECT_PATH
sudo chown -R www-data:www-data $PROJECT_PATH

# --- Step 8: Configure the database and .env ---
echo -e "${YELLOW}🧩 Step 8 of 10: Creating the database and configuring .env...${NC}"
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

sudo -u www-data cp .env.example .env
sudo sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" .env
sudo sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|" .env
sudo sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env
sudo sed -i "s|APP_URL=.*|APP_URL=https://$DOMAIN|" .env
sudo sed -i "s|APP_ENV=.*|APP_ENV=production|" .env
sudo sed -i "s|QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" .env

# --- Step 9: Install dependencies and run final Artisan commands ---
echo -e "${YELLOW}🧰 Step 9 of 10: Installing dependencies and running final Artisan commands...${NC}"
echo "Installing PHP packages with Composer..."
sudo -u www-data composer install --no-dev --optimize-autoloader

echo "Installing Node.js packages with npm..."
# --->>> Back to a safe and direct npm method <<<---
sudo -u www-data npm install --cache $PROJECT_PATH/.npm --prefer-offline

echo "Compiling CSS/JS assets for production..."
sudo -u www-data npm run build

# Clean npm cache after completion
sudo rm -rf $PROJECT_PATH/.npm

echo "Running final Artisan commands..."
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan migrate --seed --force
sudo -u www-data php artisan storage:link

# --- Step 10: Configure Nginx, Supervisor, and final optimizations ---
echo -e "${YELLOW}🌍 Step 10 of 10: Final service configuration and optimizations...${NC}"
PHP_FPM_SOCK_PATH=$(grep -oP 'listen\s*=\s*\K.*' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf | head -n 1 | sed 's/;//g' | xargs)

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
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    error_page 404 /index.php;
    location ~ \.php$ {
        fastcgi_pass unix:$PHP_FPM_SOCK_PATH;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
EOF

sudo ln -sf /etc/nginx/sites-available/vpnmarket /etc/nginx/sites-enabled/
if [ -f "/etc/nginx/sites-enabled/default" ]; then
    sudo rm /etc/nginx/sites-enabled/default
fi
sudo nginx -t && sudo systemctl restart nginx

sudo tee /etc/supervisor/conf.d/vpnmarket-worker.conf >/dev/null <<EOF
[program:vpnmarket-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_PATH/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/vpnmarket-worker.log
stopwaitsecs=3600
EOF

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start vpnmarket-worker:*

echo -e "${YELLOW}🚀 Performing final application optimizations...${NC}"
# Run cache commands after the server is fully configured
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# --- SSL installation (optional) ---
echo
read -p "🔒 Would you like to enable free HTTPS with Certbot? (recommended) (y/n): " ENABLE_SSL
if [[ "$ENABLE_SSL" == "y" || "$ENABLE_SSL" == "Y" ]]; then
    echo -e "${YELLOW}Installing SSL certificate for $DOMAIN ...${NC}"
    sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m $ADMIN_EMAIL
fi

# --- Final message ---
echo
echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN}✅ Installation completed successfully!${NC}"
echo -e "--------------------------------------------------"
echo -e "🌐 Your website: ${CYAN}https://$DOMAIN${NC}"
echo -e "🔑 Admin panel: ${CYAN}https://$DOMAIN/admin${NC}"
echo
echo -e "   - Login email: ${YELLOW}admin@example.com${NC}"
echo -e "   - Password: ${YELLOW}password${NC}"
echo
echo -e "${RED}⚠️ Immediate action: Please change the admin user's password right after the first login!${NC}"
echo -e "${GREEN}=====================================================${NC}"
