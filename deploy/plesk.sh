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

# Keeps the site up during this deploy's build: the new routing serves only the storage copy.
SPA_INDEX="$ROOT/backend/storage/app/spa/index.html"
if [ ! -f "$SPA_INDEX" ] && [ -f "$ROOT/backend/public/index.html" ]; then
    mkdir -p "$(dirname "$SPA_INDEX")"
    cp "$ROOT/backend/public/index.html" "$SPA_INDEX"
fi

cd "$ROOT/backend"
if [ ! -f composer.phar ]; then
    "$PHP" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    "$PHP" composer-setup.php --quiet
    rm -f composer-setup.php
fi
"$PHP" composer.phar install --no-dev --optimize-autoloader --no-interaction
"$PHP" artisan migrate --force
[ -e public/storage ] || "$PHP" artisan storage:link

# Clear all caches before optimize
"$PHP" artisan cache:clear
"$PHP" artisan config:clear
"$PHP" artisan route:clear
"$PHP" artisan view:clear

"$PHP" artisan optimize

cd "$ROOT/frontend"
npm ci
npm run build

# Without index.html, SPA paths fall through to Laravel's same-origin QR redirect and loop.
[ -f dist/index.html ] || { echo "SPA build missing index.html" >&2; exit 1; }

# index.html never goes into public/: only Laravel serves it, with a per-response CSP nonce.
rm -rf "$ROOT/backend/public/assets"
rm -f "$ROOT/backend/public/index.html"
for entry in dist/* dist/.[!.]*; do
    [ -e "$entry" ] || continue
    [ "$entry" = dist/index.html ] && continue
    cp -R "$entry" "$ROOT/backend/public/"
done
mkdir -p "$ROOT/backend/storage/app/spa"
cp dist/index.html "$ROOT/backend/storage/app/spa/index.html.tmp"
mv -f "$ROOT/backend/storage/app/spa/index.html.tmp" "$ROOT/backend/storage/app/spa/index.html"
[ -f "$ROOT/backend/storage/app/spa/index.html" ] || { echo "SPA index.html not installed" >&2; exit 1; }
[ ! -e "$ROOT/backend/public/index.html" ] || { echo "backend/public/index.html must not exist" >&2; exit 1; }

echo "Deploy finished."
