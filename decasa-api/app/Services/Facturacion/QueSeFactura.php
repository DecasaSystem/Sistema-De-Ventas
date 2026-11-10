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
 */
class QueSeFactura
{
    /** Estados que nunca se facturan: no son una venta o ya no existen. */
    private const ESTADOS_FUERA = ['cancelado', 'cotizacion', 'borrador'];

    /**
     * Por qué esta orden (o este pago) no se factura ahora, en palabras para
     * el facturador. null = se puede facturar.
     */
    public static function motivoParaNo(Orden $orden, ?Pago $pago = null): ?string
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
        if ($r['momento'] === 'al_confirmar' && ! $orden->pagos()->where('monto', '>', 0)->exists()) {
            return 'Se factura cuando entre el primer pago.';
        }
        if (self::antesDeDesde(self::fechaQueCuenta($orden))) {
            return 'Es de antes de que empezara la facturación electrónica.';
        }

        return null;
    }

    /**
     * La fecha con la que se compara "desde": al entregar, cuándo se entregó lo
     * último; al confirmar, cuándo se creó.
     */
    public static function fechaQueCuenta(Orden $orden): ?\DateTimeInterface
    {
        if (ReglasFacturacion::get('momento') === 'al_entregar' && Esquema::columna('despacho_items', 'entregado_at')) {
            $ultima = DB::table('despacho_items')->where('orden_id', $orden->id)->max('entregado_at');
            if ($ultima) return \Carbon\Carbon::parse($ultima);

            return $orden->updated_at;
        }

        return $orden->created_at;
    }

    private static function antesDeDesde($fecha): bool
    {
        $desde = ReglasFacturacion::get('desde');
        if (! $desde || ! $fecha) return false;

        return \Carbon\Carbon::parse($fecha)->timezone('America/Bogota')->toDateString() < $desde;
    }

    /**
     * Lo que está esperando al facturador.
     *
     * @return Collection<int, array{orden: Orden, pago: ?Pago, clave: string, factura: ?FacturaElectronica}>
     */
    public static function pendientes(?string $buscar = null, ?int $tiendaId = null): Collection
    {
        if (! ReglasFacturacion::activo()) return collect();

        $r       = ReglasFacturacion::todo();
        $desde   = $r['desde'];
        $porPago = $r['momento'] === 'por_pago';

        $q = Orden::query()
            ->with(['cliente', 'tienda:id,nombre,ciudad', 'vendedor:id,nombre,independiente'])
            ->whereNotIn('estado', self::ESTADOS_FUERA)
            ->when(! $r['facturar_fv2'], fn ($q) => $q->where(fn ($w) => $w->whereNull('serie')->orWhere('serie', '!=', Orden::SERIE_FV2)))
            ->when($tiendaId, fn ($q) => $q->where('tienda_id', $tiendaId))
            ->when($buscar, fn ($q) => self::filtrarBusqueda($q, $buscar));

        if ($r['momento'] === 'al_entregar') {
            $q->where('estado', 'entregado');
            if ($desde) {
                $tieneEntregas = Esquema::columna('despacho_items', 'entregado_at');
                $q->where(function ($w) use ($desde, $tieneEntregas) {
                    if ($tieneEntregas) {
                        $w->whereExists(fn ($s) => $s->from('despacho_items')->whereColumn('despacho_items.orden_id', 'ordenes.id')
                                ->whereNotNull('entregado_at')->where('entregado_at', '>=', self::inicioEnUtc($desde)))
                          ->orWhere(fn ($o) => $o->whereNotExists(fn ($s) => $s->from('despacho_items')->whereColumn('despacho_items.orden_id', 'ordenes.id')->whereNotNull('entregado_at'))
                                ->where('ordenes.updated_at', '>=', self::inicioEnUtc($desde)));
                    } else {
                        $w->where('ordenes.updated_at', '>=', self::inicioEnUtc($desde));
                    }
                });
            }
        } elseif ($r['momento'] === 'al_confirmar') {
            $q->whereExists(fn ($s) => $s->from('pagos')->whereColumn('pagos.orden_id', 'ordenes.id')->where('monto', '>', 0));
            if ($desde) $q->where('ordenes.created_at', '>=', self::inicioEnUtc($desde));
        } else {
            $q->whereExists(fn ($s) => $s->from('pagos')->whereColumn('pagos.orden_id', 'ordenes.id')->where('monto', '>', 0)
                ->when($desde, fn ($s) => $s->where('pagos.created_at', '>=', self::inicioEnUtc($desde))));
        }

        $ordenes = $q->orderByDesc('ordenes.updated_at')->limit(300)->get();
        if ($ordenes->isEmpty()) return collect();

        $facturas = FacturaElectronica::whereIn('orden_id', $ordenes->pluck('id'))->get()->keyBy('clave');
        $hechaParaLaLista = fn (?FacturaElectronica $f) => $f && (
            $f->estaHecha() || ($f->estado === 'borrador_alegra' && ! ReglasFacturacion::emite())
        );

        $salida = collect();
        foreach ($ordenes as $orden) {
            if ($porPago) {
                $pagos = $orden->pagos()->where('monto', '>', 0)
                    ->where(fn ($w) => $w->whereNull('tipo')->orWhere('tipo', '!=', 'reembolso'))
                    ->when(Esquema::columna('pagos', 'facturacion_hecha_at'), fn ($w) => $w->whereNull('facturacion_hecha_at'))
                    ->orderBy('created_at')->get();
                foreach ($pagos as $pago) {
                    $clave = FacturaElectronica::claveDe($orden, $pago);
                    if ($hechaParaLaLista($facturas[$clave] ?? null)) continue;
                    if (self::motivoParaNo($orden, $pago) !== null) continue;
                    $salida->push(['orden' => $orden, 'pago' => $pago, 'clave' => $clave, 'factura' => $facturas[$clave] ?? null]);
                }
                continue;
            }

            $clave = FacturaElectronica::claveDe($orden);
            if ($hechaParaLaLista($facturas[$clave] ?? null)) continue;
            if (self::motivoParaNo($orden) !== null) continue;
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
            if (ctype_digit($sinHash)) $w->orWhere('numero_orden', $sinHash)->orWhere('ordenes.id', (int) $sinHash);
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]{0,9})[\s\-]+(\d+)$/', $sinHash, $m)) {
                $w->orWhere(fn ($s) => $s->where('serie', strtoupper($m[1]))->where('serie_numero', (int) $m[2]));
            }
        });
    }
}
