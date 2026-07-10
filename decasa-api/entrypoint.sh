#!/bin/bash
set -e

PORT=${PORT:-80}
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan storage:link --force 2>/dev/null || true
php artisan migrate --force 2>/dev/null || true
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

# Los comandos artisan corren como root y pueden crear archivos en storage con
# permisos de root. Re-chownear para que www-data (Apache) pueda escribir logs.
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true

php artisan queue:work --tries=3 --timeout=60 --sleep=3 2>/dev/null &

(while true; do php artisan schedule:run --no-interaction 2>/dev/null; sleep 60; done) &

php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction 2>&1 &

exec apache2-foreground
