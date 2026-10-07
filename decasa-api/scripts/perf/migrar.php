<?php
// Uso: php migrar.php  (con DB_CONNECTION=sqlite DB_DATABASE=... APP_KEY=...)
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

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$status = $kernel->call('migrate', ['--force' => true]);
echo $kernel->output() === '' ? '' : '';
$tablas = count(Illuminate\Support\Facades\DB::select("select name from sqlite_master where type='table'"));
echo "status=$status tablas=$tablas saltadas=" . count(TolerantSqliteConnection::$saltadas) . PHP_EOL;
foreach (array_slice(array_unique(TolerantSqliteConnection::$saltadas), 0, 8) as $s) echo "  - $s\n";
