#!/bin/bash

# ==============================================================================
# ===           Smart and Secure Update Script for the VPNMarket Project      ===
# ==============================================================================

set -e # Exit immediately if any command exits with a non-zero status

# --- Variables and colors ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m' # No Color

PROJECT_PATH="/var/www/FalcoMarket"
WEB_USER="www-data"
BUILD_LOG="$PROJECT_PATH/npm_build.log"

# --- Step 0: Pre-flight checks ---
echo -e "${CYAN}--- Starting the VPNMarket update process ---${NC}"

if [ "$PWD" != "$PROJECT_PATH" ]; then
  echo -e "${RED}Error: This script must be run from the project directory ('cd $PROJECT_PATH').${NC}"
  exit 1
fi

if [ ! -f ".env" ]; then
    echo -e "${RED}Error: .env file not found!${NC}"
    exit 1
fi

echo

# --- Step 1: Backup and maintenance mode ---
echo -e "${YELLOW}Step 1 of 7: Backing up .env and enabling maintenance mode...${NC}"
sudo cp .env .env.bak.$(date +%Y-%m-%d_%H-%M-%S)
echo "A backup of the .env file has been created."
sudo -u $WEB_USER php artisan down || true

# --- Step 2: Fetch latest code from GitHub ---
echo -e "${YELLOW}Step 2 of 7: Fetching the latest changes from GitHub...${NC}"
sudo git stash push --include-untracked || true
sudo git pull origin main

# --- Step 3: Fix file permissions ---
echo -e "${YELLOW}Step 3 of 7: Resetting file permissions...${NC}"
sudo chown -R $WEB_USER:$WEB_USER .

# --- Step 4: Update PHP dependencies (Composer) ---
echo -e "${YELLOW}Step 4 of 7: Updating PHP packages...${NC}"
sudo -u $WEB_USER composer install --no-dev --optimize-autoloader

# --- Step 5: Update Frontend (Node.js/NPM) with timeout and logs ---
echo -e "${YELLOW}Step 5 of 7: Updating Node.js packages and building assets...${NC}"
{
    echo "Starting npm install and build: $(date)"
    sudo -u $WEB_USER npm install --cache .npm --prefer-offline
    # Run build and log output
    sudo -u $WEB_USER npm run build
    echo "Finished npm build: $(date)"
} &> "$BUILD_LOG" &

BUILD_PID=$!
echo -e "${CYAN}Building frontend in the background (PID=$BUILD_PID)...${NC}"
echo -e "${CYAN}Build logs are being saved to $BUILD_LOG.${NC}"

# Optional wait up to 10 minutes, then continue while build proceeds in background
TIMEOUT=600
SECONDS=0
while kill -0 $BUILD_PID 2> /dev/null; do
    if [ $SECONDS -ge $TIMEOUT ]; then
        echo -e "${RED}⚠️ Build has been running for more than 10 minutes. Continuing the script while the build keeps running in the background.${NC}"
        break
    fi
    sleep 5
done

# --- Step 6: Update database ---
echo -e "${YELLOW}Step 6 of 7: Running database migrations...${NC}"
sudo -u $WEB_USER php artisan migrate --force

# --- Step 7: Clear caches and exit maintenance mode ---
echo -e "${YELLOW}Step 7 of 7: Clearing caches and bringing the site back up...${NC}"
sudo -u $WEB_USER php artisan optimize:clear
sudo -u $WEB_USER php artisan up

echo
echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN}✅ Project successfully updated to the latest version!${NC}"
echo -e "${GREEN}=====================================================${NC}"
echo -e "${CYAN}If the frontend build is still running, you can check its log at $BUILD_LOG.${NC}"
