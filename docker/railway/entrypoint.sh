#!/bin/sh
# Boots the API on a one-port-per-service platform (Railway, Render, Fly).
#
# The web, worker and reverb services all run this image. Only the web service
# sets RUN_MIGRATIONS, so a worker restart can never race the schema.
set -e

cd /app

# Railway injects $PORT at runtime and expects the process to bind it.
# FrankenPHP's default Caddyfile reads the site address from $SERVER_NAME.
export SERVER_NAME=":${PORT:-8080}"

# Railway's MySQL and Redis plugins publish their own variable names. Map them
# onto Laravel's when the Laravel-shaped ones were not set explicitly, so the
# service works whether you wire it up with references or plugin defaults.
[ -z "${DB_HOST:-}" ] && [ -n "${MYSQLHOST:-}" ] && export DB_HOST="$MYSQLHOST"
[ -z "${DB_PORT:-}" ] && [ -n "${MYSQLPORT:-}" ] && export DB_PORT="$MYSQLPORT"
[ -z "${DB_DATABASE:-}" ] && [ -n "${MYSQLDATABASE:-}" ] && export DB_DATABASE="$MYSQLDATABASE"
[ -z "${DB_USERNAME:-}" ] && [ -n "${MYSQLUSER:-}" ] && export DB_USERNAME="$MYSQLUSER"
[ -z "${DB_PASSWORD:-}" ] && [ -n "${MYSQLPASSWORD:-}" ] && export DB_PASSWORD="$MYSQLPASSWORD"

[ -z "${REDIS_HOST:-}" ] && [ -n "${REDISHOST:-}" ] && export REDIS_HOST="$REDISHOST"
[ -z "${REDIS_PORT:-}" ] && [ -n "${REDISPORT:-}" ] && export REDIS_PORT="$REDISPORT"
[ -z "${REDIS_PASSWORD:-}" ] && [ -n "${REDISPASSWORD:-}" ] && export REDIS_PASSWORD="$REDISPASSWORD"

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "→ Waiting for the database…"

  # A PDO connect, not a port check — the port opens before MySQL will talk.
  attempts=0
  until php -r "
    try {
      new PDO(
        'mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 3306).';dbname='.getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
      );
      exit(0);
    } catch (Throwable \$e) { exit(1); }
  "; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 60 ]; then
      echo "✗ Database unreachable after 2 minutes. Check DB_HOST/DB_PASSWORD." >&2
      exit 1
    fi
    sleep 2
  done

  echo "→ Running migrations…"
  php artisan migrate --force

  # Seeding is idempotent, but only worth doing when the menu is empty.
  if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    PRODUCTS=$(php artisan tinker --execute='echo \App\Models\Product::count();' 2>/dev/null | tail -1 | tr -dc '0-9')
    if [ "${PRODUCTS:-0}" = "0" ]; then
      echo "→ Seeding…"
      php artisan db:seed --force
    else
      echo "→ Menu already seeded (${PRODUCTS} products); skipping."
    fi
  fi

  php artisan storage:link || true
fi

# Cache config, routes and events into the container. Cheap, and makes every
# request skip filesystem discovery.
echo "→ Caching configuration…"
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
