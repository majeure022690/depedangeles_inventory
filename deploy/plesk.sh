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
if [ ! -f composer.phar ]; then
    "$PHP" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    "$PHP" composer-setup.php --quiet
    rm -f composer-setup.php
fi
"$PHP" composer.phar install --no-dev --optimize-autoloader --no-interaction
"$PHP" artisan migrate --force
[ -e public/storage ] || "$PHP" artisan storage:link
"$PHP" artisan optimize

cd "$ROOT/frontend"
npm ci
npm run build

rm -rf "$ROOT/backend/public/assets"
cp -R dist/. "$ROOT/backend/public/"
# Without index.html, SPA paths fall through to Laravel's same-origin QR redirect and loop.
[ -f "$ROOT/backend/public/index.html" ] || { echo "SPA build missing index.html" >&2; exit 1; }

echo "Deploy finished."
