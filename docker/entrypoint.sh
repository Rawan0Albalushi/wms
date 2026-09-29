#!/bin/sh
set -eu

cd /var/www/html

if [ -z "${PORT:-}" ]; then
    echo "PORT is required." >&2
    exit 1
fi

php artisan optimize:clear
php artisan migrate --force
php artisan storage:link --force

exec php artisan serve --host=0.0.0.0 --port="${PORT}"
