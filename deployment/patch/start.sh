#!/bin/sh
set -eu
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php artisan package:discover --ansi
php artisan config:cache
exec "$@"
