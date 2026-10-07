<?php
// Mide consultas y tiempo por endpoint a través del kernel HTTP real.
// Uso: php medir.php [supervisor|vendedor] [ruta...]
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
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Las funciones de MySQL que usa la app y SQLite no tiene: sin ellas esos
// endpoints daban 500 y no se podían medir. Basta con que respondan algo
// razonable; aquí se cuentan consultas, no se validan cifras.
$pdo = DB::connection()->getPdo();
$fecha = fn ($v) => $v === null ? null : \Carbon\Carbon::parse($v);
$pdo->sqliteCreateFunction('CURDATE', fn () => now()->toDateString(), 0);
$pdo->sqliteCreateFunction('NOW', fn () => now()->toDateTimeString(), 0);
$pdo->sqliteCreateFunction('DATEDIFF', fn ($a, $b) => ($a === null || $b === null) ? null
    : (int) $fecha($b)->startOfDay()->diffInDays($fecha($a)->startOfDay(), false), 2);
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', fn ($u, $a, $b) => ($a === null || $b === null) ? null
    : (int) $fecha($a)->diffInMinutes($fecha($b), false) / (strtoupper($u) === 'HOUR' ? 60 : (strtoupper($u) === 'DAY' ? 1440 : 1)), 3);
$pdo->sqliteCreateFunction('CONVERT_TZ', fn ($v, $d, $h) => $v === null ? null
    : $fecha($v)->addHours((int) substr($h, 0, 3) - (int) substr($d, 0, 3))->format('Y-m-d H:i:s'), 3);
$pdo->sqliteCreateFunction('DATE_FORMAT', fn ($v, $f) => $v === null ? null
    : $fecha($v)->format(strtr($f, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's', '%u' => 'W', '%x' => 'o', '%v' => 'W'])), 2);
$pdo->sqliteCreateFunction('FIELD', function ($x, ...$opciones) {
    $i = array_search($x, $opciones); return $i === false ? 0 : $i + 1;
}, -1);
$pdo->sqliteCreateFunction('CONCAT', fn (...$p) => in_array(null, $p, true) ? null : implode('', $p), -1);
$pdo->sqliteCreateFunction('GREATEST', fn (...$p) => max($p), -1);
$pdo->sqliteCreateFunction('JSON_LENGTH', fn ($j) => $j === null ? null : count((array) json_decode($j, true)), 1);

// La vista que la migración crea con `CREATE OR REPLACE` (solo MySQL).
DB::statement('CREATE VIEW IF NOT EXISTS v_saldo_ordenes AS
    SELECT o.id AS orden_id, o.valor_total,
           COALESCE(SUM(p.monto), 0) AS total_pagado,
           o.valor_total - COALESCE(SUM(p.monto), 0) AS saldo_pendiente
    FROM ordenes o LEFT JOIN pagos p ON p.orden_id = o.id
    GROUP BY o.id, o.valor_total');
$pdo->sqliteCreateFunction('LEAST', fn (...$p) => min($p), -1);

// Cargas perezosas (N+1): una relación que se pide fila por fila. No se
// cortan —el endpoint sigue igual—, solo se cuentan y se dice cuál.
$perezosas = [];
Illuminate\Database\Eloquent\Model::preventLazyLoading();
Illuminate\Database\Eloquent\Model::handleLazyLoadingViolationUsing(function ($modelo, $relacion) use (&$perezosas) {
    $perezosas[class_basename($modelo) . '->' . $relacion] = ($perezosas[class_basename($modelo) . '->' . $relacion] ?? 0) + 1;
});

$quien = $argv[1] ?? 'supervisor';
$u = App\Models\Usuario::where('rol', $quien)->orderBy('id')->first();
$token = $u->createToken('perf')->plainTextToken;
$rutas = array_slice($argv, 2) ?: explode(',', getenv('RUTAS'));
$RTT = (float) (getenv('RTT_MS') ?: 0);

$q = 0; $qt = 0.0; $sqls = [];
DB::listen(function ($e) use (&$q, &$qt, &$sqls) { $q++; $qt += $e->time; $sqls[] = $e->sql; });

printf("%-48s %5s %6s %8s %9s %s\n", "endpoint ($quien)", 'http', 'consul', 'ms_local', "ms+RTT$RTT", 'bytes');
foreach ($rutas as $r) {
    $r = trim($r); if ($r === '') continue;
    [$met, $path] = str_contains($r, ' ') ? explode(' ', $r, 2) : ['GET', $r];
    $q = 0; $qt = 0; $sqls = []; $perezosas = [];
    Illuminate\Support\Facades\Auth::forgetGuards();
    $req = Illuminate\Http\Request::create('/api' . $path, $met, [], [], [], [
        'HTTP_AUTHORIZATION' => "Bearer $token", 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.0.0.' . mt_rand(1, 250)]);
    $t = microtime(true);
    $res = $kernel->handle($req);
    $ms = (microtime(true) - $t) * 1000;
    $kernel->terminate($req, $res);
    printf("%-48s %5d %6d %8.0f %9.0f %d\n", substr("$met $path", 0, 48), $res->getStatusCode(), $q, $ms, $ms + $q * $RTT, strlen($res->getContent()));
    // GUARDAR=carpeta: deja la respuesta exacta en disco, para comparar
    // antes/después de una optimización (tiene que salir idéntica).
    if ($dir = getenv('GUARDAR')) {
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/' . $quien . '_' . preg_replace('/[^a-z0-9]+/i', '_', trim($path, '/')) . '.json', $res->getContent());
    }
    foreach ($perezosas as $rel => $n) if ($n > 1) echo "      N+1 x$n  $rel
";
    if (getenv('VER_SQL') && $res->getStatusCode() < 400) {
        $c = array_count_values(array_map(fn ($s) => preg_replace('/\d+/', 'N', substr($s, 0, 90)), $sqls));
        arsort($c); foreach (array_slice($c, 0, 6, true) as $s => $n) if ($n > 1) echo "      x$n  $s\n";
    }
    if ($res->getStatusCode() >= 500) echo '      ERR ' . substr(json_decode($res->getContent())->message ?? $res->getContent(), 0, 160) . "\n";
}
