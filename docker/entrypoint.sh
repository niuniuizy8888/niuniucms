#!/bin/sh
set -eu
APP="${NN_APP_ROOT:-/var/www/nncms}"
cd "$APP"

for d in data runtime runtime/cache runtime/log runtime/tmp upload; do
  mkdir -p "$d"
done
if [ ! -f data/.htaccess ]; then
  printf '%s\n' 'Require all denied' > data/.htaccess 2>/dev/null \
    || printf '%s\n' 'Deny from all' > data/.htaccess
fi

if [ ! -f data/install.lock ] && [ -n "${NN_DB_HOST:-}" ]; then
  echo "[nncms] waiting for database ${NN_DB_HOST}:${NN_DB_PORT:-3306} ..."
  i=0
  while [ "$i" -lt 60 ]; do
    if php -r "try { new PDO('mysql:host='.getenv('NN_DB_HOST').';port='.(getenv('NN_DB_PORT')?:3306), getenv('NN_DB_USER'), getenv('NN_DB_PASSWORD')); exit(0);} catch (Throwable \$e) { exit(1);}" 2>/dev/null; then
      break
    fi
    i=$((i+1))
    sleep 2
  done
fi

chown -R www-data:www-data data runtime upload 2>/dev/null || true

php-fpm -D
exec nginx -g 'daemon off;'