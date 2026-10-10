<?php

namespace App\Services;

use App\Models\NominaConceptoEmpleador;
use App\Models\NominaConceptoTarifa;
use App\Models\NominaPago;
use App\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que la empresa paga por un trabajador ADEMÁS de lo que le entrega en
 * el ciclo: aportes del empleador y provisión de prestaciones.
 *
 * Va por detrás: no suma ni resta en el `total` del pago (eso sigue siendo
 * lo que recibe la persona), pero es plata que sale —a la planilla (PILA) cada
 * mes, y la prima, las cesantías y las vacaciones cuando se causan— y sin ella
 * el costo de la nómina se veía como lo que recibe la gente. El módulo de
 * Finanzas lo lee de aquí (docs/plan-gestion-financiera.md §3.3).
 *
 * TODO es personalizable (dueño, 2026-10-09), porque la ley cambia y no a
 * todos les toca lo mismo:
 *
 * - Los conceptos son filas (`nomina_conceptos_empleador`): se crean, se
 *   renombran, se desactivan. Si mañana sale un aporte nuevo, se agrega.
 * - El porcentaje tiene vigencia (`nomina_concepto_tarifas`): un cambio de ley
 *   se pone "desde el 1 de enero" y cada ciclo usa el que regía cuando cerró.
 * - Cada trabajador puede tener su excepción (`nomina_concepto_trabajador`):
 *   no se le paga pensión, o tiene la ARL del taller.
 *
 * Cómo se decide si un concepto le aplica a alguien:
 *   1. Si tiene excepción, manda la excepción.
 *   2. Si no, lo que diga el concepto (`aplica_por_defecto`); y los del grupo
 *      "aportes" solo si está afiliado (se le descuenta seguridad social): quien
 *      no aporta lo suyo tampoco tiene planilla del empleador.
 *
 * Las bases:
 *   salario          = sueldo devengado del ciclo (subtotal − faltas), y el bono
 *                      solo si la empresa dice que es salario
 *   salario_auxilio  = salario + auxilio de transporte devengado (prima, cesantías)
 *   concepto         = el valor de otro concepto (intereses = 12 % de las cesantías)
 *
 * La parte del TRABAJADOR (su 4 % de salud y 4 % de pensión) ya se le descuenta
 * en el pago (`descuento_seguridad_social`): no es costo extra de la empresa.
 */
class CostoEmpleador
{
    /** En `configuracion`: si el bono de producción entra a la base salarial. */
    public const CLAVE_BONO = 'nomina_bono_es_salario';

    /**
     * Los de ley 2026, iguales a los que siembra la migración. Solo rigen si
     * no existen las tablas (pruebas que arman el trabajador sin base): el
     * cálculo no puede tumbar una liquidación.
     */
    private const LEY_2026 = [
        // clave, nombre, grupo, base, base_concepto, %, por_defecto
        ['pension',             'Pensión',                'aportes',      'salario',         null,        12,    true],
        ['salud',               'Salud',                  'aportes',      'salario',         null,        8.5,   false],
        ['arl',                 'ARL',                    'aportes',      'salario',         null,        0.522, true],
        ['caja',                'Caja de compensación',   'aportes',      'salario',         null,        4,     true],
        ['icbf',                'ICBF',                   'aportes',      'salario',         null,        3,     false],
        ['sena',                'SENA',                   'aportes',      'salario',         null,        2,     false],
        ['prima',               'Prima de servicios',     'prestaciones', 'salario_auxilio', null,        8.33,  true],
        ['cesantias',           'Cesantías',              'prestaciones', 'salario_auxilio', null,        8.33,  true],
        ['intereses_cesantias', 'Intereses de cesantías', 'prestaciones', 'concepto',        'cesantias', 12,    true],
        ['vacaciones',          'Vacaciones',             'prestaciones', 'salario',         null,        4.17,  true],
    ];

    private static ?Collection $conceptos = null;
    private static ?bool $hayTablas = null;
    private static ?bool $bono = null;
    /** dePago() de los pagos sin costo congelado: Finanzas pasa por el mismo pago varias veces por petición. */
    private static array $porPago = [];

    public static function olvidarCache(): void
    {
        self::$conceptos = null;
        self::$hayTablas = null;
        self::$bono      = null;
        self::$porPago   = [];
    }

    public static function hayTablas(): bool
    {
        try {
            return self::$hayTablas ??= \App\Support\Esquema::tabla('nomina_conceptos_empleador')
                && \App\Support\Esquema::tabla('nomina_concepto_tarifas')
                && \App\Support\Esquema::tabla('nomina_concepto_trabajador');
        } catch (\Throwable) {
            return self::$hayTablas = false;
        }
    }

    /**
     * Los conceptos activos, en orden, con sus tarifas y la clave de su base.
     *
     * @return Collection<int, NominaConceptoEmpleador>
     */
    public static function conceptos(): Collection
    {
        if (self::$conceptos !== null) return self::$conceptos;

        if (self::hayTablas()) {
            return self::$conceptos = NominaConceptoEmpleador::with(['tarifas', 'baseConcepto:id,clave'])
                ->where('activo', true)
                ->orderBy('orden')->orderBy('id')
                ->get();
        }

        $porClave = [];
        foreach (self::LEY_2026 as $i => [$clave, $nombre, $grupo, $base, $baseClave, $pct, $porDefecto]) {
            $c = new NominaConceptoEmpleador(compact('clave', 'nombre', 'grupo', 'base') + [
                'aplica_por_defecto' => $porDefecto, 'activo' => true, 'orden' => $i + 1,
            ]);
            $c->setRelation('tarifas', collect([new NominaConceptoTarifa(['porcentaje' => $pct, 'desde' => '2026-01-01'])]));
            $c->setRelation('baseConcepto', $baseClave ? $porClave[$baseClave] : null);
            $porClave[$clave] = $c;
        }

        return self::$conceptos = collect(array_values($porClave));
    }

    public static function bonoEsSalario(): bool
    {
        if (self::$bono !== null) return self::$bono;

        try {
            $valor = \App\Support\Esquema::tabla('configuracion')
                ? DB::table('configuracion')->where('clave', self::CLAVE_BONO)->value('valor')
                : null;
        } catch (\Throwable) {
            $valor = null;
        }

        return self::$bono = $valor === '1';
    }

    public static function guardarBonoEsSalario(bool $si): void
    {
        DB::table('configuracion')->updateOrInsert(
            ['clave' => self::CLAVE_BONO],
            ['valor' => $si ? '1' : '0', 'updated_at' => now()]
        );
        self::$bono = $si;
    }

    /** Las excepciones de un trabajador, por concepto. */
    public static function excepcionesDe(Usuario $e): Collection
    {
        if ($e->relationLoaded('conceptosEmpleador')) {
            return $e->conceptosEmpleador->keyBy('concepto_id');
        }
        if (! $e->exists || ! self::hayTablas()) {
            return collect();
        }

        return $e->conceptosEmpleador()->get()->keyBy('concepto_id');
    }

    /**
     * Cómo le queda cada concepto a un trabajador en una fecha: si le aplica,
     * con qué porcentaje y si es lo de la empresa o una excepción suya. Es lo
     * que muestra su ficha y lo que usa el cálculo.
     */
    public static function conceptosDe(Usuario $e, CarbonInterface|string $fecha): array
    {
        $excepciones = self::excepcionesDe($e);

        return self::conceptos()->map(function (NominaConceptoEmpleador $c) use ($e, $fecha, $excepciones) {
            $exc        = $c->id ? $excepciones->get($c->id) : null;
            $porDefecto = $c->aplica_por_defecto && ($c->grupo !== 'aportes' || $e->aportaSeguridadSocial());
            $empresa    = $c->porcentajeEn($fecha);

            return [
                'concepto_id'          => $c->id,
                'clave'                => $c->clave,
                'nombre'               => $c->nombre,
                'grupo'                => $c->grupo,
                'base'                 => $c->base,
                'base_clave'           => $c->baseConcepto?->clave,
                'aplica'               => $exc ? (bool) $exc->aplica : $porDefecto,
                'aplica_por_defecto'   => $porDefecto,
                'porcentaje'           => $exc && $exc->porcentaje !== null ? (float) $exc->porcentaje : $empresa,
                'porcentaje_empresa'   => $empresa,
                'personalizado'        => (bool) $exc,
                'excepcion_aplica'     => $exc ? (bool) $exc->aplica : null,
                'excepcion_porcentaje' => $exc && $exc->porcentaje !== null ? (float) $exc->porcentaje : null,
            ];
        })->values()->all();
    }

    /**
     * El costo del empleador de una liquidación (NominaLiquidador::liquidar).
     * Los porcentajes son los que rigen el día que cierra el ciclo.
     *
     * @param array $l  subtotal, descuento_faltas, auxilio_transporte,
     *                  descuento_incapacidad y bonificacion del ciclo
     */
    public static function deLiquidacion(Usuario $empleado, array $l, CarbonInterface|string $fecha): array
    {
        return self::desglose(
            $empleado, $fecha,
            subtotal: (float) $l['subtotal'],
            faltas:   (float) $l['descuento_faltas'],
            auxilio:  (float) $l['auxilio_transporte'] - (float) $l['descuento_incapacidad'],
            bono:     (float) ($l['bonificacion'] ?? 0),
        );
    }

    /**
     * El costo del empleador de un pago ya hecho. Si se congeló al pagarlo,
     * ese; los pagos de antes de que existiera esta cuenta se calculan con su
     * desglose congelado y lo de hoy del trabajador (y lo dicen).
     */
    /**
     * Lo que hay que cargar (con `with`) en una lista de pagos antes de llamar
     * dePago() en cada uno. Los pagos de antes del 2026-10-19 no tienen el
     * costo congelado y se calculan con el trabajador y sus excepciones: sin
     * esto eran dos consultas por pago (~1.000 pagos en producción → miles de
     * consultas, y /finanzas/resumen se caía con 502).
     */
    public static function relacionesDePago(string ...$extra): array
    {
        $con = self::hayTablas() ? ['trabajador.conceptosEmpleador'] : ['trabajador'];

        return array_merge($con, $extra);
    }

    public static function dePago(NominaPago $p): array
    {
        if ($p->costo_empleador_detalle) {
            return $p->costo_empleador_detalle;
        }

        $clave = $p->id ? $p->id . '@' . $p->updated_at : null;
        if ($clave && isset(self::$porPago[$clave])) {
            return self::$porPago[$clave];
        }

        return $clave ? self::$porPago[$clave] = self::calcularDePago($p) : self::calcularDePago($p);
    }

    private static function calcularDePago(NominaPago $p): array
    {
        $t = $p->relationLoaded('trabajador') ? $p->trabajador : $p->trabajador()->first();
        if (! $t) {
            return self::vacio() + ['calculado_despues' => true];
        }

        return self::desglose(
            $t, $p->fecha_fin,
            subtotal: (float) $p->subtotal,
            faltas:   (float) $p->descuento_faltas,
            auxilio:  (float) $p->auxilio_transporte - (float) $p->descuento_incapacidad,
            bono:     (float) $p->bonificacion,
        ) + ['calculado_despues' => true];
    }

    private static function desglose(Usuario $e, CarbonInterface|string $fecha, float $subtotal, float $faltas, float $auxilio, float $bono): array
    {
        $salario = max(0.0, $subtotal - $faltas) + (self::bonoEsSalario() ? $bono : 0.0);
        $bases   = ['salario' => $salario, 'salario_auxilio' => $salario + max(0.0, $auxilio)];

        $todos     = self::conceptosDe($e, $fecha);
        $conceptos = collect($todos)->where('aplica', true);

        // Primero los que van sobre el sueldo y después los que van sobre otro
        // concepto (los intereses necesitan las cesantías ya calculadas).
        $montos = [];
        $lineas = [];
        foreach ([false, true] as $sobreConcepto) {
            foreach ($conceptos as $c) {
                if (($c['base'] === 'concepto') !== $sobreConcepto) continue;

                $base  = $sobreConcepto ? (float) ($montos[$c['base_clave']] ?? 0) : (float) ($bases[$c['base']] ?? 0);
                $monto = round($base * $c['porcentaje'] / 100);
                $montos[$c['clave']] = $monto;

                if ($monto > 0) {
                    $lineas[] = [
                        'clave'         => $c['clave'],
                        'nombre'        => $c['nombre'],
                        'grupo'         => $c['grupo'],
                        'porcentaje'    => $c['porcentaje'],
                        'base'          => round($base),
                        'monto'         => $monto,
                        'personalizado' => $c['personalizado'],
                    ];
                }
            }
        }

        // En el orden de los conceptos, no en el del cálculo.
        $orden  = array_flip(array_column($todos, 'clave'));
        usort($lineas, fn ($a, $b) => $orden[$a['clave']] <=> $orden[$b['clave']]);

        $suma = fn (string $g) => (float) array_sum(array_column(
            array_filter($lineas, fn ($l) => $l['grupo'] === $g), 'monto'
        ));

        return [
            'fecha_tarifas'     => $fecha instanceof CarbonInterface ? $fecha->toDateString() : substr((string) $fecha, 0, 10),
            'base_salarial'     => round($bases['salario']),
            'base_prestaciones' => round($bases['salario_auxilio']),
            'lineas'            => $lineas,
            'aportes'           => $suma('aportes'),
            'prestaciones'      => $suma('prestaciones'),
            'otros'             => $suma('otros'),
            'total'             => (float) array_sum(array_column($lineas, 'monto')),
        ];
    }

    private static function vacio(): array
    {
        return [
            'fecha_tarifas' => null, 'base_salarial' => 0, 'base_prestaciones' => 0, 'lineas' => [],
            'aportes' => 0.0, 'prestaciones' => 0.0, 'otros' => 0.0, 'total' => 0.0,
        ];
    }
}
