#!/bin/bash
set -e

PORT=${PORT:-80}
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan storage:link --force 2>/dev/null || true
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true
php artisan migrate --force 2>/dev/null || true
php artisan db:seed --force 2>/dev/null || true

REVERB_PORT=${REVERB_PORT:-8080}
php artisan reverb:start --host=0.0.0.0 --port=${REVERB_PORT} --no-interaction 2>/dev/null &

php artisan queue:work --tries=3 --timeout=60 --sleep=3 2>/dev/null &

(while true; do php artisan schedule:run --no-interaction 2>/dev/null; sleep 60; done) &

exec apache2-foreground
