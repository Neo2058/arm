#!/bin/sh
# Host-side PHP2Shell mitigation for FirstVDS/ISPmanager PHP 8.3 (not the ARM container).
# Run as root: sudo sh scripts/php2shell-host-mitigation.sh
# Does not replace apt upgrade to 8.3.36. Does not close ISPmanager :1500.
set -eu

if [ "$(id -u)" -ne 0 ]; then
  echo "run as root" >&2
  exit 1
fi

ini=/etc/php/8.3/fpm/conf.d/zz-php2shell.ini
if [ ! -d /etc/php/8.3/fpm/conf.d ]; then
  echo "php 8.3 fpm conf.d not found" >&2
  exit 1
fi

cat >"$ini" <<'EOF'
; PHP2Shell CVE-2026-109618 / CVE-2026-109619
session.upload_progress.enabled = Off
session.use_strict_mode = 1
session.use_only_cookies = 1
expose_php = Off
EOF

if [ -d /etc/php/8.3/cli/conf.d ]; then
  cp "$ini" /etc/php/8.3/cli/conf.d/zz-php2shell.ini
fi

systemctl reload php8.3-fpm
php -r 'echo "upload_progress=".var_export((bool)ini_get("session.upload_progress.enabled"), true).PHP_EOL;'
echo "host fpm reloaded. still upgrade php to 8.3.36+ and restrict :1500"
