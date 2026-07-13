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

# Also ensure bootstrap/cache exists (needed for config/route/view cache)
mkdir -p /var/www/bootstrap/cache

# Ensure the main log file exists and is writable (prevents "Permission denied" when Laravel tries to log errors)
touch /var/www/storage/logs/laravel.log

# Fix ownership and permissions for www-data (fpm user) — only for directories that PHP needs to WRITE to at runtime.
# We set 775 so the host "deploy" user can also run artisan commands directly if needed
# (the uid/gid of www-data inside container may differ from host user).
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod 664 /var/www/storage/logs/laravel.log 2>/dev/null || true

# Make sure the log file is writable by group (helps when running artisan on host as deploy)
touch /var/www/storage/logs/laravel.log
chmod 664 /var/www/storage/logs/laravel.log 2>/dev/null || true

# Publish Filament and Livewire assets (needed for admin panel JS/CSS)
echo "Publishing Filament and Livewire assets..."
php artisan vendor:publish --tag=filament-assets --force --quiet || true
php artisan vendor:publish --tag=livewire:assets --force --quiet || true
echo "Assets published."

# Do NOT recursively chown /var/www/public.
# This directory is bind-mounted from the host. chown here changes host file ownership,
# which breaks `npm run build` (Vite needs to rmdir public/build/assets).
#
# Only chown runtime-writable dirs (storage, bootstrap/cache).
# Build output (public/build) should stay owned by the user who runs `npm run build` (usually deploy on host).
# As long as files have 755/644 permissions, nginx + php-fpm can read them.
#
# If you ever need to fix published assets permissions, do it selectively:
# chown -R www-data:www-data /var/www/public/vendor 2>/dev/null || true
# find /var/www/public -type d -exec chmod 755 {} + 2>/dev/null || true
# find /var/www/public -type f -exec chmod 644 {} + 2>/dev/null || true

# Clear caches so config, routes, and Livewire settings pick up .env / compose changes (safe on start)
php artisan config:clear --quiet || true
php artisan route:clear --quiet || true
php artisan view:clear --quiet || true

echo "Storage dirs ready."

# Ensure storage/app/public exists (required for storage:link)
mkdir -p /var/www/storage/app/public

# Force correct symlink (robust against broken links from host runs)
rm -f /var/www/public/storage
php artisan storage:link --quiet || echo "storage:link note"


# Ensure S3/MinIO bucket exists (idempotent, safe for volume resets)
# Run synchronously because uploads and form loads depend on S3 being ready
echo "Ensuring S3 bucket..."
php -r '
require "/var/www/vendor/autoload.php";
$app = require_once "/var/www/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$bucket = config("filesystems.disks.s3.bucket") ?: env("AWS_BUCKET");
if ($bucket) {
    try {
        $client = new Aws\S3\S3Client([
            "version" => "latest",
            "region" => config("filesystems.disks.s3.region") ?: env("AWS_DEFAULT_REGION", "us-east-1"),
            "endpoint" => config("filesystems.disks.s3.endpoint") ?: env("AWS_ENDPOINT"),
            "use_path_style_endpoint" => (bool)(config("filesystems.disks.s3.use_path_style_endpoint") ?? env("AWS_USE_PATH_STYLE_ENDPOINT", true)),
            "credentials" => [
                "key" => config("filesystems.disks.s3.key") ?: env("AWS_ACCESS_KEY_ID"),
                "secret" => config("filesystems.disks.s3.secret") ?: env("AWS_SECRET_ACCESS_KEY"),
            ],
        ]);
        $client->createBucket(["Bucket" => $bucket]);
        echo "Bucket ensured: $bucket\n";
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, "BucketAlreadyOwnedByYou") !== false || strpos($msg, "BucketAlreadyExists") !== false) {
            echo "Bucket exists: $bucket\n";
        } else {
            echo "Bucket ensure note: " . $msg . "\n";
        }
    }
}
' 2>&1 || echo "Bucket ensure script note (non-fatal)"

# Final permission sweep for logs (in case volume/bind mount semantics reset some bits)
touch /var/www/storage/logs/laravel.log
chmod 664 /var/www/storage/logs/laravel.log 2>/dev/null || true

# Run the original php-fpm
exec php-fpm
