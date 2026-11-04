#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force --no-interaction
fi

php artisan migrate --force --no-interaction
php artisan app:seed-if-empty --no-interaction

exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
