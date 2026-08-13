<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;

/**
 * Copia de seguridad de la base, al correo de la empresa.
 *
 * No usa `mysqldump`: el servidor autentica con caching_sha2_password y el
 * cliente de MariaDB que trae la imagen no sabe hablar ese idioma. Se vuelca
 * con la misma conexión que usa la aplicación, que ya funciona.
 *
 * Sale un .sql.gz de dos o tres megas —los datos de verdad son unos 17— así
 * que cabe de sobra en un correo. Va al buzón de la empresa a propósito: si
 * un día se pierde la cuenta de Aiven o la de Render, la copia sigue estando
 * en un sitio que no depende de ninguna de las dos.
 */
class RespaldarBaseDatos extends Command
{
    protected $signature = 'respaldo:base
                            {--a= : A qué correo enviarlo (por defecto, el de la empresa)}
                            {--guardar= : Escribirlo en esta ruta en vez de enviarlo}';

    protected $description = 'Vuelca la base de datos y la manda comprimida al correo de la empresa';

    /**
     * Tablas de las que se guarda la estructura pero no el contenido.
     *
     * Son de usar y tirar: la caché se regenera sola y las sesiones caducan.
     * Sólo `cache` son 13 de los 30 MB, así que dejarlas fuera hace la copia
     * cuatro veces más pequeña sin perder un solo dato del negocio.
     */
    private const SIN_DATOS = [
        'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function handle(): int
    {
        $inicio = microtime(true);

        try {
            [$sql, $resumen] = $this->volcar();
        } catch (\Throwable $e) {
            return $this->fallar('No se pudo leer la base: ' . $e->getMessage());
        }

        $comprimido = gzencode($sql, 9);
        $nombre     = 'decasa_' . now()->format('Y-m-d_Hi') . '.sql.gz';

        $this->info(sprintf(
            '%d tablas, %s filas, %s sin comprimir, %s comprimido',
            $resumen['tablas'],
            number_format($resumen['filas']),
            $this->enMegas(strlen($sql)),
            $this->enMegas(strlen($comprimido))
        ));

        if ($ruta = $this->option('guardar')) {
            file_put_contents($ruta, $comprimido);
            $this->info("Guardado en {$ruta}");
            return self::SUCCESS;
        }

        $destino = $this->option('a') ?: config('mail.from.address');

        try {
            Mail::raw($this->cuerpoDelCorreo($resumen, $sql, $comprimido, $inicio),
                function (Message $m) use ($destino, $nombre, $comprimido) {
                    $m->to($destino)
                      ->subject('Copia de seguridad — ' . now()->format('d/m/Y'))
                      ->attachData($comprimido, $nombre, ['mime' => 'application/gzip']);
                });
        } catch (\Throwable $e) {
            return $this->fallar('No se pudo enviar el correo: ' . $e->getMessage());
        }

        $this->info("Enviado a {$destino}");
        Log::info('Respaldo enviado', ['a' => $destino] + $resumen);

        return self::SUCCESS;
    }

    /**
     * Arma el .sql completo: primero todas las estructuras, después los datos.
     *
     * En ese orden porque las tablas se apuntan entre ellas con claves
     * foráneas; creándolas todas antes de meter nada, el orden de los datos
     * deja de importar.
     */
    private function volcar(): array
    {
        $bd     = DB::getDatabaseName();
        $tablas = collect(DB::select('SHOW FULL TABLES'))
            ->map(fn ($t) => (array) $t)
            ->map(fn ($t) => ['nombre' => array_values($t)[0], 'tipo' => array_values($t)[1]]);

        $sql = "-- Copia de seguridad de Decasa\n"
             . '-- ' . now()->format('d/m/Y H:i') . " (hora de Colombia)\n"
             . "-- base: {$bd}\n\n"
             . "SET NAMES utf8mb4;\n"
             . "SET FOREIGN_KEY_CHECKS = 0;\n"
             . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        $filas = 0;

        foreach ($tablas as $t) {
            if ($t['tipo'] === 'VIEW') continue;
            $sql .= $this->estructura($t['nombre']);
        }

        foreach ($tablas as $t) {
            if ($t['tipo'] === 'VIEW') continue;
            if (in_array($t['nombre'], self::SIN_DATOS, true)) {
                $sql .= "-- {$t['nombre']}: sólo la estructura, el contenido se regenera solo\n\n";
                continue;
            }
            [$trozo, $n] = $this->datos($t['nombre']);
            $sql .= $trozo;
            $filas += $n;
        }

        // Las vistas se crean al final: dependen de las tablas de arriba.
        foreach ($tablas as $t) {
            if ($t['tipo'] !== 'VIEW') continue;
            $sql .= $this->estructura($t['nombre'], true);
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        return [$sql, ['tablas' => $tablas->count(), 'filas' => $filas]];
    }

    private function estructura(string $tabla, bool $esVista = false): string
    {
        $fila = (array) DB::select('SHOW CREATE TABLE `' . $tabla . '`')[0];
        $ddl  = $esVista ? array_values($fila)[1] : array_values($fila)[1];

        $tipo = $esVista ? 'VIEW' : 'TABLE';

        return "DROP {$tipo} IF EXISTS `{$tabla}`;\n{$ddl};\n\n";
    }

    /**
     * Los datos de una tabla, en INSERTs de a mil filas.
     *
     * Se lee por trozos y no de golpe: aunque hoy la base sean 17 MB, traerse
     * una tabla entera a memoria deja de funcionar en cuanto crezca, y esto
     * tiene que seguir corriendo dentro de dos años sin que nadie lo mire.
     */
    private function datos(string $tabla): array
    {
        $pdo      = DB::connection()->getPdo();
        $columnas = collect(DB::select('SHOW COLUMNS FROM `' . $tabla . '`'))
            ->map(fn ($c) => $c->Field);
        $lista    = $columnas->map(fn ($c) => "`{$c}`")->implode(', ');

        $sql   = "-- {$tabla}\n";
        $filas = 0;
        $lote  = [];
        $total = 0;

        foreach (DB::cursor("SELECT * FROM `{$tabla}`") as $registro) {
            $valores = [];
            foreach ($columnas as $c) {
                $v = ((array) $registro)[$c] ?? null;
                $valores[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
            }
            $lote[] = '(' . implode(',', $valores) . ')';
            $filas++;

            if (count($lote) >= 1000) {
                $sql .= "INSERT INTO `{$tabla}` ({$lista}) VALUES\n" . implode(",\n", $lote) . ";\n";
                $total += count($lote);
                $lote = [];
            }
        }

        if ($lote) {
            $sql .= "INSERT INTO `{$tabla}` ({$lista}) VALUES\n" . implode(",\n", $lote) . ";\n";
        }

        return [$sql . "\n", $filas];
    }

    private function cuerpoDelCorreo(array $resumen, string $sql, string $gz, float $inicio): string
    {
        return "Copia de seguridad de la base de datos de Decasa.\n\n"
             . 'Fecha: ' . now()->format('d/m/Y H:i') . " (hora de Colombia)\n"
             . "Tablas: {$resumen['tablas']}\n"
             . 'Filas: ' . number_format($resumen['filas']) . "\n"
             . 'Tamaño: ' . $this->enMegas(strlen($gz)) . ' (' . $this->enMegas(strlen($sql)) . " sin comprimir)\n"
             . 'Tardó: ' . round(microtime(true) - $inicio, 1) . " segundos\n\n"
             . "Para restaurarla hace falta descomprimir el archivo y cargarlo en una base\n"
             . "vacía. Guarda este correo: si un día se pierde el servidor, esto es lo único\n"
             . "que queda.\n\n"
             . "Si algún día deja de llegar este correo, avisa: significa que la copia no se\n"
             . "está haciendo.\n";
    }

    /** Avisa por log y por correo, y devuelve error para que se note. */
    private function fallar(string $motivo): int
    {
        $this->error($motivo);
        Log::error('Falló la copia de seguridad', ['motivo' => $motivo]);

        // Una copia que falla en silencio es peor que no tenerla: se cree que
        // hay red y no la hay.
        try {
            Mail::raw(
                "La copia de seguridad de la base NO se pudo hacer.\n\n"
                . "Motivo: {$motivo}\n"
                . 'Fecha: ' . now()->format('d/m/Y H:i') . "\n\n"
                . "Hay que revisarlo: mientras tanto no se está guardando nada.",
                fn (Message $m) => $m->to(config('mail.from.address'))
                    ->subject('⚠️ FALLÓ la copia de seguridad')
            );
        } catch (\Throwable $e) {
            Log::error('Y tampoco se pudo avisar del fallo', ['error' => $e->getMessage()]);
        }

        return self::FAILURE;
    }

    private function enMegas(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
}
