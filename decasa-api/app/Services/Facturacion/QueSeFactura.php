<?php

namespace App\Services\Facturacion;

use App\Models\FacturaElectronica;
use App\Models\Orden;
use App\Models\Pago;
use App\Support\Esquema;
use App\Support\ReglasFacturacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué está por facturar según las reglas de Ajustes (ReglasFacturacion).
 *
 * Una sola puerta: la lista del facturador, la pantalla de revisión, el botón
 * y la tarea automática preguntan aquí. Cambiar una regla en Ajustes cambia lo
 * que sale en todos lados a la vez.
 *
 * Revisado a fondo el 2026-11-11:
 *  - Cambiar "cuándo se factura" no puede facturar dos veces: una orden con
 *    facturas por pago no se vuelve a facturar completa, y un pago de una orden
 *    ya facturada completa no se factura aparte.
 *  - Una entrega por garantía no cuenta como "la entrega" (si no, una orden
 *    facturada a mano antes del módulo volvía a salir).
 *  - Lo ya facturado se descarta en la consulta, no después: antes se traían
 *    las 300 últimas entregadas (con las facturadas) y las pendientes más
 *    viejas desaparecían de la lista.
 *  - La fecha de la última entrega viene en la misma consulta (antes, una
 *    consulta por orden: a ~200 ms cada una, la lista tardaba segundos).
 */
class QueSeFactura
{
    /** Estados que nunca se facturan: no son una venta o ya no existen. */
    private const ESTADOS_FUERA = ['cancelado', 'cotizacion', 'borrador'];

    /** Máximo de renglones en la lista (solo pendientes de verdad). */
    private const MAXIMO = 500;

    /**
     * Por qué esta orden (o este pago) no se factura ahora, en palabras para
     * el facturador. null = se puede facturar.
     *
     * @param Collection<string, FacturaElectronica>|null $facturas las de la orden, por clave (si ya se cargaron)
     */
    public static function motivoParaNo(Orden $orden, ?Pago $pago = null, ?Collection $facturas = null): ?string
    {
        $r = ReglasFacturacion::todo();

        if (in_array($orden->estado, self::ESTADOS_FUERA, true)) {
            return $orden->estado === 'cancelado' ? 'La orden está cancelada.' : 'No es una venta (es ' . $orden->estado . ').';
        }
        if ($orden->serie === Orden::SERIE_FV2 && ! $r['facturar_fv2']) {
            return 'Las órdenes FV2 no se facturan (Facturación electrónica → Ajustes).';
        }
        if (! $r['facturar_restauraciones'] && $orden->esSoloRestauracion()) {
            return 'Las restauraciones no se facturan (Facturación electrónica → Ajustes).';
        }
        if (! $r['facturar_independientes'] && $orden->vendedor?->independiente) {
            return 'Las ventas de independientes no se facturan (Facturación electrónica → Ajustes).';
        }

        // Lo que ya tiene factura de la otra forma (se cambió "cuándo se factura").
        $facturas ??= self::facturasDe($orden);
        $hechas = $facturas->filter(fn ($f) => $f->estaHecha());
        if ($pago && ($completa = $hechas->first(fn ($f) => $f->pago_id === null))) {
            return 'La orden ya se facturó completa (' . ($completa->numero ?: 'sin número') . '): este pago no se factura aparte.';
        }
        if (! $pago && ($porPago = $hechas->filter(fn ($f) => $f->pago_id !== null))->isNotEmpty()) {
            return 'Esta orden ya tiene facturas por cada pago (' . $porPago->pluck('numero')->filter()->implode(', ')
                . '): no se factura otra vez completa. Si falta facturar algo, hazlo por pago o en Alegra.';
        }

        if ($r['momento'] === 'por_pago') {
            if (! $pago) return 'Con "una factura por cada pago" se factura cada pago, no la orden.';
            if ((float) $pago->monto <= 0 || $pago->tipo === 'reembolso') return 'Un reembolso no se factura: es una nota crédito.';
            if (self::antesDeDesde($pago->created_at)) return 'El pago es de antes de que empezara la facturación electrónica.';

            return null;
        }

        if ($pago) return 'Con las reglas de hoy se factura la orden completa, no cada pago.';

        if ($r['momento'] === 'al_entregar' && $orden->estado !== 'entregado') {
            return 'Se factura cuando la orden esté entregada completa.';
        }
        if ($r['momento'] === 'al_confirmar') {
            $tienePago = $orden->relationLoaded('pagos')
                ? $orden->pagos->contains(fn ($p) => (float) $p->monto > 0)
                : $orden->pagos()->where('monto', '>', 0)->exists();
            if (! $tienePago) return 'Se factura cuando entre el primer pago.';
        }
        if (self::antesDeDesde(self::fechaQueCuenta($orden))) {
            return 'Es de antes de que empezara la facturación electrónica.';
        }

        return null;
    }

    /** @return Collection<string, FacturaElectronica> */
    private static function facturasDe(Orden $orden): Collection
    {
        if (! Esquema::tabla('facturas_electronicas')) return collect();

        return FacturaElectronica::where('orden_id', $orden->id)->get()->keyBy('clave');
    }

    /**
     * La fecha con la que se compara "desde": al entregar, cuándo se entregó lo
     * último (sin contar las entregas de una garantía); al confirmar, cuándo se
     * creó. Si la orden viene de pendientes(), ya trae `ultima_entrega`.
     */
    public static function fechaQueCuenta(Orden $orden): ?\DateTimeInterface
    {
        if (ReglasFacturacion::get('momento') !== 'al_entregar') return $orden->created_at;

        if (array_key_exists('ultima_entrega', $orden->getAttributes())) {
            $ultima = $orden->getAttributes()['ultima_entrega'];
        } elseif (Esquema::columna('despacho_items', 'entregado_at')) {
            $ultima = self::consultaUltimaEntrega()->where('despacho_items.orden_id', $orden->id)->value('ultima');
        } else {
            $ultima = null;
        }

        return $ultima ? \Carbon\Carbon::parse($ultima) : $orden->updated_at;
    }

    /** max(entregado_at) de las entregas de verdad: las que cierran una garantía no cuentan. */
    private static function consultaUltimaEntrega()
    {
        return DB::table('despacho_items')
            ->selectRaw('MAX(despacho_items.entregado_at) AS ultima')
            ->whereNotNull('despacho_items.entregado_at')
            ->when(Esquema::columna('garantias', 'despacho_item_id'), fn ($q) => $q->whereNotExists(fn ($s) => $s
                ->from('garantias')->whereColumn('garantias.despacho_item_id', 'despacho_items.id')));
    }

    private static function antesDeDesde($fecha): bool
    {
        $desde = ReglasFacturacion::get('desde');
        if (! $desde || ! $fecha) return false;

        return \Carbon\Carbon::parse($fecha)->timezone('America/Bogota')->toDateString() < $desde;
    }

    /**
     * Lo que está esperando al facturador, lo más viejo primero.
     *
     * @return Collection<int, array{orden: Orden, pago: ?Pago, clave: string, factura: ?FacturaElectronica}>
     */
    public static function pendientes(?string $buscar = null, ?int $tiendaId = null): Collection
    {
        if (! ReglasFacturacion::activo()) return collect();

        $r       = ReglasFacturacion::todo();
        $desde   = $r['desde'];
        $porPago = $r['momento'] === 'por_pago';
        $emite   = ReglasFacturacion::emite();
        $hayFacturas = Esquema::tabla('facturas_electronicas');

        // Estados de una factura que ya sacan la orden/pago de la lista.
        $hechos = [...FacturaElectronica::ESTADOS_HECHOS, ...($emite ? [] : ['borrador_alegra'])];

        $q = Orden::query()
            ->with(['cliente', 'tienda:id,nombre,ciudad', 'vendedor:id,nombre,independiente', 'pagos', 'items'])
            ->select('ordenes.*')
            ->whereNotIn('ordenes.estado', self::ESTADOS_FUERA)
            ->when(! $r['facturar_fv2'], fn ($q) => $q->where(fn ($w) => $w->whereNull('ordenes.serie')->orWhere('ordenes.serie', '!=', Orden::SERIE_FV2)))
            ->when($tiendaId, fn ($q) => $q->where('ordenes.tienda_id', $tiendaId))
            ->when($buscar, fn ($q) => self::filtrarBusqueda($q, $buscar));

        $tieneEntregas = Esquema::columna('despacho_items', 'entregado_at');
        if ($r['momento'] === 'al_entregar' && $tieneEntregas) {
            $q->selectSub(self::consultaUltimaEntrega()->whereColumn('despacho_items.orden_id', 'ordenes.id'), 'ultima_entrega');
        }

        if ($r['momento'] === 'al_entregar') {
            $q->where('ordenes.estado', 'entregado');
            if ($desde) {
                $inicio = self::inicioEnUtc($desde);
                if ($tieneEntregas) {
                    // La última entrega de verdad (sin garantías) es de "desde" en adelante;
                    // sin entregas registradas, se mira cuándo cambió la orden.
                    $ultima = self::consultaUltimaEntrega()->whereColumn('despacho_items.orden_id', 'ordenes.id');
                    $q->where(fn ($w) => $w
                        ->whereRaw('(' . $ultima->toSql() . ') >= ?', [...$ultima->getBindings(), $inicio])
                        ->orWhere(fn ($o) => $o
                            ->whereRaw('(' . $ultima->toSql() . ') IS NULL', $ultima->getBindings())
                            ->where('ordenes.updated_at', '>=', $inicio)));
                } else {
                    $q->where('ordenes.updated_at', '>=', $inicio);
                }
            }
        } elseif ($r['momento'] === 'al_confirmar') {
            $q->whereExists(fn ($s) => $s->from('pagos')->whereColumn('pagos.orden_id', 'ordenes.id')->where('monto', '>', 0));
            if ($desde) $q->where('ordenes.created_at', '>=', self::inicioEnUtc($desde));
        } else {
            $q->whereExists(fn ($s) => $s->from('pagos')->whereColumn('pagos.orden_id', 'ordenes.id')->where('monto', '>', 0)
                ->when($desde, fn ($s) => $s->where('pagos.created_at', '>=', self::inicioEnUtc($desde))));
        }

        // Lo ya facturado completo no se trae (por pago, la orden sigue: se mira pago por pago).
        if ($hayFacturas) {
            $q->whereNotExists(fn ($s) => $s->from('facturas_electronicas')
                ->whereColumn('facturas_electronicas.orden_id', 'ordenes.id')
                ->whereNull('facturas_electronicas.pago_id')
                ->whereIn('facturas_electronicas.estado', $hechos));
        }

        $ordenes = $q->orderBy('ordenes.updated_at')->limit(self::MAXIMO)->get();
        if ($ordenes->isEmpty()) return collect();

        $facturasPorOrden = $hayFacturas
            ? FacturaElectronica::whereIn('orden_id', $ordenes->pluck('id'))->get()->groupBy('orden_id')
            : collect();

        $salida = collect();
        foreach ($ordenes as $orden) {
            $facturas = ($facturasPorOrden[$orden->id] ?? collect())->keyBy('clave');
            $hecha = fn (?FacturaElectronica $f) => $f && in_array($f->estado, $hechos, true);

            if ($porPago) {
                $pagos = $orden->pagos
                    // "Marcar facturado" (facturacion_hecha_at) es que el recibo ya se
                    // digitó en Alegra, no que tenga factura: no saca el pago de aquí.
                    ->filter(fn ($p) => (float) $p->monto > 0 && $p->tipo !== 'reembolso')
                    ->sortBy('created_at');
                foreach ($pagos as $pago) {
                    $clave = FacturaElectronica::claveDe($orden, $pago);
                    if ($hecha($facturas[$clave] ?? null)) continue;
                    if (self::motivoParaNo($orden, $pago, $facturas) !== null) continue;
                    $salida->push(['orden' => $orden, 'pago' => $pago, 'clave' => $clave, 'factura' => $facturas[$clave] ?? null]);
                }
                continue;
            }

            $clave = FacturaElectronica::claveDe($orden);
            if (self::motivoParaNo($orden, null, $facturas) !== null) continue;
            $salida->push(['orden' => $orden, 'pago' => null, 'clave' => $clave, 'factura' => $facturas[$clave] ?? null]);
        }

        return $salida;
    }

    private static function inicioEnUtc(string $fecha): string
    {
        return \Carbon\Carbon::parse($fecha, 'America/Bogota')->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    public static function filtrarBusqueda($q, string $buscar)
    {
        $raw     = trim($buscar);
        $sinHash = ltrim($raw, '#');
        $term    = '%' . mb_strtolower($raw) . '%';

        return $q->where(function ($w) use ($term, $sinHash) {
            $w->whereHas('cliente', fn ($c) => $c->whereRaw('LOWER(nombre) LIKE ?', [$term])->orWhere('cedula', 'like', "%{$sinHash}%"));
            if (ctype_digit($sinHash)) $w->orWhere('ordenes.numero_orden', $sinHash)->orWhere('ordenes.id', (int) $sinHash);
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]{0,9})[\s\-]+(\d+)$/', $sinHash, $m)) {
                $w->orWhere(fn ($s) => $s->where('ordenes.serie', strtoupper($m[1]))->where('ordenes.serie_numero', (int) $m[2]));
            }
        });
    }
}
