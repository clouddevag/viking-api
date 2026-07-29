#!/bin/sh
# Boots the API container.
#
# Migrations and seeders run only in the container that sets RUN_MIGRATIONS —
# the worker and Reverb containers share this image but must not touch schema.
set -e

cd /var/www/html

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "→ Waiting for the database…"
  until php -r "
    try {
      new PDO(
        'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
      );
      exit(0);
    } catch (Throwable \$e) { exit(1); }
  "; do
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

# Cache config, routes and events into the container. Cheap and makes every
# request skip filesystem discovery.
echo "→ Caching configuration…"
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
