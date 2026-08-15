#!/usr/bin/env bash
set -euo pipefail
cd /var/www/app
sudo -u www-data git pull --ff-only
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan route:clear
sudo systemctl reload apache2
echo "deployed: $(git rev-parse --short HEAD)"
