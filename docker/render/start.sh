#!/bin/sh
set -e
cd /var/www/html
if [ -z "$APP_KEY" ]; then export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"; fi
# Render injects PORT; nginx.conf listens on 8080 (Render's default is 10000, so set PORT=8080 in the service).
# config:cache is skipped: config/scramble.php holds objects and cannot be serialized.
php artisan migrate --force
# Reference data (roles, specialties) is idempotent; demo accounts are seeded only into an empty database.
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
php artisan db:seed --class=Database\\Seeders\\SpecialtySeeder --force
if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
  USERS=$(php artisan tinker --execute='echo \Illuminate\Support\Facades\DB::table("users")->count();' | tail -1)
  if [ "$USERS" = "0" ]; then php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder --force; fi
fi
exec /usr/bin/supervisord -c /etc/supervisord.conf
