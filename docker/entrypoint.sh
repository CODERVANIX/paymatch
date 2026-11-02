#!/bin/sh
set -eu

mkdir -p /data
touch /data/paymatch.sqlite
chown -R www-data:www-data /data /var/www/html/storage /var/www/html/bootstrap/cache
php artisan migrate --force

exec "$@"
