#!/usr/bin/env bash
# Arnés de rendimiento local (ver docs/plan-rendimiento.md).
#
# Monta el esquema real en SQLite (saltando el SQL que solo entiende MySQL),
# siembra un volumen parecido al de producción y mide, por endpoint, cuántas
# consultas hace y cuánto tardaría con la latencia real hacia la base.
#
#   bash scripts/perf/medir.sh                      # las rutas por defecto
#   bash scripts/perf/medir.sh vendedor /ordenes    # otro usuario / rutas
#   RTT_MS=200 CACHE_STORE=database bash scripts/perf/medir.sh supervisor /push/vapid-key
#   VER_SQL=1 ...                                   # muestra las consultas repetidas
#
# La base queda en storage/perf/ (ignorado por git). REHACER=1 la reconstruye.
set -euo pipefail
AQUI="$(cd "$(dirname "$0")" && pwd)"
command -v cygpath >/dev/null 2>&1 && AQUI="$(cygpath -m "$AQUI")"
export API_DIR="${AQUI%/scripts/perf}"
PHP="${PHP:-/c/php/php.exe}"
command -v "$PHP" >/dev/null 2>&1 || PHP=php
mkdir -p "$API_DIR/storage/perf"
BASE="$API_DIR/storage/perf/perf.sqlite"

export MSYS_NO_PATHCONV=1
export APP_KEY="base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="
export DB_CONNECTION=sqlite DB_DATABASE="$BASE"
export BROADCAST_CONNECTION=null QUEUE_CONNECTION=sync LOG_CHANNEL=null
export CACHE_STORE="${CACHE_STORE:-file}" RTT_MS="${RTT_MS:-200}"

if [[ ! -s "$BASE" || "${REHACER:-}" == 1 ]]; then
  rm -f "$BASE"; touch "$BASE"
  PERF_MIGRANDO=1 "$PHP" "$AQUI/migrar.php"
  "$PHP" -d memory_limit=1G "$AQUI/sembrar.php"
fi

QUIEN="${1:-supervisor}"; shift || true
if [[ $# -eq 0 ]]; then
  export RUTAS="${RUTAS:-/push/vapid-key,/auth/me,/modulos,/notificaciones,/catalogo-telas,/inventario/surtidos/pendientes,/despacho/cola,/despacho/asignados,/tiendas,/ordenes,/ordenes/50,/clientes,/productos,/inventario?tienda_id=1,/stats/cartera,/reportes/ventas,/reportes/pendientes,/cotizaciones,/caja/balance}"
fi
"$PHP" -d memory_limit=1G "$AQUI/medir.php" "$QUIEN" "$@"
