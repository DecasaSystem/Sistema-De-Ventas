<?php

namespace App\Services;

use App\Models\Comision;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los anticipos de comisión: lo que un vendedor se lleva cada mes por
 * adelantado y se le descuenta cuando se le paga la comisión.
 *
 * Es un libro, no un campo: cada mes queda un renglón `anticipo` con lo que se
 * llevó, y cada vez que se le paga una comisión queda un renglón `descuento`
 * atado a esa comisión. Lo que debe es la resta de los dos:
 *
 *     debe = anticipos hasta el mes que se le paga − lo ya descontado
 *
 * El "hasta el mes que se le paga" importa: la comisión de septiembre se paga
 * el 20 de octubre, y para entonces ya se llevó el anticipo de octubre, que
 * es de la comisión de octubre, no de esta. En una tienda trimestral el corte
 * es el último mes del trimestre: la comisión de Jul–Sep descuenta los tres
 * anticipos (3 × $200.000 = $600.000).
 *
 * Si la comisión no alcanza —o no comisiona— se descuenta lo que haya y el
 * resto lo sigue debiendo para el pago siguiente. La comisión bruta no se
 * toca: el descuento va aparte, para poder ver las dos cosas.
 */
class AnticiposComision
{
    public const ANTICIPO  = 'anticipo';
    public const DESCUENTO = 'descuento';

    /** La base tiene las tablas (los tests que no las montan siguen andando). */
    public static function hayTablas(): bool
    {
        return self::$hayTablas ??= Schema::hasTable('comision_anticipos')
            && Schema::hasTable('comision_anticipos_config');
    }

    private static ?bool $hayTablas = null;

    public static function olvidarEsquema(): void
    {
        self::$hayTablas      = null;
        self::$aseguradoHasta = null;
    }

    /** Hasta qué mes ya se anotaron los anticipos en este proceso. */
    private static ?string $aseguradoHasta = null;

    public static function claveDe(int $vendedorId, string $mes): string
    {
        return "anticipo:{$vendedorId}:{$mes}";
    }

    /**
     * La configuración que rige en un mes para cada vendedor que alguna vez
     * tuvo anticipo: la última puesta hasta ese mes.
     *
     * @return array<int, object>  [vendedor_id => fila]
     */
    public static function vigentesEn(string $mes): array
    {
        if (! self::hayTablas()) return [];

        $out = [];
        foreach (DB::table('comision_anticipos_config')->where('desde_mes', '<=', $mes)
                     ->orderBy('desde_mes')->orderBy('id')->get() as $c) {
            $out[(int) $c->vendedor_id] = $c;
        }

        return $out;
    }

    /**
     * Deja escrito el anticipo de cada mes, desde que se activó hasta `$hasta`.
     *
     * Idempotente: lo puede llamar la pantalla, el pago o la tarea de cada
     * mañana. Lo que alguien corrigió a mano en un mes no se pisa. Un mes en
     * que la configuración ya no está activa (o quedó en $0) no lleva
     * anticipo: si había uno puesto solo, se quita.
     */
    public static function asegurarHasta(string $hasta): void
    {
        if (! self::hayTablas()) return;

        $configs = DB::table('comision_anticipos_config')->where('desde_mes', '<=', $hasta)
            ->orderBy('desde_mes')->orderBy('id')->get()->groupBy('vendedor_id');

        foreach ($configs as $vendedorId => $filas) {
            $existentes = DB::table('comision_anticipos')
                ->where('vendedor_id', $vendedorId)->where('tipo', self::ANTICIPO)
                ->get()->keyBy('mes');

            $mes = Carbon::parse($filas->first()->desde_mes . '-01');
            $fin = Carbon::parse($hasta . '-01');

            while ($mes->lte($fin)) {
                $m       = $mes->format('Y-m');
                $vigente = $filas->filter(fn ($f) => $f->desde_mes <= $m)->last();
                $monto   = ($vigente && $vigente->activo) ? round((float) $vigente->monto) : 0.0;
                $ya      = $existentes[$m] ?? null;

                if ($ya && $ya->editado_a_mano) {
                    // Corregido a mano: manda lo que se escribió.
                } elseif ($monto > 0 && ! $ya) {
                    try {
                        DB::table('comision_anticipos')->insert([
                            'vendedor_id' => $vendedorId, 'tipo' => self::ANTICIPO, 'mes' => $m,
                            'monto' => $monto, 'clave' => self::claveDe((int) $vendedorId, $m),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        // Lo escribió otro al mismo tiempo.
                    }
                } elseif ($monto > 0 && abs((float) $ya->monto - $monto) >= 0.01) {
                    DB::table('comision_anticipos')->where('id', $ya->id)
                        ->update(['monto' => $monto, 'updated_at' => now()]);
                } elseif ($monto <= 0 && $ya) {
                    DB::table('comision_anticipos')->where('id', $ya->id)->delete();
                }

                $mes->addMonth();
            }
        }
    }

    /** Lo que se ha llevado de anticipo hasta ese mes (incluido). */
    public static function anticiposHasta(int $vendedorId, string $mes): float
    {
        if (! self::hayTablas()) return 0.0;

        // Sin depender de que alguien haya abierto la pantalla o de que haya
        // corrido la tarea del día: antes de contar, se anota lo que falte.
        $hoy = Carbon::now(\App\Http\Controllers\StatsController::TZ_NEGOCIO)->format('Y-m');
        $hasta = max($mes, $hoy);
        if ((self::$aseguradoHasta ?? '') < $hasta) {
            self::asegurarHasta($hasta);
            self::$aseguradoHasta = $hasta;
        }

        return (float) DB::table('comision_anticipos')->where('vendedor_id', $vendedorId)
            ->where('tipo', self::ANTICIPO)->where('mes', '<=', $mes)->sum('monto');
    }

    /** Lo que ya se le descontó de comisiones. */
    public static function descontado(int $vendedorId): float
    {
        if (! self::hayTablas()) return 0.0;

        return (float) DB::table('comision_anticipos')->where('vendedor_id', $vendedorId)
            ->where('tipo', self::DESCUENTO)->sum('monto');
    }

    /** Lo que debe de anticipos que corresponden a comisiones hasta ese mes. */
    public static function debeHasta(int $vendedorId, string $mes): float
    {
        return max(0.0, round(self::anticiposHasta($vendedorId, $mes) - self::descontado($vendedorId)));
    }

    /**
     * Descuenta de una comisión que se acaba de pagar lo que debe de
     * anticipos, hasta donde alcance. Devuelve cuánto se descontó.
     *
     * @param string $corte  hasta qué mes de anticipos le corresponde a esta comisión
     */
    public static function descontar(Comision $c, float $montoPagado, string $corte, ?int $usuarioId): float
    {
        if (! self::hayTablas() || $montoPagado <= 0) return 0.0;

        self::asegurarHasta(max($corte, Carbon::now(\App\Http\Controllers\StatsController::TZ_NEGOCIO)->format('Y-m')));

        $descuento = min(self::debeHasta((int) $c->vendedor_id, $corte), round($montoPagado));
        if ($descuento <= 0) return 0.0;

        DB::table('comision_anticipos')->insert([
            'vendedor_id' => $c->vendedor_id, 'tipo' => self::DESCUENTO, 'mes' => $c->mes_venta,
            'monto' => $descuento, 'comision_id' => $c->id, 'creado_por' => $usuarioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $descuento;
    }

    /** Si el pago de esa comisión se deshace, lo que se le descontó vuelve a deberse. */
    public static function revertir(Comision $c): float
    {
        if (! self::hayTablas()) return 0.0;

        $q = DB::table('comision_anticipos')->where('tipo', self::DESCUENTO)->where('comision_id', $c->id);
        $monto = (float) $q->sum('monto');
        $q->delete();

        return $monto;
    }

    /** Lo descontado de unas comisiones (para mostrar el neto de un pago). */
    public static function descontadoDe(array $comisionIds): float
    {
        if (! self::hayTablas() || ! $comisionIds) return 0.0;

        return (float) DB::table('comision_anticipos')->where('tipo', self::DESCUENTO)
            ->whereIn('comision_id', $comisionIds)->sum('monto');
    }
}
