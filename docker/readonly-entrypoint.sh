#!/bin/sh
# Hardened runtime only: assets/symlinks/buckets are prepared before deployment.
set -eu
umask 027

test -r /var/www/public/build/manifest.json
test -L /var/www/public/storage
test "$(readlink /var/www/public/storage)" = /var/www/storage/app/public
test "${ARM_BUCKET_PROVISIONED:-false}" = true

for path in \
    /var/www/storage \
    /var/www/storage/app \
    /var/www/storage/app/private \
    /var/www/storage/app/private/livewire-tmp \
    /var/www/storage/app/public \
    /var/www/storage/framework \
    /var/www/storage/framework/cache \
    /var/www/storage/framework/cache/data \
    /var/www/storage/framework/views \
    /var/www/storage/framework/sessions \
    /var/www/storage/logs; do
    mkdir -p "$path"
    chown www-data:www-data "$path"
done
# Only this public subdirectory is mounted into Nginx.
chmod 0755 /var/www/storage/app/public

# Cache is a fresh tmpfs, never the host checkout. Do not clear shared views.
mkdir -p /var/www/bootstrap/cache
php artisan config:cache --quiet
# FPM workers can read but cannot replace executable bootstrap cache files.
chown -R root:root /var/www/bootstrap/cache
find /var/www/bootstrap/cache -type d -exec chmod 0555 {} +
find /var/www/bootstrap/cache -type f -exec chmod 0444 {} +

exec php-fpm
