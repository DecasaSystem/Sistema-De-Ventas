<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\OrdenEdicion;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corregir la numeración de una orden desde el sistema.
 *
 * El caso que resuelve: al vendedor se le olvidó marcar una venta como FV2 y
 * ya se hicieron órdenes encima. Convertirla libera su consecutivo y deja un
 * hueco (la 4289 desaparece y quedan 4290, 4291, 4292), así que el sistema se
 * desalinea del talonario de papel. Correr las siguientes cierra ese hueco.
 *
 * Dos cosas hacen que esto sea viable sin tocar medio sistema:
 *
 * 1. El número NO está copiado en ninguna otra tabla. Todo lo que lo muestra
 *    —detalle, PDF, reportes, comisiones, facturación, despacho— lo deriva de
 *    `Orden::referencia`, así que cambiarlo acá se refleja solo en todos lados.
 *
 * 2. Los huecos que ya existen (4266-4273 son del talonario que se usó fuera
 *    del sistema) NO se tocan. Solo se corre el tramo posterior a la orden que
 *    se está convirtiendo, nunca "todos los huecos": barrer los viejos movería
 *    órdenes a través de rangos que se saltaron a propósito.
 *
 * Lo que NO se reescribe es el texto ya escrito: las notificaciones y los
 * mensajes que dicen "#4290" quedan como están. Son el registro de lo que se
 * dijo en su momento, y la tabla de notificaciones ni siquiera guarda a qué
 * orden pertenece cada una, así que un reemplazo masivo de texto pisaría
 * números que no son. El rastro queda en el historial de la orden.
 */
class NumeracionOrdenes
{
    /**
     * Qué pasaría al convertir esta orden a una serie, sin tocar nada.
     *
     * @return array{orden: array, corridas: array, hueco: ?int, ya_entregadas: int, serie_numero: int}
     */
    public static function previsualizarConversion(Orden $orden, string $serie, bool $correr): array
    {
        self::exigirConvertible($orden, $serie);

        $hueco    = $orden->numero_orden;
        $grupo    = $orden->grupo_secuencia;
        $corridas = ($correr && $hueco && $grupo) ? self::ordenesACorrer($grupo, $hueco) : collect();

        return [
            'orden' => [
                'id'         => $orden->id,
                'de'         => $orden->referencia,
                'a'          => $serie . '-' . (self::proximoDeSerie($serie) ?: '?'),
                'cliente'    => $orden->cliente?->nombre,
            ],
            'serie_numero'  => self::proximoDeSerie($serie),
            'hueco'         => $hueco,
            'corridas'      => $corridas->map(fn (Orden $o) => [
                'id'      => $o->id,
                'de'      => '#' . $o->numero_orden,
                'a'       => '#' . ($o->numero_orden - 1),
                'cliente' => $o->cliente?->nombre,
                'estado'  => $o->estado,
                'entregada' => $o->estado === 'entregado',
            ])->values()->all(),
            'ya_entregadas' => $corridas->where('estado', 'entregado')->count(),
        ];
    }

    /**
     * Convierte de verdad. Todo dentro de una transacción con el contador
     * bloqueado: si alguien está creando una orden al mismo tiempo, espera.
     */
    public static function convertir(Orden $orden, string $serie, bool $correr, Usuario $usuario, ?string $motivo = null): array
    {
        self::exigirConvertible($orden, $serie);

        return DB::transaction(function () use ($orden, $serie, $correr, $usuario, $motivo) {
            $refAntes = $orden->referencia;
            $hueco    = $orden->numero_orden;
            $grupo    = $orden->grupo_secuencia;

            // El número de la serie sale de su propio contador, bloqueado.
            $claveSerie = strtolower($serie);
            $actual = DB::table('orden_secuencias')->where('grupo', $claveSerie)
                ->lockForUpdate()->value('ultimo_numero');
            if ($actual === null) {
                DB::table('orden_secuencias')->insert(['grupo' => $claveSerie, 'ultimo_numero' => 0]);
                $actual = 0;
            }
            $numeroSerie = $actual + 1;
            DB::table('orden_secuencias')->where('grupo', $claveSerie)
                ->update(['ultimo_numero' => $numeroSerie]);

            $orden->update([
                'serie'           => $serie,
                'serie_numero'    => $numeroSerie,
                'numero_orden'    => null,
                'grupo_secuencia' => null,
                'motivo_serie'    => $motivo,
            ]);

            $corridas = [];
            if ($correr && $hueco && $grupo) {
                $corridas = self::correrHaciaAbajo($grupo, $hueco);
            }

            self::anotar($orden, $usuario, [[
                'campo'   => 'numeracion',
                'label'   => 'Convertida a serie ' . $serie,
                'antes'   => $refAntes,
                'despues' => $serie . '-' . $numeroSerie,
            ]]);

            foreach ($corridas as $c) {
                $movida = Orden::find($c['id']);
                self::anotar($movida, $usuario, [[
                    'campo'   => 'numeracion',
                    'label'   => 'Número corrido al convertir ' . $refAntes . ' a ' . $serie,
                    'antes'   => $c['de'],
                    'despues' => $c['a'],
                ]]);
            }

            return [
                'referencia' => $orden->fresh()->referencia,
                'corridas'   => $corridas,
            ];
        });
    }

    /**
     * Baja en 1 todas las órdenes del grupo con número mayor a $hueco.
     *
     * En orden ASCENDENTE a propósito: el destino de cada una es el número que
     * acaba de quedar libre, así nunca hay dos órdenes con el mismo número ni
     * un instante.
     *
     * @return array<int, array{id: int, de: string, a: string}>
     */
    private static function correrHaciaAbajo(string $grupo, int $hueco): array
    {
        // Se bloquea el contador ANTES de mover nada: si alguien está
        // numerando una orden nueva en este momento, espera su turno y no se
        // lleva un número que estamos a punto de reutilizar.
        $ultimo = DB::table('orden_secuencias')->where('grupo', $grupo)
            ->lockForUpdate()->value('ultimo_numero');

        $aCorrer = self::ordenesACorrer($grupo, $hueco, bloquear: true);
        $movidas = [];

        foreach ($aCorrer as $o) {
            $de = (int) $o->numero_orden;
            $o->update(['numero_orden' => $de - 1]);
            $movidas[] = ['id' => $o->id, 'de' => '#' . $de, 'a' => '#' . ($de - 1)];
        }

        // El contador baja solo si lo que se corrió llega hasta arriba. Si la
        // última orden movida no era la del tope, bajarlo repartiría un número
        // que ya está en uso.
        $maxAhora = (int) Orden::where('grupo_secuencia', $grupo)->max('numero_orden');
        if ($ultimo !== null && $maxAhora > 0 && $maxAhora < $ultimo) {
            DB::table('orden_secuencias')->where('grupo', $grupo)
                ->update(['ultimo_numero' => $maxAhora]);
        }

        return $movidas;
    }

    /**
     * Las órdenes del grupo posteriores al hueco, de menor a mayor.
     *
     * `$bloquear` solo al aplicar de verdad: en la vista previa no hay nada
     * que proteger y un bloqueo suelto fuera de transacción no sirve de nada.
     */
    private static function ordenesACorrer(string $grupo, int $hueco, bool $bloquear = false)
    {
        $q = Orden::with('cliente:id,nombre')
            ->where('grupo_secuencia', $grupo)
            ->whereNotNull('numero_orden')
            ->where('numero_orden', '>', $hueco)
            ->orderBy('numero_orden');

        if ($bloquear) {
            $q->lockForUpdate();
        }

        return $q->get();
    }

    /** Cambiar el número a mano, para los casos que no son una conversión. */
    public static function cambiarNumero(Orden $orden, int $nuevo, Usuario $usuario): array
    {
        if ($orden->serie) {
            throw ValidationException::withMessages([
                'numero_orden' => ["{$orden->referencia} es de serie {$orden->serie}: su número sale del contador de esa serie."],
            ]);
        }
        if (! $orden->grupo_secuencia) {
            throw ValidationException::withMessages([
                'numero_orden' => ['Esta orden todavía no tiene consecutivo asignado.'],
            ]);
        }

        return DB::transaction(function () use ($orden, $nuevo, $usuario) {
            $grupo   = $orden->grupo_secuencia;
            $refAntes = $orden->referencia;

            $ocupado = Orden::where('grupo_secuencia', $grupo)
                ->where('numero_orden', $nuevo)
                ->where('id', '!=', $orden->id)
                ->lockForUpdate()
                ->first();

            if ($ocupado) {
                throw ValidationException::withMessages([
                    'numero_orden' => ["El #{$nuevo} ya lo tiene otra orden (" . ($ocupado->cliente?->nombre ?? 'sin cliente') . ').'],
                ]);
            }

            $orden->update(['numero_orden' => $nuevo]);

            // El contador solo sube: si el número nuevo se pasa del tope, hay
            // que reservarlo o la próxima venta lo repite.
            $ultimo = DB::table('orden_secuencias')->where('grupo', $grupo)
                ->lockForUpdate()->value('ultimo_numero');
            if ($ultimo !== null && $nuevo > $ultimo) {
                DB::table('orden_secuencias')->where('grupo', $grupo)
                    ->update(['ultimo_numero' => $nuevo]);
            }

            self::anotar($orden, $usuario, [[
                'campo'   => 'numeracion',
                'label'   => 'Número corregido a mano',
                'antes'   => $refAntes,
                'despues' => '#' . $nuevo,
            ]]);

            return ['referencia' => $orden->fresh()->referencia];
        });
    }

    private static function exigirConvertible(Orden $orden, string $serie): void
    {
        if (! in_array($serie, [Orden::SERIE_FV2, Orden::SERIE_RESTAURACION], true)) {
            throw ValidationException::withMessages(['serie' => ['Serie no reconocida.']]);
        }
        if ($orden->serie === $serie) {
            throw ValidationException::withMessages(['serie' => ["Esta orden ya es {$serie}."]]);
        }
        if (in_array($orden->estado, ['borrador', 'cotizacion', 'pendiente_cotizacion'], true)) {
            throw ValidationException::withMessages([
                'serie' => ['Esta orden todavía no tiene consecutivo: se le asigna la serie cuando se confirme.'],
            ]);
        }
    }

    private static function proximoDeSerie(string $serie): int
    {
        return ((int) DB::table('orden_secuencias')->where('grupo', strtolower($serie))->value('ultimo_numero')) + 1;
    }

    private static function anotar(Orden $orden, Usuario $usuario, array $cambios): void
    {
        OrdenEdicion::create([
            'orden_id'   => $orden->id,
            'usuario_id' => $usuario->id,
            'cambios'    => $cambios,
        ]);
    }
}
