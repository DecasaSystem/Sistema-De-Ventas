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
    $q = 0; $qt = 0; $sqls = [];
    Illuminate\Support\Facades\Auth::forgetGuards();
    $req = Illuminate\Http\Request::create('/api' . $path, $met, [], [], [], [
        'HTTP_AUTHORIZATION' => "Bearer $token", 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.0.0.' . mt_rand(1, 250)]);
    $t = microtime(true);
    $res = $kernel->handle($req);
    $ms = (microtime(true) - $t) * 1000;
    $kernel->terminate($req, $res);
    printf("%-48s %5d %6d %8.0f %9.0f %d\n", substr("$met $path", 0, 48), $res->getStatusCode(), $q, $ms, $ms + $q * $RTT, strlen($res->getContent()));
    if (getenv('VER_SQL') && $res->getStatusCode() < 400) {
        $c = array_count_values(array_map(fn ($s) => preg_replace('/\d+/', 'N', substr($s, 0, 90)), $sqls));
        arsort($c); foreach (array_slice($c, 0, 6, true) as $s => $n) if ($n > 1) echo "      x$n  $s\n";
    }
    if ($res->getStatusCode() >= 500) echo '      ERR ' . substr(json_decode($res->getContent())->message ?? $res->getContent(), 0, 160) . "\n";
}
