#!/bin/sh
set -e

# Wait for the database to accept connections (max ~60s)
i=0
until php -r 'require "/var/www/html/config/database.php"; db();' >/dev/null 2>&1; do
  i=$((i+1))
  if [ "$i" -ge 30 ]; then
    echo "[entrypoint] Database not reachable, skipping first-run setup."
    i=999
    break
  fi
  sleep 2
done

# Create schema + demo accounts on a brand-new database only.
if [ "$i" -lt 999 ]; then
  php /var/www/html/scripts/bootstrap.php || echo "[entrypoint] First-run setup failed (continuing)."
fi

exec docker-php-entrypoint "$@"
