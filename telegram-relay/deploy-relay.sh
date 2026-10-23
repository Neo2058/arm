#!/bin/bash
# Run from a reviewed checkout on the relay host. No embedded credentials or generated duplicate source.
set -euo pipefail
relay_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$relay_dir"
if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    echo "Fill .env with a NEW bot token and random RELAY_SECRET; then rerun."
    exit 1
fi
docker compose up -d --build
echo "Relay started on loopback. Configure an HTTPS reverse proxy before using it from ARM."
