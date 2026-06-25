#!/bin/sh
set -e

# Prepare Laravel storage directories (especially for Livewire temp uploads)
# Note: 'local' disk in filesystems.php points to storage/app/private
mkdir -p /var/www/storage/app/private/livewire-tmp
mkdir -p /var/www/storage/app/private
mkdir -p /var/www/storage/app/public
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/logs

# Fix ownership for www-data (fpm user)
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# Optional: clear config cache in prod (safe)
# php /var/www/artisan config:clear || true

echo "Storage dirs ready."

# Run the original php-fpm
exec php-fpm
