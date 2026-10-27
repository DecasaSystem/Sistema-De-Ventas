<?php

namespace App\Support;

use App\Http\Controllers\ComisionController;
use App\Services\ComisionIndependientes;

/**
 * Las reglas con las que se calcula una comisión, en un solo sitio.
 *
 * Hasta ahora cada cifra —el 5 %, el ÷ 1,19, el 5,5 % del datáfono, el 50 %
 * pagado, el día 20, la mitad de una venta compartida— estaba escrita a mano
 * en el cálculo, a veces en cinco sitios y dentro de SQL. El dueño quiere
 * poder cambiarlas sin programar "por si algún día cambia el funcionamiento
 * de todo" (2026-10-26, `docs/plan-comisiones-personalizables.md`).
 *
 * Fase 0 (esta): el cálculo ya no tiene cifras propias, las pide aquí, y aquí
 * cada regla vale lo mismo que valía en el código. No cambia ni un peso: se
 * comprobó con la prueba diferencial (respuestas idénticas byte a byte).
 *
 * Fase 1: cada regla podrá tener filas con "desde qué mes" y por alcance
 * (persona > tienda > general), como los conceptos de `CostoEmpleador`. Por
 * eso quien pregunta dice SIEMPRE de qué mes es la comisión y, si aplica, de
 * qué tienda y de qué persona: la regla que manda es la vigente en el mes de
 * la venta, no la de hoy, y así cambiar una regla desde noviembre no mueve
 * agosto. Mientras no haya filas, todo da lo de hoy.
 *
 * Una cifra nueva del cálculo de comisiones se agrega al catálogo, no se
 * escribe suelta en el código.
 */
class ReglasComision
{
    /**
     * El catálogo: qué se puede ajustar, cuánto vale si nadie lo cambia (lo
     * mismo que hacía el código) y a qué niveles se puede cambiar.
     *
     * tipo: 'fraccion' (0,05 = 5 %), 'numero', 'entero', 'si_no', 'lista'.
     */
    public const CATALOGO = [
        // ── Porcentajes ──────────────────────────────────────────────────
        'pct_pool' => [
            'tipo' => 'fraccion', 'defecto' => ComisionController::PORCENTAJE_DIRECTO, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Porcentaje del pool',
            'explica' => 'Lo que el equipo se reparte de lo vendido por encima de la meta.',
        ],
        'pct_individual' => [
            'tipo' => 'fraccion', 'defecto' => ComisionController::PORCENTAJE_DIRECTO, 'alcances' => ['general', 'tienda', 'usuario'],
            'nombre' => 'Porcentaje individual',
            'explica' => 'Lo que cobra de sus ventas quien no reparte pool: tienda sin meta, tienda que no comparte, o alguien de fuera del equipo.',
        ],
        'pct_restauracion' => [
            'tipo' => 'fraccion', 'defecto' => ComisionController::PORCENTAJE_DIRECTO, 'alcances' => ['general', 'tienda', 'usuario'],
            'nombre' => 'Porcentaje de una restauración',
            'explica' => 'Sobre el valor de la restauración. En una tienda que comparte, ese porcentaje se parte entre los que estaban ese día.',
        ],

        // ── IVA ──────────────────────────────────────────────────────────
        'iva_divisor' => [
            'tipo' => 'numero', 'defecto' => ComisionController::IVA, 'alcances' => ['general'],
            'nombre' => 'Divisor del IVA',
            'explica' => 'Por cuánto se divide el valor para quitarle el IVA (1,19 = IVA del 19 %).',
        ],
        'iva_en_venta' => [
            'tipo' => 'si_no', 'defecto' => true, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Quitar el IVA a las ventas',
            'explica' => 'Antes de sacar el porcentaje de una venta (y del pool) se le quita el IVA.',
        ],
        'iva_en_restauracion' => [
            'tipo' => 'si_no', 'defecto' => false, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Quitar el IVA a las restauraciones',
            'explica' => 'En la restauración se cobra mano de obra: hoy no se le quita el IVA.',
        ],

        // ── Datáfono ─────────────────────────────────────────────────────
        'franquicia_pct' => [
            'tipo' => 'fraccion', 'defecto' => ComisionController::COSTO_TARJETA, 'alcances' => ['general'],
            'nombre' => 'Lo que se queda la franquicia',
            'explica' => 'De lo pagado con estos medios, esta parte no le llega a la empresa y nadie comisiona sobre ella.',
        ],
        'franquicia_metodos' => [
            'tipo' => 'lista', 'defecto' => ['tarjeta', 'addi'], 'alcances' => ['general'],
            'nombre' => 'Medios de pago con franquicia',
            'explica' => 'Solo para comisiones. El descuento condicionado y Finanzas tienen su propia lista (Orden::METODOS_CON_FRANQUICIA).',
        ],

        // ── Cuándo se cobra ──────────────────────────────────────────────
        'req_pagado_pct' => [
            'tipo' => 'fraccion', 'defecto' => 0.5, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Lo que el cliente tiene que haber pagado para cobrar',
            'explica' => 'Parte de la orden entera que el cliente debe haber pagado para que la comisión de esa venta se pueda pagar.',
        ],
        'req_pagado_restauracion' => [
            'tipo' => 'si_no', 'defecto' => true, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Las restauraciones también esperan ese pago',
            'explica' => 'Dueño, 2026-10-23: las restauraciones esperan el 50 % pagado como las ventas.',
        ],
        'meta_req_pagado_pct' => [
            'tipo' => 'fraccion', 'defecto' => 0.5, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Lo que el cliente tiene que haber pagado para sumar a la meta',
            'explica' => 'Una venta suma a la meta y al pool de la tienda cuando el cliente pagó esta parte. En 0 cuenta todo lo vendido.',
        ],
        'dia_pago' => [
            'tipo' => 'entero', 'defecto' => 20, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Día de pago',
            'explica' => 'Día del mes en que la comisión queda disponible.',
        ],
        'meses_espera' => [
            'tipo' => 'entero', 'defecto' => 1, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Meses de espera',
            'explica' => 'Cuántos meses después de la venta (o del cierre del trimestre) llega su día de pago.',
        ],

        // ── Repartos ─────────────────────────────────────────────────────
        'compartida_parte_vendedor' => [
            'tipo' => 'fraccion', 'defecto' => 0.5, 'alcances' => ['general'],
            'nombre' => 'Parte de quien vendió en una venta compartida',
            'explica' => 'El resto es del covendedor.',
        ],
        'restauracion_se_reparte' => [
            'tipo' => 'si_no', 'defecto' => true, 'alcances' => ['general', 'tienda'],
            'nombre' => 'La restauración se reparte con el equipo',
            'explica' => 'En una tienda que comparte, el porcentaje de una restauración se parte entre los que estaban ese día.',
        ],

        // ── Independientes y almacenes ───────────────────────────────────
        'indep_pct_venta' => [
            'tipo' => 'fraccion', 'defecto' => ComisionIndependientes::PORCENTAJE, 'alcances' => ['general', 'usuario'],
            'nombre' => 'Porcentaje de un independiente sobre sus ventas',
            'explica' => 'Sobre lo suyo, sin IVA.',
        ],
        'indep_pct_bolson' => [
            'tipo' => 'fraccion', 'defecto' => ComisionIndependientes::PORCENTAJE, 'alcances' => ['general', 'usuario'],
            'nombre' => 'Porcentaje de cada independiente sobre el bolsón',
            'explica' => 'Todas las restauraciones del mes se juntan en un bolsón y cada independiente cobra este porcentaje del bolsón completo.',
        ],
        'bolson_incluye_almacenes' => [
            'tipo' => 'si_no', 'defecto' => true, 'alcances' => ['general'],
            'nombre' => 'El bolsón incluye las restauraciones de los almacenes',
            'explica' => 'Toda restauración le suma a los independientes, la suba quien la suba.',
        ],
        'almacen_pct' => [
            'tipo' => 'fraccion', 'defecto' => ComisionIndependientes::PORCENTAJE, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Porcentaje del almacén que ayudó a un independiente',
            'explica' => 'La gente del almacén se lo reparte.',
        ],
        'almacen_fraccion_meta' => [
            'tipo' => 'fraccion', 'defecto' => 0.5, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Parte de la venta que le suma a la meta del almacén',
            'explica' => 'Cuando un independiente cierra una venta con un contacto del almacén. Las restauraciones no suman a la meta.',
        ],
    ];

    /**
     * Lo que vale una regla para una comisión.
     *
     * @param string   $mes        'YYYY-MM': el mes de la venta (manda la regla vigente entonces)
     * @param int|null $tiendaId   la tienda a la que se le cuenta, si la regla se puede cambiar por tienda
     * @param int|null $usuarioId  la persona que cobra, si la regla se puede cambiar por persona
     */
    public static function valor(string $clave, string $mes, ?int $tiendaId = null, ?int $usuarioId = null): mixed
    {
        if (! isset(self::CATALOGO[$clave])) {
            throw new \InvalidArgumentException("Regla de comisión desconocida: {$clave}");
        }

        // Fase 1: aquí se buscará la fila más específica (persona > tienda >
        // general) vigente en $mes. Sin filas, el valor de siempre.
        return self::CATALOGO[$clave]['defecto'];
    }

    /** valor() como número. */
    public static function numero(string $clave, string $mes, ?int $tiendaId = null, ?int $usuarioId = null): float
    {
        return (float) self::valor($clave, $mes, $tiendaId, $usuarioId);
    }

    /** valor() como sí/no. */
    public static function si(string $clave, string $mes, ?int $tiendaId = null, ?int $usuarioId = null): bool
    {
        return (bool) self::valor($clave, $mes, $tiendaId, $usuarioId);
    }

    /**
     * Le quita el IVA a un valor si la regla de ese tipo lo pide.
     *
     * Se divide (no se multiplica por el inverso) para que la cifra salga
     * idéntica a la de siempre: `valor / 1,19`.
     */
    public static function sinIva(float $valor, bool $esRestauracion, string $mes, ?int $tiendaId = null): float
    {
        $quitar = self::si($esRestauracion ? 'iva_en_restauracion' : 'iva_en_venta', $mes, $tiendaId);

        return $quitar ? $valor / self::numero('iva_divisor', $mes, $tiendaId) : $valor;
    }

    /**
     * Una regla dentro de una consulta SQL que suma muchas filas, de
     * distintas tiendas y meses a la vez.
     *
     * Devuelve una expresión SQL. Hoy es la cifra de siempre, escrita como
     * literal; en la Fase 1, un CASE por tienda y mes con las filas de la
     * regla. Va escrita y no como parámetro (`?`) a propósito: MySQL trata
     * un literal como 0.055 como DECIMAL exacto y un parámetro como DOUBLE,
     * y `ROUND(100 * 0.055)` da 6 con el primero y 5 con el segundo.
     *
     * @param string $tiendaSql  la columna (o expresión) con la tienda, p. ej. 'c.tienda_id'
     * @param string $mesSql     la columna (o expresión) con el mes 'YYYY-MM'
     */
    public static function sql(string $clave, string $tiendaSql, string $mesSql): string
    {
        if (! isset(self::CATALOGO[$clave])) {
            throw new \InvalidArgumentException("Regla de comisión desconocida: {$clave}");
        }

        return self::literal((float) self::CATALOGO[$clave]['defecto']);
    }

    /**
     * Una regla de tipo lista dentro de SQL: "columna IN ('tarjeta','addi')".
     * Escrita con comillas de la base, como sql(); en la Fase 1 por mes.
     */
    public static function sqlEnLista(string $clave, string $columnaSql, string $mesSql): string
    {
        if (! isset(self::CATALOGO[$clave]) || self::CATALOGO[$clave]['tipo'] !== 'lista') {
            throw new \InvalidArgumentException("Regla de comisión sin lista: {$clave}");
        }

        $valores = (array) self::CATALOGO[$clave]['defecto'];
        if (! $valores) return '1 = 0';

        $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();

        return $columnaSql . ' IN (' . implode(',', array_map(fn ($v) => $pdo->quote((string) $v), $valores)) . ')';
    }

    /** Un número como literal SQL decimal: 0.055, 0.5, 20. Nunca notación científica. */
    public static function literal(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** Se vuelve a preguntar a la base (Fase 1: cuando las reglas vivan ahí). */
    public static function olvidarCache(): void
    {
    }
}
