<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repara los índices únicos de nómina que quedaron mal en la unificación de
 * trabajadores (agosto 2026).
 *
 * `nomina_pagos` tenía `unique(empleado_id, fecha_inicio)` y `nomina_ausencias`
 * `unique(empleado_id, fecha)`. Al borrar la columna `empleado_id`, MySQL la
 * sacó del índice compuesto y lo dejó reducido a UNA sola columna
 * (`fecha_inicio` / `fecha`). Efecto: dos trabajadores no podían cobrar la
 * misma quincena ni faltar el mismo día — el segundo `INSERT` reventaba con
 * "Duplicate entry ... for key nomina_pagos_empleado_id_fecha_inicio_unique".
 *
 * Se restauran los únicos correctos por `(usuario_id, ...)`. No puede haber
 * duplicados reales porque el único de una sola columna los venía impidiendo.
 *
 * Solo aplica en MySQL (es donde está el daño); en SQLite de los tests no
 * hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->repararUnico('nomina_pagos',     'fecha_inicio', ['usuario_id', 'fecha_inicio']);
        $this->repararUnico('nomina_ausencias', 'fecha',        ['usuario_id', 'fecha']);

        // Índice de apoyo (no único) que también quedó reducido a `fecha_fin`.
        $this->repararIndiceApoyo('nomina_pagos', ['usuario_id', 'fecha_fin']);
    }

    public function down(): void
    {
        // Reparación de esquema: no se revierte.
    }

    /** Los índices de una tabla, con sus columnas en orden. */
    private function indices(string $tabla): \Illuminate\Support\Collection
    {
        return collect(DB::select(
            "SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             GROUP BY INDEX_NAME, NON_UNIQUE",
            [$tabla]
        ));
    }

    private function repararUnico(string $tabla, string $columnaRota, array $columnas): void
    {
        $objetivo = implode(',', $columnas);
        $nombre   = $tabla . '_' . implode('_', $columnas) . '_unique';
        $idx      = $this->indices($tabla);

        $yaEsta = $idx->first(fn ($i) => $i->cols === $objetivo && (int) $i->non_unique === 0);
        if ($yaEsta) {
            echo "[fix-nomina] {$tabla}: unique ({$objetivo}) ya existe (`{$yaEsta->name}`).\n";
            return;
        }

        // Contar duplicados por si acaso, antes de crear el único.
        $dups = DB::table($tabla)
            ->selectRaw(implode(',', array_map(fn ($c) => "`$c`", $columnas)))
            ->groupBy($columnas)
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        if ($dups > 0) {
            echo "[fix-nomina] ⚠ {$tabla}: hay {$dups} grupo(s) duplicado(s) en ({$objetivo}). NO se crea el único — revisar a mano.\n";
            return;
        }

        // Borrar el único de una sola columna que dejó la unificación.
        foreach ($idx as $i) {
            if ((int) $i->non_unique === 0 && $i->cols === $columnaRota && strtoupper($i->name) !== 'PRIMARY') {
                DB::statement("ALTER TABLE `{$tabla}` DROP INDEX `{$i->name}`");
                echo "[fix-nomina] {$tabla}: borrado unique roto `{$i->name}` (solo `{$columnaRota}`)\n";
            }
        }

        DB::statement("ALTER TABLE `{$tabla}` ADD UNIQUE `{$nombre}` (`" . implode('`,`', $columnas) . "`)");
        echo "[fix-nomina] {$tabla}: creado unique `{$nombre}` ({$objetivo})\n";
    }

    private function repararIndiceApoyo(string $tabla, array $columnas): void
    {
        $objetivo = implode(',', $columnas);
        $nombre   = $tabla . '_' . implode('_', $columnas) . '_index';
        $idx      = $this->indices($tabla);

        if ($idx->contains(fn ($i) => $i->cols === $objetivo)) {
            echo "[fix-nomina] {$tabla}: índice ({$objetivo}) ya existe.\n";
            return;
        }

        // Quitar el reducido a una sola columna (la última del compuesto).
        $ultima = end($columnas);
        foreach ($idx as $i) {
            if ((int) $i->non_unique === 1 && $i->cols === $ultima && strtoupper($i->name) !== 'PRIMARY') {
                DB::statement("ALTER TABLE `{$tabla}` DROP INDEX `{$i->name}`");
                echo "[fix-nomina] {$tabla}: borrado índice reducido `{$i->name}` (solo `{$ultima}`)\n";
            }
        }

        DB::statement("ALTER TABLE `{$tabla}` ADD INDEX `{$nombre}` (`" . implode('`,`', $columnas) . "`)");
        echo "[fix-nomina] {$tabla}: creado índice `{$nombre}` ({$objetivo})\n";
    }
};
