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
php artisan event:cache 2>/dev/null || true

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

# La cola y las tareas programadas corren como www-data, el mismo usuario de
# Apache. La caché vive en archivos (storage/framework/cache): si estos
# procesos corrieran como root crearían carpetas de root ahí dentro, y Apache
# ya no podría escribir en ellas —el límite de peticiones fallaría con un 500
# al azar—.
#
# La cola vive en la base, que comparten todos los servidores. Un servidor que
# todavía no recibe tráfico (el nuevo, durante una mudanza de región) se
# arranca con COLA_ACTIVA=0: si no, tomaría trabajos que encoló el que sí
# atiende —entre ellos los avisos en tiempo real— y los mandaría por SU
# Reverb, al que no hay nadie conectado: la gente dejaría de ver los cambios
# al instante.
if [ "${COLA_ACTIVA:-1}" = "1" ]; then
    relanzar queue runuser -u www-data -- php artisan queue:work --tries=3 --timeout=60 --sleep=3
else
    echo "[decasa] COLA_ACTIVA=0: este servidor no procesa la cola" >&2
fi
relanzar reverb php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction

# Las tareas programadas (comisiones 6:30, respaldo 3:00, avisos…) corren en
# UN solo servidor. Mientras convivan dos —al mudar el servicio de región, ver
# docs/plan-rendimiento.md— el que no manda se arranca con
# PROGRAMADOR_ACTIVO=0; si no, cada tarea correría dos veces (avisos dobles,
# dos respaldos). `withoutOverlapping` no lo evita: su candado está en la caché
# de archivos de cada contenedor.
if [ "${PROGRAMADOR_ACTIVO:-1}" = "1" ]; then
    (while true; do runuser -u www-data -- php artisan schedule:run --no-interaction 2>/dev/null; sleep 60; done) &
else
    echo "[decasa] PROGRAMADOR_ACTIVO=0: las tareas programadas no corren en este servidor" >&2
fi

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
