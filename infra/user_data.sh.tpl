#!/usr/bin/env bash
# EC2 初期プロビジョニング。再実行しても壊れないように各ステップで存在チェックを行う。
# ALBのTGヘルスチェックは /up。ここが完了してApacheが起動するまで数分かかる（TGはそれまで unhealthy）。
set -euo pipefail
exec > >(tee -a /var/log/user-data.log) 2>&1

# user_data はrootでHOME未設定のまま実行されるため、Composerが
# "The HOME or COMPOSER_HOME environment variable must be set" で落ちるのを防ぐ。
export HOME=/root
export COMPOSER_HOME=/root/.composer

GIT_REPO_URL="${git_repo_url}"
GIT_BRANCH="${git_branch}"
APP_DIR=/var/www/app
PHP_VERSION=8.4

echo "=== [1/9] SSM agent ==="
if ! systemctl is-active --quiet snap.amazon-ssm-agent.amazon-ssm-agent.service 2>/dev/null; then
  if ! snap list amazon-ssm-agent >/dev/null 2>&1; then
    snap install amazon-ssm-agent --classic
  fi
  systemctl enable --now snap.amazon-ssm-agent.amazon-ssm-agent.service
fi

echo "=== [2/9] apt-get update & PPA ==="
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common
if ! grep -rq "^deb .*ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi

echo "=== [3/9] install packages ==="
apt-get install -y \
  apache2 \
  "php$PHP_VERSION" \
  "libapache2-mod-php$PHP_VERSION" \
  "php$PHP_VERSION-sqlite3" \
  "php$PHP_VERSION-mbstring" \
  "php$PHP_VERSION-xml" \
  "php$PHP_VERSION-curl" \
  "php$PHP_VERSION-bcmath" \
  "php$PHP_VERSION-intl" \
  "php$PHP_VERSION-zip" \
  git \
  unzip \
  curl

if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "=== [4/9] clone or pull application code ==="
if [ -d "$APP_DIR/.git" ]; then
  cd "$APP_DIR"
  git fetch origin "$GIT_BRANCH"
  git checkout "$GIT_BRANCH"
  git reset --hard "origin/$GIT_BRANCH"
else
  rm -rf "$APP_DIR"
  git clone --branch "$GIT_BRANCH" "$GIT_REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"

echo "=== [5/9] composer install ==="
composer install --no-dev --optimize-autoloader --no-interaction

echo "=== [6/9] .env setup ==="
if [ ! -f "$APP_DIR/.env" ]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi
sed -i "s#^DB_CONNECTION=.*#DB_CONNECTION=sqlite#" "$APP_DIR/.env"
if grep -q "^DB_DATABASE=" "$APP_DIR/.env"; then
  sed -i "s#^DB_DATABASE=.*#DB_DATABASE=$APP_DIR/database/database.sqlite#" "$APP_DIR/.env"
else
  echo "DB_DATABASE=$APP_DIR/database/database.sqlite" >> "$APP_DIR/.env"
fi
touch "$APP_DIR/database/database.sqlite"

if grep -q "^APP_KEY=$" "$APP_DIR/.env" || ! grep -q "^APP_KEY=" "$APP_DIR/.env"; then
  php artisan key:generate --force
fi

echo "=== [7/9] migrate ==="
php artisan migrate --force

# config:cache は使わない。.env の TRUST_PROXIES/TRUST_HOSTS トグルが効かなくなるため（docs/trust-proxy.md 参照）。
echo "=== [8/9] permissions ==="
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwx "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "=== [9/9] apache vhost ==="
a2enmod rewrite >/dev/null

cat > /etc/apache2/sites-available/trust-verify.conf <<EOF
<VirtualHost *:80>
    ServerName trust-verify.local
    DocumentRoot $APP_DIR/public

    <Directory $APP_DIR/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \$${APACHE_LOG_DIR}/trust-verify-error.log
    CustomLog \$${APACHE_LOG_DIR}/trust-verify-access.log combined
</VirtualHost>
EOF

a2dissite 000-default >/dev/null 2>&1 || true
a2ensite trust-verify >/dev/null

systemctl enable --now apache2
systemctl reload apache2

echo "=== provisioning complete ==="
