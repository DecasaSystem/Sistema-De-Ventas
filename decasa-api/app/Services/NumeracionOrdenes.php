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
 * Lo mismo pasa al revés (una R o FV2 que en realidad era venta normal) y
 * entre dos series (R que en realidad era FV2): la orden sale de un "espacio"
 * de numeración —normal de una tienda, o el de una serie— y entra a otro.
 * Cualquiera de los dos lados puede quedar con un hueco, así que "correr" se
 * le aplica siempre al de SALIDA, sea cual sea, no solo al normal: si no, el
 * consecutivo de esa serie se queda corrido para siempre en vez de reflejar
 * lo que de verdad se vendió.
 *
 * A dónde entra: la NORMAL sale del grupo que le toca a su tienda —Pereira
 * lleva su propio consecutivo aparte de Armenia (ver
 * OrdenController::grupoDeTienda())—, y una serie sale de su propio contador
 * global.
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
 * Lo que sí hay que mover a mano es la CLASIFICACIÓN, porque no sale del
 * número: la etiqueta vive en `ordenes.tipo` y el resto del sistema
 * —comisiones, reportes, stats, el taller— la deduce de si todos los ítems
 * son `es_restauracion`. Las tres se corrigen juntas o la orden queda como
 * quedó la #1242: numerada como venta y cobrada como restauración.
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
     * @return array{orden: array, corridas: array, hueco: ?int, ya_entregadas: int, serie_numero: ?int, items_a_tocar: int}
     */
    public static function previsualizarConversion(Orden $orden, string $destino, bool $correr): array
    {
        self::exigirConvertible($orden, $destino);

        $espacio  = self::espacioActual($orden);
        $corridas = ($correr && $espacio) ? self::ordenesACorrer($espacio) : collect();

        // Cuántos muebles cambian de bando. Se avisa ANTES de aplicar porque
        // es lo que mueve la plata: una orden que deja de ser restauración
        // pasa a comisionar por el pool y a sumarle a la meta de la tienda.
        $itemsATocar = DB::table('orden_items')
            ->where('orden_id', $orden->id)
            ->where('es_restauracion', $destino === Orden::SERIE_RESTAURACION ? 0 : 1)
            ->count();

        if ($destino === Orden::SERIE_NORMAL) {
            $numeroNuevo = self::proximoNumero(self::grupoParaVentaNormal($orden));
            $a           = '#' . $numeroNuevo;
            $serieNumero = null;
        } else {
            $numeroNuevo = self::proximoNumero(strtolower($destino));
            $a           = $destino . '-' . $numeroNuevo;
            $serieNumero = $numeroNuevo;
        }

        return [
            'orden' => [
                'id'      => $orden->id,
                'de'      => $orden->referencia,
                'a'       => $a,
                'cliente' => $orden->cliente?->nombre,
            ],
            'serie_numero'  => $serieNumero,
            'items_a_tocar' => $itemsATocar,
            'hueco'         => $espacio['hueco'] ?? null,
            'corridas'      => $corridas->map(fn (Orden $o) => [
                'id'      => $o->id,
                'de'      => $espacio['prefijo'] . $o->{$espacio['columnaNumero']},
                'a'       => $espacio['prefijo'] . ($o->{$espacio['columnaNumero']} - 1),
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

        return DB::transaction(function () use ($orden, $destino, $correr, $usuario, $motivo) {
            $refAntes = $orden->referencia;
            // El espacio que la orden ocupaba ANTES de tocar nada: de ahí sale
            // el hueco a correr, y hay que leerlo antes del update de abajo.
            $espacio  = self::espacioActual($orden);

            if ($destino === Orden::SERIE_NORMAL) {
                $grupo  = self::grupoParaVentaNormal($orden);
                $numero = self::tomarSiguienteNumero($grupo);

                $orden->update([
                    'serie'           => null,
                    'serie_numero'    => null,
                    'numero_orden'    => $numero,
                    'grupo_secuencia' => $grupo,
                    'motivo_serie'    => null,
                    // `tipo` se guarda aparte de `serie` desde que se creó la
                    // orden (el carrito lo dedujo: "restauracion" solo si TODO
                    // era mueble del cliente) y nada la mantenía sincronizada
                    // después. Si no se corrige acá, la etiqueta de la lista
                    // queda pegada al tipo viejo aunque la numeración ya cambió.
                    'tipo'            => 'venta',
                ]);
                $refDespues = '#' . $numero;
                $label      = 'Convertida a venta normal' . ($motivo ? " ({$motivo})" : '');
            } else {
                $numeroSerie = self::tomarSiguienteNumero(strtolower($destino));

                $orden->update([
                    'serie'           => $destino,
                    'serie_numero'    => $numeroSerie,
                    'numero_orden'    => null,
                    'grupo_secuencia' => null,
                    'motivo_serie'    => $motivo,
                    'tipo'            => $destino === Orden::SERIE_RESTAURACION ? 'restauracion' : 'venta',
                ]);
                $refDespues = $destino . '-' . $numeroSerie;
                $label      = 'Convertida a serie ' . $destino;
            }

            // Que una orden sea restauración se decide en DOS capas, y la
            // corrección tiene que mover las dos. Arriba está la etiqueta
            // (`ordenes.tipo`), que es la que se ve en la lista. Abajo están
            // los ítems: comisiones, reportes, stats y el taller no miran ni
            // la serie ni el tipo, deducen la restauración de que TODOS los
            // ítems tengan `es_restauracion` (Orden::sqlTipo(),
            // ComisionController::idsDeRestauracion()).
            //
            // Mover solo la de arriba fue lo que dejó a la #1242 con número
            // de venta normal y cobrada como restauración: al 5% aparte, sin
            // sumarle a la meta de la tienda y saliendo como restauración en
            // los reportes.
            $itemsTocados = self::sincronizarItems($orden, $destino);

            $corridas = ($correr && $espacio) ? self::correrHaciaAbajo($espacio) : [];

            $cambios = [[
                'campo'   => 'numeracion',
                'label'   => $label,
                'antes'   => $refAntes,
                'despues' => $refDespues,
            ]];

            if ($itemsTocados) {
                $aRestauracion = $destino === Orden::SERIE_RESTAURACION;
                $cambios[] = [
                    'campo'   => 'items_restauracion',
                    'label'   => $aRestauracion
                        ? "{$itemsTocados} mueble(s) pasan a ser del cliente"
                        : "{$itemsTocados} mueble(s) dejan de ser restauración",
                    'antes'   => $aRestauracion ? 'Venta' : 'Restauración',
                    'despues' => $aRestauracion ? 'Restauración' : 'Venta',
                ];
            }

            self::anotar($orden, $usuario, $cambios);

            foreach ($corridas as $c) {
                $movida = Orden::find($c['id']);
                self::anotar($movida, $usuario, [[
                    'campo'   => 'numeracion',
                    'label'   => 'Número corrido al convertir ' . $refAntes . ' a ' . $refDespues,
                    'antes'   => $c['de'],
                    'despues' => $c['a'],
                ]]);
            }

            return [
                'referencia'    => $orden->fresh()->referencia,
                'corridas'      => $corridas,
                'items_tocados' => $itemsTocados,
            ];
        });
    }

    /**
     * Pone los ítems de acuerdo con lo que la orden pasó a ser.
     *
     * `orden_items.es_restauracion` significa "este mueble es del cliente, no
     * salió de inventario", y la orden entera es de una cosa o de la otra
     * (OrdenController::store() no deja mezclarlas). Así que corregir la
     * clasificación es corregir todos los ítems de una vez:
     *
     *   → a serie R : todos pasan a ser del cliente.
     *   → a NORMAL o FV2 : ninguno lo es. El ítem queda como personalizado
     *     sin producto de catálogo, que es lo que ya era por dentro; no toca
     *     inventario porque nunca hubo un producto que descontar.
     *
     * @return int cuántos ítems cambiaron (0 si ya estaban como debían)
     */
    private static function sincronizarItems(Orden $orden, string $destino): int
    {
        $debenSerRestauracion = $destino === Orden::SERIE_RESTAURACION;

        return DB::table('orden_items')
            ->where('orden_id', $orden->id)
            ->where('es_restauracion', $debenSerRestauracion ? 0 : 1)
            ->update(['es_restauracion' => $debenSerRestauracion]);
    }

    /**
     * El espacio de numeración que la orden ocupa AHORA MISMO: normal (de su
     * tienda) o el de una serie. Nunca los dos a la vez. Null solo si la orden
     * todavía no tiene consecutivo —exigirConvertible() ya bloqueó ese caso
     * antes de llegar acá, así que en la práctica siempre hay uno.
     *
     * `claveContador` es la fila de `orden_secuencias` que gobierna ese
     * espacio: el nombre del grupo para lo normal, la serie en minúscula para
     * una serie. `prefijo` es cómo se escribe un número de ese espacio
     * ("#4289" vs "R-1103"), para las columnas de la vista previa.
     */
    private static function espacioActual(Orden $orden): ?array
    {
        if ($orden->serie && $orden->serie_numero) {
            return [
                'columnaGrupo'  => 'serie',
                'valorGrupo'    => $orden->serie,
                'columnaNumero' => 'serie_numero',
                'claveContador' => strtolower($orden->serie),
                'hueco'         => (int) $orden->serie_numero,
                'prefijo'       => $orden->serie . '-',
            ];
        }
        if ($orden->grupo_secuencia && $orden->numero_orden) {
            return [
                'columnaGrupo'  => 'grupo_secuencia',
                'valorGrupo'    => $orden->grupo_secuencia,
                'columnaNumero' => 'numero_orden',
                'claveContador' => $orden->grupo_secuencia,
                'hueco'         => (int) $orden->numero_orden,
                'prefijo'       => '#',
            ];
        }

        return null;
    }

    /**
     * Baja en 1 todas las órdenes del espacio con número mayor al hueco.
     *
     * En orden ASCENDENTE a propósito: el destino de cada una es el número que
     * acaba de quedar libre, así nunca hay dos órdenes con el mismo número ni
     * un instante.
     *
     * @return array<int, array{id: int, de: string, a: string}>
     */
    private static function correrHaciaAbajo(array $espacio): array
    {
        [$columnaGrupo, $valorGrupo, $columnaNumero, $claveContador, $prefijo] = [
            $espacio['columnaGrupo'], $espacio['valorGrupo'], $espacio['columnaNumero'],
            $espacio['claveContador'], $espacio['prefijo'],
        ];

        // Se bloquea el contador ANTES de mover nada: si alguien está
        // numerando una orden nueva en este momento, espera su turno y no se
        // lleva un número que estamos a punto de reutilizar.
        $ultimo = DB::table('orden_secuencias')->where('grupo', $claveContador)
            ->lockForUpdate()->value('ultimo_numero');

        $aCorrer = self::ordenesACorrer($espacio, bloquear: true);
        $movidas = [];

        foreach ($aCorrer as $o) {
            $de = (int) $o->{$columnaNumero};
            $o->update([$columnaNumero => $de - 1]);
            $movidas[] = ['id' => $o->id, 'de' => $prefijo . $de, 'a' => $prefijo . ($de - 1)];
        }

        // El contador baja solo si lo que se corrió llega hasta arriba. Si la
        // última orden movida no era la del tope, bajarlo repartiría un número
        // que ya está en uso.
        $maxAhora = (int) Orden::where($columnaGrupo, $valorGrupo)->max($columnaNumero);
        if ($ultimo !== null && $maxAhora > 0 && $maxAhora < $ultimo) {
            DB::table('orden_secuencias')->where('grupo', $claveContador)
                ->update(['ultimo_numero' => $maxAhora]);
        }

        return $movidas;
    }

    /**
     * Las órdenes del espacio posteriores al hueco, de menor a mayor.
     *
     * `$bloquear` solo al aplicar de verdad: en la vista previa no hay nada
     * que proteger y un bloqueo suelto fuera de transacción no sirve de nada.
     */
    private static function ordenesACorrer(array $espacio, bool $bloquear = false)
    {
        $q = Orden::with('cliente:id,nombre')
            ->where($espacio['columnaGrupo'], $espacio['valorGrupo'])
            ->whereNotNull($espacio['columnaNumero'])
            ->where($espacio['columnaNumero'], '>', $espacio['hueco'])
            ->orderBy($espacio['columnaNumero']);

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

        // Una restauración es el mueble del cliente: no sale de inventario. Si
        // la orden lleva productos del catálogo, marcarlos como del cliente
        // sería mentir sobre un stock que ya se descontó, así que se para acá
        // —la misma regla que impide mezclar venta y restauración al crearla,
        // en OrdenController::store()—.
        if ($serie === Orden::SERIE_RESTAURACION) {
            $deCatalogo = DB::table('orden_items')->where('orden_id', $orden->id)
                ->whereNotNull('producto_id')->count();

            if ($deCatalogo) {
                throw ValidationException::withMessages([
                    'serie' => ["Esta orden lleva {$deCatalogo} producto(s) del inventario y una restauración es el mueble del cliente. Sácalos de la orden antes de convertirla."],
                ]);
            }
        }
    }

    private static function proximoNumero(string $clave): int
    {
        return ((int) DB::table('orden_secuencias')->where('grupo', $clave)->value('ultimo_numero')) + 1;
    }

    /**
     * Toma el siguiente número de un contador y lo deja reservado (bloqueado
     * mientras dura la transacción). Lo usan tanto una serie (clave = "fv2",
     * "r") como la numeración normal (clave = "armenia", "pereira"): es el
     * mismo contador, solo cambia qué fila de `orden_secuencias` gobiernan.
     */
    private static function tomarSiguienteNumero(string $clave): int
    {
        $actual = DB::table('orden_secuencias')->where('grupo', $clave)
            ->lockForUpdate()->value('ultimo_numero');
        if ($actual === null) {
            DB::table('orden_secuencias')->insert(['grupo' => $clave, 'ultimo_numero' => 0]);
            $actual = 0;
        }
        $nuevo = $actual + 1;
        DB::table('orden_secuencias')->where('grupo', $clave)->update(['ultimo_numero' => $nuevo]);

        return $nuevo;
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

    private static function anotar(Orden $orden, Usuario $usuario, array $cambios): void
    {
        OrdenEdicion::create([
            'orden_id'   => $orden->id,
            'usuario_id' => $usuario->id,
            'cambios'    => $cambios,
        ]);
    }
}
