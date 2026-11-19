<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos especiales (docs/metodologia-de-trabajo-por-rol.md, fase 6).
 *
 * - `asigna_fechas`: poner la fecha de entrega de cada producto de una orden
 *   (el usuario: "a veces los vendedores asignan la fecha que cuadran con el
 *   cliente").
 * - `edita_catalogo`: cambiar precio, nombre y fotos de un producto.
 *
 * Hasta hoy eran solo del supervisor. Se les prenden a todos los supervisores,
 * así nadie pierde nada; a partir de aquí se pueden dar o quitar por persona.
 * Aprobar cambios de plata NO entra: sigue siendo solo de supervisores
 * (decisión del usuario, 2026-11-19). Solo agrega columnas.
 */
return new class extends Migration
{
    private const COLUMNAS = ['asigna_fechas', 'edita_catalogo'];

    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            foreach (self::COLUMNAS as $c) {
                if (! Schema::hasColumn('usuarios', $c)) {
                    $t->boolean($c)->default(false);
                }
            }
        });

        DB::table('usuarios')->where('rol', 'supervisor')
            ->update(array_fill_keys(self::COLUMNAS, true));
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            foreach (self::COLUMNAS as $c) {
                if (Schema::hasColumn('usuarios', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
