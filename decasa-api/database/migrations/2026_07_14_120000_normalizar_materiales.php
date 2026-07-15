<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — limpieza de la tabla `materiales` (ver AGENT.md).
 *
 * Las 314 filas vienen de hojas de Excel distintas, así que la columna `unidad` tiene
 * 91 valores diferentes para ~15 unidades reales (LAMINA/LAMINAS, METRO/METROS/MTS/MRTROS…)
 * y hay materiales duplicados con grafías distintas (CARPINCOL / CARPINFLEX / COLBON / COBON
 * son todos pegante a $18.000/botella).
 *
 * IMPORTANTE: 313 de los 314 materiales están referenciados por nombre desde
 * `ficha_tecnica_items` (99% de match). Por eso NO se borra ni se renombra ninguna fila:
 * - `unidad` se conserva (solo se corrigen typos de display).
 * - se añade `unidad_norm` con la unidad canónica, para que el LLM pueda razonar cantidades.
 * - los duplicados se marcan con `activo = false` + `equivalente_a_id`, no se eliminan.
 *   El filtro `activo` solo se aplica al construir la lista de candidatos del cotizador,
 *   así que las fichas existentes siguen resolviendo igual.
 */
return new class extends Migration
{
    /** unidad (texto libre del Excel) → unidad canónica */
    private const MAPA = [
        'lamina'   => ['LAMINA', 'LAMINAS', 'LAMINA 3 x 1,22 MTS', 'LAMINA DE 12 "', 'LAMINA DE 15 M,M',
                       'LAMINA DE 18 M,M', 'LAMINA DE 2,44 X 1,22', 'LAMINA DE 244 X 122',
                       'LAMINA DE 3,00 X 122', 'LAMINA DE TRIPLEX DE 4 M.M', 'LAMINA EN CEDRO',
                       'LAMINAS DE 122 X 244', 'LAMINAS DE 244 X 151'],
        'sabana'   => ['SABANA', 'SABANAS'],
        'metro'    => ['METRO', 'METROS', 'MTS', 'MRTROS', 'METROS DE 44'],
        'tabla'    => ['TABLA', 'TABLAS', 'TABLA DE PINO 33 X 14 X 396', 'TABLAS DE 33 X 14 X 396',
                       'TABLAS DE PINO', 'TABLAS DE SAJO', 'JUEGO DE TABLAS'],
        'telera'   => ['TELERA', 'TELERAS', 'TELERAS DE PINO IMPORTADO'],
        'tira'     => ['TIRA', 'TIRAS', 'TIRAS DE 33 X 14'],
        'pulgada'  => ['PULGADAS', 'PULGADAS DE CEDRO'],
        'botella'  => ['BOTELLA', 'BOTELLAS', 'BOTELLA DE PL'],
        'juego'    => ['JUEGO', 'JUEGOS', 'JUEGO    PINTADA', 'JUEGO DE', 'JUEGOS CON TUERCAS',
                       'JUEGOS DE 35', 'JUEGOS DE 40', 'C-15 (juego x 2 unidades)',
                       'CLIK CLAK (juego x 2 unidades)', 'PARES'],
        'bolsa'    => ['BOLSAS'],
        'carril'   => ['CARRILES'],
        'tornillo' => ['TORNILLOS', 'TORNILLOS 2"'],
        'piel'     => ['PIELEAS'],
        'unidad'   => ['UNIDAD', 'UNIDADES', 'UNIDADES CON TUERCAS', 'BOTONES', 'BOMBILLOS', 'BRAZOS',
                       'MANIJAS', 'PATAS', 'TUBINO', 'TUBINOS', 'CONICAS', 'ESPEJO', 'VIDRIO',
                       'VIDRIO 10 M.M', 'HERRAJE', 'HERRAJE PARA CAMA', 'HIERRO', 'ESQUELETO',
                       'BASE', 'BASE CAMA', 'TOMA CON PUERTOS', 'TRONCO', 'COMPLETO', 'CORTE',
                       'MADERA', 'COMPRA ARMADA', 'COMPRA BOGOTA'],
    ];

    /** typos de `unidad` que se muestran en el desglose al vendedor */
    private const TYPOS = [
        'MRTROS'           => 'METROS',
        'PIELEAS'          => 'PIELES',
        'JUEGO    PINTADA' => 'JUEGO PINTADA',
    ];

    /**
     * Duplicados confirmados: mismo producto, misma unidad, mismo precio.
     * canónico => [grafías alternativas]
     */
    private const EQUIVALENTES = [
        'CARPINCOL' => ['CARPINFLEX', 'COLBON', 'COBON'],
    ];

    public function up(): void
    {
        Schema::table('materiales', function (Blueprint $table) {
            $table->string('unidad_norm', 20)->nullable()->after('unidad')->index();
            $table->boolean('activo')->default(true)->after('precio_unitario');
            $table->unsignedBigInteger('equivalente_a_id')->nullable()->after('activo');
            $table->foreign('equivalente_a_id')->references('id')->on('materiales')->nullOnDelete();
        });

        // 1. Unidad canónica
        foreach (self::MAPA as $norm => $variantes) {
            DB::table('materiales')->whereIn('unidad', $variantes)->update(['unidad_norm' => $norm]);
        }
        // Lo que no encaja en ninguna categoría queda como 'otro' — el LLM lo verá y
        // tendrá que declararlo en "supuestos" en vez de asumir una unidad falsa.
        DB::table('materiales')->whereNull('unidad_norm')->update(['unidad_norm' => 'otro']);

        // 2. Typos visibles en el desglose
        foreach (self::TYPOS as $malo => $bueno) {
            DB::table('materiales')->where('unidad', $malo)->update(['unidad' => $bueno]);
        }

        // 3. Duplicados: se marcan, NO se borran (las fichas los referencian por nombre)
        foreach (self::EQUIVALENTES as $canonico => $alternativas) {
            $idCanonico = DB::table('materiales')->where('nombre', $canonico)->value('id');
            if (! $idCanonico) continue;

            DB::table('materiales')
                ->whereIn('nombre', $alternativas)
                ->update(['activo' => false, 'equivalente_a_id' => $idCanonico]);
        }
    }

    public function down(): void
    {
        foreach (array_flip(self::TYPOS) as $bueno => $malo) {
            DB::table('materiales')->where('unidad', $bueno)->update(['unidad' => $malo]);
        }

        Schema::table('materiales', function (Blueprint $table) {
            $table->dropForeign(['equivalente_a_id']);
            $table->dropColumn(['unidad_norm', 'activo', 'equivalente_a_id']);
        });
    }
};
