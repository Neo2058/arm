#!/usr/bin/env bash
# Проверяет установщики до атомарной публикации.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec python3 "$SCRIPT_DIR/publish_installers.py" "$@"
