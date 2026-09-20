#!/usr/bin/env bash
# Копирует собранные APK/IPA в public/downloads, откуда их отдаёт страница /install.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEST="$ROOT/public/downloads"
mkdir -p "$DEST"

copied=0
for src in "$@"; do
  if [[ ! -f "$src" ]]; then
    echo "нет файла: $src" >&2
    exit 1
  fi
  case "$src" in
    *.apk)
      cp "$src" "$DEST/tch15-android.apk"
      echo "Android → $DEST/tch15-android.apk"
      copied=1
      ;;
    *.ipa)
      cp "$src" "$DEST/tch15-ios.ipa"
      echo "iOS → $DEST/tch15-ios.ipa"
      copied=1
      ;;
    *)
      echo "нужен .apk или .ipa: $src" >&2
      exit 1
      ;;
  esac
done

if [[ "$copied" -eq 0 ]]; then
  echo "usage: $0 path/to/app.apk [path/to/app.ipa]" >&2
  exit 1
fi
