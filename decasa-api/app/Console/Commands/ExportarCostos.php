<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportarCostos extends Command
{
    protected $signature   = 'db:exportar-costos {--output=costos_seed.sql}';
    protected $description = 'Exporta las tablas de costos/catálogo a un archivo SQL listo para importar en producción';

    private array $tablas = [
        'salarios_cargo',
        'tarifas_proceso',
        'materiales',
        'productos',
        'fichas_tecnicas',
        'ficha_tecnica_items',
    ];

    public function handle(): int
    {
        $archivo = $this->option('output');
        $lines   = [];

        $lines[] = '-- Decasa — Seed de costos y catálogo';
        $lines[] = '-- Generado: ' . now()->toDateTimeString();
        $lines[] = '-- Importar en Aiven: mysql -h HOST -P PORT -u USER -pPASS --ssl-ca=... DB < ' . $archivo;
        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $lines[] = '';

        foreach ($this->tablas as $tabla) {
            try {
                $filas = DB::table($tabla)->get();

                if ($filas->isEmpty()) {
                    $this->line("  <comment>$tabla: vacía, omitida</comment>");
                    continue;
                }

                $lines[] = "-- ── $tabla (" . $filas->count() . " filas) ───────────────────────────────────────";

                foreach ($filas as $fila) {
                    $datos = (array) $fila;
                    $cols  = implode(', ', array_map(fn($c) => "`$c`", array_keys($datos)));
                    $vals  = implode(', ', array_map(fn($v) => $this->escapar($v), array_values($datos)));
                    $lines[] = "INSERT INTO `$tabla` ($cols) VALUES ($vals) ON DUPLICATE KEY UPDATE "
                        . implode(', ', array_map(
                            fn($c) => "`$c` = VALUES(`$c`)",
                            array_filter(array_keys($datos), fn($c) => $c !== 'id')
                        )) . ';';
                }

                $lines[] = '';
                $this->info("  ✓ $tabla: {$filas->count()} filas");
            } catch (\Throwable $e) {
                $this->warn("  ✗ $tabla: " . $e->getMessage());
            }
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';

        file_put_contents($archivo, implode("\n", $lines));

        $this->newLine();
        $this->info("✅ Archivo generado: $archivo");
        $this->newLine();
        $this->line('<comment>Para importar en Aiven, ejecuta este comando (en la carpeta del proyecto):</comment>');
        $this->line('');
        $this->line('  mysql -h decasa-bdedatos76-7dd3.k.aivencloud.com -P 16847 -u avnadmin -p \\');
        $this->line('        --ssl-mode=REQUIRED defaultdb < ' . $archivo);
        $this->line('');
        $this->line('(te pedirá la contraseña de Aiven)');

        return self::SUCCESS;
    }

    private function escapar(mixed $valor): string
    {
        if ($valor === null) return 'NULL';
        if (is_bool($valor)) return $valor ? '1' : '0';
        if (is_int($valor) || is_float($valor)) return (string) $valor;
        return "'" . addslashes((string) $valor) . "'";
    }
}
