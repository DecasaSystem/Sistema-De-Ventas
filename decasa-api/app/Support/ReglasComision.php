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
 * Fase 0: el cálculo ya no tiene cifras propias, las pide aquí. Sin cambiar
 * nada, cada regla vale lo mismo que valía en el código (comprobado con la
 * prueba diferencial: respuestas idénticas byte a byte).
 *
 * Fase 1: cada regla puede tener filas en `comision_reglas` con "desde qué
 * mes" y por alcance —persona > tienda > general—, como los conceptos de
 * `CostoEmpleador`. Por eso quien pregunta dice SIEMPRE de qué mes es la
 * comisión y, si aplica, de qué tienda y de qué persona: manda la regla
 * vigente en el mes de la venta, no la de hoy, y cambiar una regla desde
 * noviembre no mueve agosto. Sin filas, todo da lo de siempre.
 *
 * En cada nivel manda la última fila hasta ese mes. Una fila con valor nulo
 * en una tienda o una persona quiere decir "desde aquí, sin excepción":
 * vuelve a regir el nivel de arriba.
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
     * tipo: 'fraccion' (0,05 = 5 %), 'numero', 'entero', 'si_no', 'lista', y
     * 'opcion': una forma de calcular escogida de una lista cerrada que el
     * código sabe hacer (`opciones`: valor => [nombre, explica]). La primera
     * opción es siempre lo que hacía el código antes de poder escoger.
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
        'reparto_pool' => [
            'tipo' => 'opcion', 'defecto' => 'dias', 'alcances' => ['general', 'tienda'],
            'nombre' => 'Cómo se reparte el pool',
            'explica' => 'Entre los del equipo de la tienda (con quien llegó a cubrir).',
            'opciones' => [
                'dias'    => ['nombre' => 'Por días',
                    'explica' => 'Cada uno se lleva según los días que estuvo en la tienda. Si nadie faltó, todos lo mismo.'],
                'iguales' => ['nombre' => 'Partes iguales',
                    'explica' => 'Todos los que estuvieron ese mes se llevan lo mismo, aunque uno haya estado menos días.'],
            ],
        ],
        'restauracion_suma_meta' => [
            'tipo' => 'si_no', 'defecto' => false, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Las restauraciones cuentan como ventas',
            'explica' => 'Encendido, en esa tienda una restauración es una venta más: suma a la meta, entra al pool '
                . '(sin IVA, como toda venta del pool) y ya no se paga aparte con su porcentaje ni se reparte por días. '
                . 'Al bolsón de los independientes entra igual que siempre.',
        ],
        'arrastra_deuda_trimestre' => [
            'tipo' => 'si_no', 'defecto' => true, 'alcances' => ['general', 'tienda'],
            'nombre' => 'La deuda del trimestre pasa al siguiente',
            'explica' => 'En las tiendas trimestrales: si un trimestre no llega a la meta, lo que faltó se descuenta del pool del siguiente. Apagado, cada trimestre arranca de cero. Rige el último mes del trimestre que empieza.',
        ],
        'compartida_parte_vendedor' => [
            'tipo' => 'fraccion', 'defecto' => 0.5, 'alcances' => ['general', 'tienda', 'usuario'],
            'nombre' => 'Parte de quien vendió en una venta compartida',
            'explica' => 'El resto es del covendedor. Por tienda: la tienda a la que cuenta la venta. Por persona: cuando esa persona es la que registró la venta.',
        ],
        'restauracion_se_reparte' => [
            'tipo' => 'si_no', 'defecto' => true, 'alcances' => ['general', 'tienda'],
            'nombre' => 'La restauración se reparte con el equipo',
            'explica' => 'En una tienda que comparte, el porcentaje de una restauración se parte entre los que estaban ese día.',
        ],
        'reparto_restauracion' => [
            'tipo' => 'opcion', 'defecto' => 'iguales', 'alcances' => ['general', 'tienda'],
            'nombre' => 'Cómo se reparte la restauración',
            'explica' => 'Cuando se reparte con el equipo: entre los que estaban en la tienda ese día.',
            'opciones' => [
                'iguales'       => ['nombre' => 'Partes iguales',
                    'explica' => 'Todos los que estaban ese día se llevan lo mismo, también quien la hizo.'],
                'autor_primero' => ['nombre' => 'Quien la hizo primero',
                    'explica' => 'Quien la hizo se lleva su parte (la regla de abajo) y el resto se reparte igual entre los demás.'],
            ],
        ],
        'parte_autor_restauracion' => [
            'tipo' => 'fraccion', 'defecto' => 0.5, 'alcances' => ['general', 'tienda'],
            'nombre' => 'Parte de quien hizo la restauración',
            'explica' => 'Solo con "Quien la hizo primero": qué parte de la comisión de la restauración es suya.',
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
        'bolson_modo' => [
            'tipo' => 'opcion', 'defecto' => 'cada_uno_completo', 'alcances' => ['general'],
            'nombre' => 'Cómo se cobra el bolsón',
            'explica' => 'Entre los independientes que entran al bolsón de restauraciones.',
            'opciones' => [
                'cada_uno_completo' => ['nombre' => 'Cada uno, el bolsón completo',
                    'explica' => 'Cada independiente cobra su porcentaje del bolsón entero. Con dos, la empresa paga el doble.'],
                'se_reparte' => ['nombre' => 'Se reparte entre ellos',
                    'explica' => 'El porcentaje del bolsón se parte en partes iguales entre los que entran.'],
            ],
        ],
        'entra_al_bolson' => [
            'tipo' => 'si_no', 'defecto' => true, 'alcances' => ['general', 'usuario'],
            'nombre' => 'Entra al bolsón de restauraciones',
            'explica' => 'Si un independiente cobra del bolsón. Sacarlo no le quita "independiente" (caja propia, lo que ve).',
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

    /** Cómo se agrupan en la pantalla (Comisiones → Reglas). Cada regla va en uno. */
    public const GRUPOS = [
        'porcentajes'     => ['titulo' => 'Porcentajes',               'claves' => ['pct_pool', 'pct_individual', 'pct_restauracion']],
        'iva'             => ['titulo' => 'IVA',                       'claves' => ['iva_divisor', 'iva_en_venta', 'iva_en_restauracion']],
        'datafono'        => ['titulo' => 'Datáfono',                  'claves' => ['franquicia_pct', 'franquicia_metodos']],
        'cobro'           => ['titulo' => 'Cuándo se cobra',           'claves' => ['req_pagado_pct', 'req_pagado_restauracion', 'meta_req_pagado_pct', 'dia_pago', 'meses_espera']],
        'pool'            => ['titulo' => 'Pool de la tienda',         'claves' => ['reparto_pool', 'restauracion_suma_meta', 'arrastra_deuda_trimestre']],
        'repartos'        => ['titulo' => 'Repartos',                  'claves' => ['compartida_parte_vendedor', 'restauracion_se_reparte', 'reparto_restauracion', 'parte_autor_restauracion']],
        'independientes'  => ['titulo' => 'Independientes y almacenes', 'claves' => ['indep_pct_venta', 'indep_pct_bolson', 'bolson_modo', 'entra_al_bolson', 'bolson_incluye_almacenes', 'almacen_pct', 'almacen_fraccion_meta']],
    ];

    /**
     * Reglas que cambian algo GUARDADO en cada renglón (la base de la venta en
     * `valor_orden`, la fecha en que se cobra): el simulador no las puede
     * mostrar al instante —habría que reescribir cada orden— y se ven después
     * del recálculo.
     */
    public const SE_VEN_AL_RECALCULAR = [
        'franquicia_pct', 'franquicia_metodos', 'compartida_parte_vendedor', 'almacen_fraccion_meta',
        'dia_pago', 'meses_espera',
        // El reparto de la restauración entre el equipo queda escrito por persona.
        'restauracion_suma_meta', 'reparto_restauracion', 'parte_autor_restauracion',
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

        $filas = self::filas()[$clave] ?? null;
        if (! $filas) return self::CATALOGO[$clave]['defecto'];

        // La última fila de cada nivel hasta ese mes (vienen de la más vieja
        // a la más nueva). Un nivel que la regla no admite no cuenta.
        $alcances = self::CATALOGO[$clave]['alcances'];
        $nivel = ['usuario' => null, 'tienda' => null, 'general' => null];
        foreach ($filas as $f) {
            if ($f->desde_mes > $mes) break;
            if (! in_array($f->alcance, $alcances, true)) continue;

            if ($f->alcance === 'general'
                || ($f->alcance === 'tienda' && $tiendaId !== null && (int) $f->alcance_id === $tiendaId)
                || ($f->alcance === 'usuario' && $usuarioId !== null && (int) $f->alcance_id === $usuarioId)) {
                $nivel[$f->alcance] = $f;
            }
        }

        // Gana la más específica; nula = "sin excepción", sigue la de arriba.
        foreach ($nivel as $f) {
            if ($f !== null && $f->valor !== null) return $f->valor;
        }

        return self::CATALOGO[$clave]['defecto'];
    }

    /**
     * Todas las reglas que rigen para una comisión, para guardarlas con el
     * pago (`comisiones.reglas_usadas`): así se sabe después con qué se pagó
     * aunque las reglas cambien.
     *
     * @return array<string, mixed>
     */
    public static function foto(string $mes, ?int $tiendaId = null, ?int $usuarioId = null): array
    {
        $out = [];
        foreach (array_keys(self::CATALOGO) as $clave) {
            $out[$clave] = self::valor($clave, $mes, $tiendaId, $usuarioId);
        }

        return $out;
    }

    /** Hay al menos una regla cambiada (para no hacer trabajo de más). */
    public static function hayCambios(): bool
    {
        return self::filas() !== [];
    }

    /** Esa regla tiene alguna fila, o alguna de ese nivel: si no, no hace falta averiguar la tienda o la persona. */
    public static function hayFilas(string $clave, ?string $alcance = null): bool
    {
        foreach (self::filas()[$clave] ?? [] as $f) {
            if ($alcance === null || $f->alcance === $alcance) return true;
        }

        return false;
    }

    /**
     * Un valor como lo guarda la regla, según su tipo: 0,05 como fracción, 20
     * como entero, sí/no, una lista de textos. Null si no sirve para esa regla.
     */
    public static function normalizar(string $clave, mixed $valor): mixed
    {
        $tipo = self::CATALOGO[$clave]['tipo'] ?? null;

        return match ($tipo) {
            'fraccion', 'numero' => is_numeric($valor) ? (float) $valor : null,
            'entero'             => is_numeric($valor) && (float) $valor == (int) $valor ? (int) $valor : null,
            'si_no'              => is_bool($valor) ? $valor : (in_array($valor, [0, 1, '0', '1'], true) ? (bool) $valor : null),
            'lista'              => is_array($valor) ? array_values(array_unique(array_map('strval', $valor))) : null,
            'opcion'             => is_string($valor) && isset(self::CATALOGO[$clave]['opciones'][$valor]) ? $valor : null,
            default              => null,
        };
    }

    /**
     * Las filas de `comision_reglas`, por clave y de la más vieja a la más
     * nueva, con el valor ya convertido a su tipo. Se leen una vez por
     * petición (ver CachesDePeticion).
     *
     * @return array<string, array<int, object>>
     */
    private static function filas(): array
    {
        if (self::$filas !== null) return self::$filas;
        if (! Esquema::tabla('comision_reglas')) return self::$filas = [];

        $out = [];
        foreach (\Illuminate\Support\Facades\DB::table('comision_reglas')
                     ->orderBy('desde_mes')->orderBy('id')->get() as $f) {
            if (! isset(self::CATALOGO[$f->clave])) continue;
            $crudo = $f->valor === null ? null : json_decode((string) $f->valor, true);
            $f->valor = $crudo === null ? null : self::normalizar($f->clave, $crudo);
            $out[$f->clave][] = $f;
        }

        return self::$filas = $out;
    }

    /** [clave => filas]. Ver filas(). */
    private static ?array $filas = null;

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
     * Devuelve una expresión SQL: sin reglas cambiadas, la cifra de siempre
     * escrita como literal (0.5); con reglas, un CASE por tienda y mes con la
     * misma prioridad que valor() (tienda sobre general, la última fila hasta
     * ese mes). Va escrita y no como parámetro (`?`) a propósito: MySQL trata
     * un literal como 0.055 como DECIMAL exacto y un parámetro como DOUBLE,
     * y `ROUND(100 * 0.055)` da 6 con el primero y 5 con el segundo.
     *
     * Solo para reglas sin nivel de persona: una suma de muchas órdenes no
     * sabe de quién es cada una.
     *
     * @param string $tiendaSql  la columna (o expresión) con la tienda, p. ej. 'c.tienda_id'
     * @param string $mesSql     la columna (o expresión) con el mes 'YYYY-MM'
     */
    public static function sql(string $clave, string $tiendaSql, string $mesSql): string
    {
        // Un sí/no entra como 1 o 0; una lista o una opción no tienen número.
        if (! isset(self::CATALOGO[$clave]) || in_array(self::CATALOGO[$clave]['tipo'], ['lista', 'opcion'], true)) {
            throw new \InvalidArgumentException("Regla de comisión sin número: {$clave}");
        }

        $defecto = self::literal((float) self::CATALOGO[$clave]['defecto']);
        $filas   = self::filas()[$clave] ?? [];
        if (! $filas) return $defecto;

        $alcances = self::CATALOGO[$clave]['alcances'];
        $num = fn ($v) => $v === null ? null : self::literal((float) $v);

        $general = self::casePorMes(
            array_filter($filas, fn ($f) => $f->alcance === 'general'), $mesSql, $num, $defecto
        );

        $cuando = [];
        if (in_array('tienda', $alcances, true)) {
            $porTienda = [];
            foreach ($filas as $f) {
                if ($f->alcance === 'tienda') $porTienda[(int) $f->alcance_id][] = $f;
            }
            foreach ($porTienda as $tid => $suyas) {
                foreach (array_reverse($suyas) as $f) {
                    $cuando[] = "WHEN {$tiendaSql} = {$tid} AND {$mesSql} >= " . self::texto($f->desde_mes)
                        . ' THEN ' . ($num($f->valor) ?? $general);
                }
            }
        }

        return $cuando ? '(CASE ' . implode(' ', $cuando) . " ELSE {$general} END)" : $general;
    }

    /**
     * Una regla de tipo lista dentro de SQL: "columna IN ('tarjeta','addi')",
     * con la lista que regía en cada mes. Solo nivel general.
     */
    public static function sqlEnLista(string $clave, string $columnaSql, string $mesSql): string
    {
        if (! isset(self::CATALOGO[$clave]) || self::CATALOGO[$clave]['tipo'] !== 'lista') {
            throw new \InvalidArgumentException("Regla de comisión sin lista: {$clave}");
        }

        $enLista = function (?array $valores) use ($columnaSql): ?string {
            if ($valores === null) return null;
            if (! $valores) return '1 = 0';

            return '(' . $columnaSql . ' IN (' . implode(',', array_map(fn ($v) => self::texto((string) $v), $valores)) . '))';
        };

        $defecto = $enLista((array) self::CATALOGO[$clave]['defecto']);
        $filas   = array_filter(self::filas()[$clave] ?? [], fn ($f) => $f->alcance === 'general');
        if (! $filas) {
            // Sin cambios, igual que siempre: sin paréntesis de más.
            $valores = (array) self::CATALOGO[$clave]['defecto'];
            return $valores ? $columnaSql . ' IN (' . implode(',', array_map(fn ($v) => self::texto((string) $v), $valores)) . ')' : '1 = 0';
        }

        return self::casePorMes($filas, $mesSql, $enLista, $defecto);
    }

    /**
     * CASE WHEN mes >= 'desde' THEN valor … ELSE defecto END, de la fila más
     * nueva a la más vieja. Una fila nula vuelve al valor de siempre.
     */
    private static function casePorMes(array $filas, string $mesSql, callable $comoSql, string $defecto): string
    {
        if (! $filas) return $defecto;

        $cuando = [];
        foreach (array_reverse(array_values($filas)) as $f) {
            $cuando[] = "WHEN {$mesSql} >= " . self::texto($f->desde_mes) . ' THEN ' . ($comoSql($f->valor) ?? $defecto);
        }

        return '(CASE ' . implode(' ', $cuando) . " ELSE {$defecto} END)";
    }

    /** Un texto como literal SQL, con las comillas de la base. */
    private static function texto(string $v): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getPdo()->quote($v);
    }

    /** Un número como literal SQL decimal: 0.055, 0.5, 20. Nunca notación científica. */
    public static function literal(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** Se vuelve a preguntar a la base: alguien cambió una regla. */
    public static function olvidarCache(): void
    {
        self::$filas = null;
    }
}
