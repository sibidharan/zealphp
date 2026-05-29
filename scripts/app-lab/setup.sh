#!/usr/bin/env bash
# Provisions a reproducible compatibility/perf lab:
#   - sweep-mysql container, shared, with per-app DBs
#   - apache-wp reference container running WordPress via mod_php
#   - sweep-mode1 / sweep-mode5 (or zealphp-wordpress) for ZealPHP serving
#
# Idempotent: re-running cleans + reprovisions.

set -euo pipefail
cd "$(dirname "$0")"

MYSQL_CONTAINER=${MYSQL_CONTAINER:-sweep-mysql}
MYSQL_ROOT_PW=${MYSQL_ROOT_PW:-root}
MYSQL_USER=${MYSQL_USER:-testuser}
MYSQL_PASSWORD=${MYSQL_PASSWORD:-testpass}
NET=${NET:-applab}

APPS=(
    wordpress drupal joomla mediawiki bookstack monica flarum
    vanilla mybb phpbb piwigo opencart matomo cacti lychee roundcube
)

echo "==> Network"
docker network create "$NET" 2>/dev/null || true

echo "==> MySQL"
if ! docker ps -q -f "name=^${MYSQL_CONTAINER}$" >/dev/null; then
    docker rm -f "$MYSQL_CONTAINER" 2>/dev/null || true
    docker run -d --name "$MYSQL_CONTAINER" --network "$NET" \
        -e MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PW" \
        -p 3307:3306 \
        mysql:8.0 --default-authentication-plugin=mysql_native_password
    echo "Waiting for MySQL ready..."
    until docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot -p"$MYSQL_ROOT_PW" --silent 2>/dev/null; do
        sleep 1
    done
fi

echo "==> Per-app databases + universal test user"
mysql_cmds="
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;"
for app in "${APPS[@]}"; do
    mysql_cmds+=$'\n'"CREATE DATABASE IF NOT EXISTS \`${app}\`;"
done
docker exec -i "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PW" <<<"$mysql_cmds"

echo "==> Apache reference container (apache-wp on port 8094)"
if ! docker ps -q -f "name=^apache-wp$" >/dev/null; then
    docker rm -f apache-wp 2>/dev/null || true
    docker run -d --name apache-wp --network "$NET" \
        -p 8094:80 \
        php:8.3-apache
    docker exec apache-wp sh -c "docker-php-ext-install pdo_mysql mysqli >/dev/null"
    docker restart apache-wp >/dev/null
fi

echo "==> Done. Endpoints:"
echo "    MySQL host       : 127.0.0.1:3307 (testuser/testpass)"
echo "    Apache reference : http://localhost:8094/"
echo "    ZealPHP sweep    : http://localhost:9101..9105/<app>/"
echo "    ZealPHP wp lab   : http://localhost:8093/  (if zealphp-wordpress running)"
