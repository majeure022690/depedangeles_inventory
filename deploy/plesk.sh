#!/bin/sh
# Plesk "additional deployment actions": sh deploy/plesk.sh
# Document root must be <repo>/backend/public. Needs backend/.env and
# frontend/.env.production to exist on the server first.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

PHP="${PHP_BIN:-/opt/plesk/php/8.4/bin/php}"
[ -x "$PHP" ] || PHP=php

NODE_BIN="$(ls -d /opt/plesk/node/*/bin 2>/dev/null | sort -V | tail -n 1 || true)"
[ -n "$NODE_BIN" ] && PATH="$NODE_BIN:$PATH"

[ -f "$ROOT/backend/.env" ] || { echo "Missing backend/.env" >&2; exit 1; }
[ -f "$ROOT/frontend/.env.production" ] || { echo "Missing frontend/.env.production" >&2; exit 1; }

cd "$ROOT/backend"
if [ -f /usr/lib/plesk-9.0/composer.phar ]; then
    "$PHP" /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader --no-interaction
else
    "$PHP" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction
fi
"$PHP" artisan migrate --force
[ -e public/storage ] || "$PHP" artisan storage:link
"$PHP" artisan optimize

cd "$ROOT/frontend"
npm ci
npm run build

rm -rf "$ROOT/backend/public/assets"
cp -R dist/. "$ROOT/backend/public/"

echo "Deploy finished."
