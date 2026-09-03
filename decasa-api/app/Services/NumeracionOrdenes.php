<?php

namespace App\Services;

use App\Http\Controllers\OrdenController;
use App\Models\Orden;
use App\Models\OrdenEdicion;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Corregir la numeración de una orden desde el sistema.
 *
 * El caso que resuelve: al vendedor se le olvidó marcar una venta como FV2 y
 * ya se hicieron órdenes encima. Convertirla libera su consecutivo y deja un
 * hueco (la 4289 desaparece y quedan 4290, 4291, 4292), así que el sistema se
 * desalinea del talonario de papel. Correr las siguientes cierra ese hueco.
 *
 * El caso contrario también pasa: se marcó FV2 o R de más y en realidad era
 * una venta normal. Ahí no hay hueco que cerrar (esa orden nunca ocupó un
 * consecutivo de tienda) — lo único que hace falta es darle el siguiente
 * número normal de SU tienda, y Pereira lleva su propio consecutivo aparte de
 * Armenia, así que no vale con tomar cualquier número: sale del grupo que le
 * corresponde a `orden->tienda_id` (ver OrdenController::grupoDeTienda()).
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
     * Qué pasaría al convertir esta orden, sin tocar nada. `$destino` es
     * Orden::SERIE_FV2, Orden::SERIE_RESTAURACION, o Orden::SERIE_NORMAL para
     * volver a numeración normal de tienda.
     *
     * @return array{orden: array, corridas: array, hueco: ?int, ya_entregadas: int, serie_numero: ?int}
     */
    public static function previsualizarConversion(Orden $orden, string $destino, bool $correr): array
    {
        self::exigirConvertible($orden, $destino);

        if ($destino === Orden::SERIE_NORMAL) {
            $grupo = self::grupoParaVentaNormal($orden);

            return [
                'orden' => [
                    'id'      => $orden->id,
                    'de'      => $orden->referencia,
                    'a'       => '#' . self::proximoDeSerie($grupo),
                    'cliente' => $orden->cliente?->nombre,
                ],
                'serie_numero'  => null,
                'hueco'         => null,
                'corridas'      => [],
                'ya_entregadas' => 0,
            ];
        }

        $hueco    = $orden->numero_orden;
        $grupo    = $orden->grupo_secuencia;
        $corridas = ($correr && $hueco && $grupo) ? self::ordenesACorrer($grupo, $hueco) : collect();

        return [
            'orden' => [
                'id'         => $orden->id,
                'de'         => $orden->referencia,
                'a'          => $destino . '-' . (self::proximoDeSerie($destino) ?: '?'),
                'cliente'    => $orden->cliente?->nombre,
            ],
            'serie_numero'  => self::proximoDeSerie($destino),
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
    public static function convertir(Orden $orden, string $destino, bool $correr, Usuario $usuario, ?string $motivo = null): array
    {
        self::exigirConvertible($orden, $destino);

        if ($destino === Orden::SERIE_NORMAL) {
            return self::convertirANormal($orden, $usuario, $motivo);
        }

        $serie = $destino;

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
        $destinos = [Orden::SERIE_FV2, Orden::SERIE_RESTAURACION, Orden::SERIE_NORMAL];
        if (! in_array($serie, $destinos, true)) {
            throw ValidationException::withMessages(['serie' => ['Serie no reconocida.']]);
        }

        // "Ya es NORMAL" se detecta por ausencia de serie: NORMAL nunca se
        // guarda en la columna, así que no hay con qué compararla directo.
        $yaEsDestino = $serie === Orden::SERIE_NORMAL ? ! $orden->serie : $orden->serie === $serie;
        if ($yaEsDestino) {
            $nombre = $serie === Orden::SERIE_NORMAL ? 'una venta normal' : "de serie {$serie}";
            throw ValidationException::withMessages(['serie' => ["Esta orden ya es {$nombre}."]]);
        }
        if (in_array($orden->estado, ['borrador', 'cotizacion', 'pendiente_cotizacion'], true)) {
            throw ValidationException::withMessages([
                'serie' => ['Esta orden todavía no tiene consecutivo: se le asigna la serie cuando se confirme.'],
            ]);
        }
    }

    private static function proximoDeSerie(string $grupo): int
    {
        return ((int) DB::table('orden_secuencias')->where('grupo', strtolower($grupo))->value('ultimo_numero')) + 1;
    }

    /**
     * A qué grupo de consecutivo normal (armenia/pereira) le toca el próximo
     * número de esta orden, según su tienda. Mismo criterio que usa
     * OrdenController::asignarNumeroOrden() al numerar una orden nueva —
     * incluida la reserva de más abajo para una tienda sin grupo asignado,
     * para no repetir la desalineación que ya pasó una vez con Independientes.
     */
    private static function grupoParaVentaNormal(Orden $orden): string
    {
        $grupo = OrdenController::grupoDeTienda($orden->tienda_id);
        if ($grupo) {
            return $grupo;
        }

        Log::warning('Orden de una tienda sin grupo de consecutivo: se numera con el de Armenia', [
            'orden_id'  => $orden->id,
            'tienda_id' => $orden->tienda_id,
        ]);

        return 'armenia';
    }

    /**
     * Saca la orden de FV2/R y le da el siguiente número normal DE SU TIENDA.
     * No hay hueco que correr del lado de la serie: esa orden nunca ocupó un
     * consecutivo de venta, así que no deja nada pendiente al salir de ahí.
     */
    private static function convertirANormal(Orden $orden, Usuario $usuario, ?string $motivo): array
    {
        return DB::transaction(function () use ($orden, $usuario, $motivo) {
            $refAntes = $orden->referencia;
            $grupo    = self::grupoParaVentaNormal($orden);

            $actual = DB::table('orden_secuencias')->where('grupo', $grupo)
                ->lockForUpdate()->value('ultimo_numero');
            if ($actual === null) {
                DB::table('orden_secuencias')->insert(['grupo' => $grupo, 'ultimo_numero' => 0]);
                $actual = 0;
            }
            $numero = $actual + 1;
            DB::table('orden_secuencias')->where('grupo', $grupo)
                ->update(['ultimo_numero' => $numero]);

            $orden->update([
                'serie'           => null,
                'serie_numero'    => null,
                'numero_orden'    => $numero,
                'grupo_secuencia' => $grupo,
                'motivo_serie'    => null,
            ]);

            self::anotar($orden, $usuario, [[
                'campo'   => 'numeracion',
                'label'   => 'Convertida a venta normal' . ($motivo ? " ({$motivo})" : ''),
                'antes'   => $refAntes,
                'despues' => '#' . $numero,
            ]]);

            return [
                'referencia' => $orden->fresh()->referencia,
                'corridas'   => [],
            ];
        });
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
