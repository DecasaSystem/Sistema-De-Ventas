<?php
// Siembra datos sintéticos con volumen parecido a producción. Solo para medir.
$base = getenv('API_DIR');
require $base . '/vendor/autoload.php';
// El código de app/ de ESTE checkout primero (el vendor puede ser un enlace a otro).
spl_autoload_register(function ($c) use ($base) {
    if (strncmp($c, 'App' . chr(92), 4) !== 0) return;
    $f = $base . '/app/' . strtr(substr($c, 4), chr(92), '/') . '.php';
    if (is_file($f)) require $f;
}, true, true);
require __DIR__ . '/TolerantSqlite.php';
$app = require $base . '/bootstrap/app.php';
Illuminate\Database\Connection::resolverFor('sqlite', fn ($pdo, $db, $prefix, $config) =>
    new TolerantSqliteConnection($pdo, $db, $prefix, $config));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
mt_srand(42);

$cols = [];
function cols($t) { global $cols; return $cols[$t] ??= DB::select("PRAGMA table_info($t)"); }
// Rellena las columnas NOT NULL sin default que no se dieron.
function fila($t, array $v) {
    foreach (cols($t) as $c) {
        if ($c->pk || array_key_exists($c->name, $v) || ! $c->notnull || $c->dflt_value !== null) continue;
        $ty = strtolower($c->type);
        $v[$c->name] = str_contains($ty, 'int') || str_contains($ty, 'numeric') || str_contains($ty, 'decimal') || str_contains($ty, 'real') || str_contains($ty, 'float') ? 0
            : (str_contains($ty, 'date') || str_contains($ty, 'time') ? now()->toDateTimeString() : 'x');
    }
    $existentes = array_column(cols($t), 'name');
    return array_intersect_key($v, array_flip($existentes));
}
function ins($t, array $rows) {
    $grupos = [];
    foreach ($rows as $r) { $r = fila($t, $r); ksort($r); $grupos[implode(',', array_keys($r))][] = $r; }
    foreach ($grupos as $g) foreach (array_chunk($g, 80) as $ch) DB::table($t)->insert($ch);
}

DB::beginTransaction();
$tiendas = ['Decasa Norte','Decasa Vía El Edén','Decasa Vía Jardines','Decasa Unicentro Pereira','Decasa Circunvalar','Bodega Fábrica','Independientes'];
foreach ($tiendas as $i => $n) ins('tiendas', [['nombre' => $n, 'ciudad' => $i > 2 && $i < 5 ? 'Pereira' : 'Armenia', 'activa' => 1, 'es_fabrica' => $n === 'Bodega Fábrica' ? 1 : 0, 'es_independientes' => $n === 'Independientes' ? 1 : 0, 'created_at' => now()]]);
$tids = DB::table('tiendas')->pluck('id')->all();

$pw = bcrypt('x');
$usuarios = [['nombre' => 'Super', 'email' => 'sup@x', 'password' => $pw, 'rol' => 'supervisor', 'activo' => 1, 'tienda_default_id' => $tids[0],
    'acceso_costos' => 1, 'acceso_despacho' => 1, 'acceso_produccion' => 1, 'gestiona_produccion' => 1, 'acceso_comisiones' => 1, 'acceso_surtir' => 1, 'acceso_reserva' => 1, 'acceso_nomina' => 1, 'created_at' => now()]];
for ($i = 0; $i < 25; $i++) $usuarios[] = ['nombre' => "Vend $i", 'email' => "v$i@x", 'password' => $pw, 'rol' => 'vendedor', 'activo' => 1, 'tienda_default_id' => $tids[$i % 5], 'apto_comisiones' => 1, 'created_at' => now()];
for ($i = 0; $i < 4; $i++) $usuarios[] = ['nombre' => "Cond $i", 'email' => "c$i@x", 'password' => $pw, 'rol' => 'conductor', 'activo' => 1, 'created_at' => now()];
ins('usuarios', $usuarios);
$vend = DB::table('usuarios')->where('rol', 'vendedor')->pluck('tienda_default_id', 'id')->all();

$cats = ['SALAS','CAMAS','COMEDORES','ESCRITORIOS','SILLAS','MESAS','ACCESORIOS'];
$p = []; for ($i = 0; $i < 600; $i++) $p[] = ['nombre' => "Producto $i", 'categoria' => $cats[$i % 7], 'precio_base' => 100000 + $i * 1000, 'activo' => 1, 'created_at' => now()];
ins('productos', $p);
$pids = DB::table('productos')->pluck('id')->all();
$inv = []; foreach ($pids as $pid) foreach ($tids as $t) $inv[] = ['producto_id' => $pid, 'tienda_id' => $t, 'cantidad_disponible' => mt_rand(0, 6), 'cantidad_reservada' => mt_rand(0, 1), 'stock_minimo' => 1];
ins('inventario', $inv);

$c = []; for ($i = 0; $i < 6000; $i++) $c[] = ['nombre' => "Cliente $i Apellido", 'telefono' => '300' . str_pad($i, 7, '0'), 'cedula' => (string) (1000000 + $i), 'created_at' => now()->subDays(mt_rand(0, 400))];
ins('clientes', $c);
$cids = DB::table('clientes')->pluck('id')->all();

$estados = ['pendiente_anticipo','en_produccion','listo_entrega','entregado','entregado','entregado','cancelado'];
$vids = array_keys($vend);
$ords = [];
for ($i = 0; $i < 8000; $i++) {
    $v = $vids[$i % count($vids)];
    $f = now()->subDays(mt_rand(0, 400));
    $ords[] = ['cliente_id' => $cids[mt_rand(0, count($cids) - 1)], 'vendedor_id' => $v, 'tienda_id' => $vend[$v], 'canal' => 'fisica', 'tipo' => 'venta',
        'estado' => $estados[$i % 7], 'numero_orden' => 1000 + $i, 'valor_total' => mt_rand(5, 80) * 100000, 'descuento_total' => 0, 'anticipo_pct' => 50,
        'confirmada_en' => $f, 'created_at' => $f, 'updated_at' => $f];
}
ins('ordenes', $ords);
$oids = DB::table('ordenes')->select('id', 'valor_total', 'vendedor_id', 'tienda_id', 'created_at', 'estado')->get();
$items = []; $pagos = []; $com = [];
foreach ($oids as $o) {
    $n = mt_rand(1, 4);
    for ($k = 0; $k < $n; $k++) $items[] = ['orden_id' => $o->id, 'producto_id' => $pids[mt_rand(0, 599)], 'cantidad' => 1, 'precio_unitario' => $o->valor_total / $n,
        'subtotal' => $o->valor_total / $n, 'es_personalizado' => $k === 0 && $o->estado === 'en_produccion' ? 1 : 0, 'fecha_entrega_prom' => now()->addDays(mt_rand(-20, 40))->toDateString(), 'created_at' => $o->created_at];
    $pagos[] = ['orden_id' => $o->id, 'vendedor_id' => $o->vendedor_id, 'tienda_id' => $o->tienda_id, 'tipo' => 'anticipo', 'monto' => $o->valor_total / 2, 'metodo' => 'efectivo', 'created_at' => $o->created_at];
    if (mt_rand(0, 1)) $pagos[] = ['orden_id' => $o->id, 'vendedor_id' => $o->vendedor_id, 'tienda_id' => $o->tienda_id, 'tipo' => 'abono', 'monto' => $o->valor_total / 2, 'metodo' => 'transferencia', 'created_at' => $o->created_at];
    $com[] = ['orden_id' => $o->id, 'vendedor_id' => $o->vendedor_id, 'tienda_id' => $o->tienda_id, 'mes_venta' => substr($o->created_at, 0, 7), 'valor_orden' => $o->valor_total,
        'fecha_venta' => $o->created_at, 'fecha_disponible' => $o->created_at, 'estado' => 'pendiente', 'monto_comision' => 0, 'created_at' => $o->created_at];
}
ins('orden_items', $items); ins('pagos', $pagos); ins('comisiones', $com);

$prod = [];
foreach (DB::table('orden_items')->where('es_personalizado', 1)->pluck('id') as $iid)
    $prod[] = ['orden_item_id' => $iid, 'estado' => 'en_proceso', 'created_at' => now()];
ins('produccion', $prod);

$sup = DB::table('usuarios')->where('rol', 'supervisor')->value('id');
$n = []; for ($i = 0; $i < 3000; $i++) $n[] = ['usuario_id' => $i % 3 ? $sup : $vids[0], 'tipo' => 'abono_registrado', 'titulo' => "Aviso $i", 'mensaje' => 'm', 'leida' => $i > 50 ? 1 : 0, 'created_at' => now()->subMinutes($i * 30)];
ins('notificaciones', $n);

// Lo que alimenta comisiones y caja, para que la comparación antes/después
// de una optimización tenga algo que comparar ahí.
$tiendasVenta = array_slice($tids, 0, 5);
$meses = []; for ($i = 13; $i >= 0; $i--) $meses[] = now()->startOfMonth()->subMonths($i)->format('Y-m');
$asesores = []; $metas = [];
foreach ($tiendasVenta as $t) {
    $equipo = array_keys(array_filter($vend, fn ($tt) => $tt == $t));
    foreach ([$meses[0], $meses[5], $meses[10]] as $k => $mes) {           // el equipo cambia dos veces
        foreach (array_slice($equipo, 0, 3 + $k % 2) as $v) $asesores[] = ['tienda_id' => $t, 'mes' => $mes, 'vendedor_id' => $v, 'created_at' => now()];
    }
    foreach ($meses as $i => $mes) if ($i % 3 === 0) $metas[] = ['tienda_id' => $t, 'mes' => $mes, 'meta' => 30000000 + $t * 1000000, 'divisor_asesores' => 3, 'created_at' => now()];
}
ins('tienda_asesores_comision', $asesores); ins('metas_tienda', $metas);
$reemp = [];
foreach (array_slice($vids, 0, 8) as $i => $v) $reemp[] = ['tienda_id' => $tiendasVenta[($i + 1) % 5], 'usuario_id' => $v, 'reemplaza_a_id' => $vids[$i + 8],
    'desde' => now()->subDays(30 * $i + 5)->toDateString(), 'hasta' => $i % 3 ? now()->subDays(30 * $i - 5)->toDateString() : null, 'tipo' => 'reemplazo', 'created_at' => now()];
ins('tienda_reemplazos', $reemp);
$ant = [];
foreach (array_slice($vids, 0, 12) as $i => $v) {
    foreach ($meses as $mes) $ant[] = ['vendedor_id' => $v, 'tipo' => 'anticipo', 'mes' => $mes, 'monto' => 200000, 'clave' => "$v-$mes", 'created_at' => now()];
    if ($i % 2) $ant[] = ['vendedor_id' => $v, 'tipo' => 'descuento', 'mes' => $meses[6], 'monto' => 350000 + $i * 1000, 'created_at' => now()];
}
ins('comision_anticipos', $ant);
$cfg = []; foreach (array_slice($vids, 0, 12) as $v) $cfg[] = ['vendedor_id' => $v, 'desde_mes' => $meses[0], 'monto' => 200000, 'activo' => 1, 'created_at' => now()];
ins('comision_anticipos_config', $cfg);
$caja = [];
foreach ($tids as $t) for ($k = 0; $k < 40; $k++)
    $caja[] = ['tienda_id' => $t, 'usuario_id' => $vids[$k % count($vids)], 'tipo' => $k % 3 ? 'egreso' : 'ingreso_manual', 'monto' => 10000 * ($k + 1), 'concepto' => 'x', 'created_at' => now()->subDays($k)];
ins('caja_movimientos', $caja);
// Un vendedor con caja propia (sus movimientos no cuentan en la tienda) y una tienda cerrada.
DB::table('usuarios')->where('id', $vids[1])->update(['independiente' => 1]);
DB::table('tiendas')->where('id', $tiendasVenta[2])->update(['cerrada_en' => now()->subMonths(4)->toDateString()]);
DB::commit();

foreach (['tiendas','usuarios','productos','inventario','clientes','ordenes','orden_items','pagos','comisiones','produccion','notificaciones'] as $t)
    echo str_pad($t, 16) . DB::table($t)->count() . PHP_EOL;
