#!/bin/sh
set -e
mkdir -p \
  /var/www/storage/app/private \
  /var/www/storage/app/private/chat-pending \
  /var/www/storage/app/public \
  /var/www/storage/framework/cache \
  /var/www/storage/framework/views \
  /var/www/storage/framework/sessions \
  /var/www/storage/logs \
  /var/www/bootstrap/cache
touch /var/www/storage/logs/laravel.log
exec "$@"
