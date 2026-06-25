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

# Publish Filament and Livewire assets (needed for admin panel JS/CSS)
echo "Publishing Filament and Livewire assets..."
php artisan vendor:publish --tag=filament-assets --force --quiet || true
php artisan vendor:publish --tag=livewire:assets --force --quiet || true
echo "Assets published."

# Fix ownership again for public (assets)
chown -R www-data:www-data /var/www/public 2>/dev/null || true

# Optional: clear config cache in prod (safe)
# php /var/www/artisan config:clear || true

echo "Storage dirs ready."

# Ensure S3/MinIO bucket exists (idempotent, safe for volume resets)
# Run in background so php-fpm starts immediately; don't block startup
echo "Ensuring S3 bucket in background..."
(
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
') &

# Run the original php-fpm
exec php-fpm
