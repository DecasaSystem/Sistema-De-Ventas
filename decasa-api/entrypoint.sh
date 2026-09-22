#!/bin/bash
set -e

PORT=${PORT:-80}
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan storage:link --force 2>/dev/null || true
php artisan migrate --force || true
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

# Los comandos artisan corren como root y pueden crear archivos en storage con
# permisos de root. Re-chownear para que www-data (Apache) pueda escribir logs.
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true

# Los tres procesos de fondo se relanzan si se caen. Antes se lanzaban una
# vez y, si alguno moría, se quedaba muerto hasta el siguiente despliegue —sin
# que nadie se enterara—:
#
#   queue:work  -> los traslados programados no se ejecutan a su hora y los
#                  correos y avisos encolados no salen nunca.
#   reverb      -> se cae el tiempo real: el chat de las órdenes, los avisos
#                  de despacho y las pantallas que se refrescan solas.
#
# El `|| true` de cada vuelta es para que `set -e` no mate el contenedor
# entero cuando uno de ellos termina con error.
relanzar() {
    local nombre="$1"; shift
    (
        while true; do
            "$@" || true
            echo "[decasa] ${nombre} se detuvo; relanzando en 2s" >&2
            sleep 2
        done
    ) &
}

relanzar queue php artisan queue:work --tries=3 --timeout=60 --sleep=3
relanzar reverb php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction

(while true; do php artisan schedule:run --no-interaction 2>/dev/null; sleep 60; done) &

# Apache no abre hasta que Reverb esté escuchando.
#
# Apache le pasa /app a Reverb (ver el ProxyPass del Dockerfile), pero Reverb
# tarda un par de segundos en levantar. Quien entrara en ese hueco se topaba
# con un proxy sin nadie detrás: "WebSocket is closed before the connection is
# established" en la consola. Por eso salía a veces y no siempre — solo a
# quien abría la página justo mientras el servidor arrancaba.
#
# Se espera hasta 15s y se sigue de todas formas: si Reverb no levanta, el
# sitio tiene que funcionar igual (sin tiempo real, pero funcionando).
for _ in $(seq 1 15); do
    (echo > /dev/tcp/127.0.0.1/8080) 2>/dev/null && break
    sleep 1
done

exec apache2-foreground
