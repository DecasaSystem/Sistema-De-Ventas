<?php

namespace App\Services;

use App\Http\Controllers\ComisionController;
use App\Http\Controllers\StatsController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\OrdenEdicion;
use App\Models\Usuario;
use App\Services\Finanzas\Cierres;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambiar la fecha de una venta (usuario, 2026-11-20).
 *
 * El caso: el vendedor vendió el 31 y la subió el 1 del mes siguiente, así que
 * la venta cuenta para el mes que no es. Antes se arreglaba con una migración
 * por orden (la #4423, 2026_11_07_000001). Ahora lo hace un super admin desde
 * el detalle de la orden, por esta única puerta.
 *
 * La fecha de la venta es `ordenes.created_at`: de ahí, en hora de Bogotá, la
 * leen reportes, estadísticas, metas, Finanzas, cartera y el mes de la
 * bonificación. Todo eso se calcula al pedirlo, así que se acomoda solo. Lo
 * único guardado con la fecha son los renglones de `comisiones` (mes de venta,
 * fecha de venta, día de pago): esos se mueven en su sitio, no se borran
 * (`comision_anticipos.comision_id` borra en cascada) y luego se pide el
 * recálculo de todo, que pone al día la meta y el pool de los dos meses.
 *
 * Lo que NO cambia: el número (es el consecutivo que le tocó), los pagos (la
 * plata entró cuando entró), `confirmada_en` (cuándo se subió de verdad: el
 * orden de la lista y los días para editar siguen igual) y la tienda del
 * vendedor de una venta digital.
 *
 * Con la bonificación de esta orden ya pagada no se deja: esa plata salió con
 * el mes viejo. Si la venta se va de un mes cuyo pool ya se pagó, lo cobrado de
 * más se descuenta del próximo pago (BonificacionCobradaDeMas); si llega a un
 * mes ya pagado, la diferencia sale sola en Listas (le toca − pagado).
 */
class CambiarFechaDeVenta
{
    /**
     * @return array{orden: Orden, avisos: string[]}
     */
    public static function aplicar(Orden $orden, string $fecha, Usuario $usuario, ?string $motivo = null): array
    {
        $tz = StatsController::TZ_NEGOCIO;

        if (in_array($orden->estado, Orden::ESTADOS_NO_COMERCIALES, true)) {
            throw ValidationException::withMessages(['fecha' => 'Una cotización o un borrador todavía no es venta: no tiene fecha de venta que cambiar.']);
        }

        $nueva = Carbon::createFromFormat('Y-m-d', $fecha, $tz)->startOfDay();
        // Como texto: RangoFechas::hoy() es medianoche UTC y $nueva medianoche
        // en Bogotá; comparar instantes rechazaría el día de hoy.
        if ($nueva->toDateString() > RangoFechas::hoyTexto()) {
            throw ValidationException::withMessages(['fecha' => 'La venta no puede quedar con una fecha que todavía no llega.']);
        }

        $antes = Carbon::parse($orden->created_at)->setTimezone($tz);
        if ($antes->toDateString() === $nueva->toDateString()) {
            throw ValidationException::withMessages(['fecha' => 'La venta ya tiene esa fecha.']);
        }

        if (Comision::where('orden_id', $orden->id)->where('estado', 'pagada')->exists()) {
            throw ValidationException::withMessages(['fecha' => 'La bonificación de esta venta ya se pagó con su mes: no se puede mover de fecha. Ese ajuste se hace en la liquidación.']);
        }

        $mesAntes = $antes->format('Y-m');
        $mesNuevo = $nueva->format('Y-m');

        DB::transaction(function () use ($orden, $nueva, $antes, $usuario, $motivo, $mesAntes, $mesNuevo) {
            // Foto del mes de donde sale, por si su pool ya se pagó.
            if ($mesAntes !== $mesNuevo) {
                BonificacionCobradaDeMas::alMoverDeMes($orden, $usuario);
            }

            // A mediodía en Bogotá: lejos de la medianoche, ninguna cuenta en
            // UTC la corre de día. Por consulta directa: nada más de la orden cambia.
            $utc = $nueva->copy()->setTime(12, 0)->utc();
            DB::table('ordenes')->where('id', $orden->id)->update(['created_at' => $utc]);
            $orden->forceFill(['created_at' => $utc])->syncOriginalAttributes(['created_at']);

            OrdenEdicion::create([
                'orden_id'   => $orden->id,
                'usuario_id' => $usuario->id,
                'cambios'    => [[
                    'campo'   => 'created_at',
                    'label'   => 'Fecha de la venta' . ($motivo ? " ({$motivo})" : ''),
                    'antes'   => $antes->format('d/m/Y'),
                    'despues' => $nueva->format('d/m/Y'),
                ]],
            ]);

            // Los renglones de la bonificación con la fecha nueva. El día de
            // pago con la misma cuenta que al crearla (mensual o trimestral).
            foreach (Comision::where('orden_id', $orden->id)->where('estado', '!=', 'pagada')->get() as $c) {
                $c->update([
                    'mes_venta'        => $mesNuevo,
                    'fecha_venta'      => $nueva->toDateString(),
                    'fecha_disponible' => ComisionController::calcularFechaDisponible($nueva->copy(), (int) $c->tienda_id),
                ]);
            }

            // Restauraciones (se rehacen con el equipo del día) y lo demás.
            ComisionController::sincronizarValorOrden($orden->fresh());
        });

        ComisionController::pedirRecalculo("{$usuario->nombre}: fecha de {$orden->referencia}");

        $avisos = [];
        foreach (array_unique([$mesAntes, $mesNuevo]) as $mes) {
            if (Cierres::estaCerrado($mes)) {
                $avisos[] = "El mes {$mes} está cerrado en Finanzas: su foto no cambia hasta que se reabra.";
            }
        }

        // Solo a quien la vendió: con usuario null el aviso le llegaría a
        // todos los supervisores.
        if ($orden->vendedor_id && (int) $orden->vendedor_id !== (int) $usuario->id) {
            NotificacionService::crear(
                'orden_editada',
                'Cambió la fecha de una venta',
                "{$usuario->nombre} pasó la orden {$orden->referencia} del {$antes->format('d/m/Y')} al {$nueva->format('d/m/Y')}.",
                ['orden_id' => $orden->id],
                (int) $orden->vendedor_id,
            );
        }

        return ['orden' => $orden->fresh(), 'avisos' => $avisos];
    }
}
