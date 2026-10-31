#!/bin/sh
set -eu

cd /var/www/html

mkdir -p /run/nginx \
    storage/app \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

if [ "${RUN_MIGRATIONS:-true}" != "false" ]; then
    php artisan migrate --force
fi

php-fpm -D
exec nginx -g 'daemon off;'
