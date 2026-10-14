<?php

namespace App\Services;

use App\Models\DespachoItem;
use App\Models\Orden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El recibo de entrega y pago.
 *
 * Es el acta de satisfacción de siempre más lo que el cliente pagó en la
 * puerta: lo firma una sola vez. Para el efectivo es el único soporte que hay
 * (la transferencia deja su pantallazo y la tarjeta el voucher del datáfono),
 * por eso lleva un consecutivo propio, RC-…, como un recibo de caja.
 *
 * No es una factura: la factura de venta es la electrónica que se expide ante
 * la DIAN (la marca "facturada" de Facturación). Este papel no la reemplaza y
 * lo dice en el pie.
 */
class ReciboEntrega
{
    private const CONTADOR = 'recibos_entrega';

    /** El siguiente número de recibo. Llamar dentro de una transacción. */
    public static function siguienteNumero(): int
    {
        $actual = DB::table('orden_secuencias')->where('grupo', self::CONTADOR)
            ->lockForUpdate()->value('ultimo_numero');

        // La fila la crea la migración; si no está (una base nueva), se crea aquí.
        if ($actual === null) {
            DB::table('orden_secuencias')->insert(['grupo' => self::CONTADOR, 'ultimo_numero' => 0]);
            $actual = 0;
        }

        $siguiente = (int) $actual + 1;
        DB::table('orden_secuencias')->where('grupo', self::CONTADOR)->update(['ultimo_numero' => $siguiente]);

        return $siguiente;
    }

    /**
     * El enlace que se le manda al cliente: abre el PDF sin entrar al sistema.
     * Pasa por la app (Vercel reescribe /api al servidor), así el cliente ve
     * la dirección de siempre.
     */
    public static function enlace(DespachoItem $item, ?Request $request = null): ?string
    {
        if (! $item->recibo_token) return null;

        $base = config('app.frontend_url')
            ?: ($request?->headers->get('origin') ?: config('app.url'));

        return rtrim((string) $base, '/') . '/api/public/recibos/' . $item->recibo_token;
    }

    /**
     * La plata de esta entrega, para el recibo: lo que se pagó hoy medio por
     * medio, lo que ya había abonado antes y lo que queda.
     *
     * "Antes" son los pagos anteriores a los de esta entrega; así el recibo
     * dice lo mismo aunque se abra meses después y haya habido más abonos.
     *
     * @return array{pagos: \Illuminate\Support\Collection, hoy: float, antes: float, total: float, saldo: float}
     */
    public static function cuentas(Orden $orden, DespachoItem $item): array
    {
        $todos = $orden->pagos()->orderBy('id')->get(['id', 'monto', 'metodo', 'referencia', 'tipo', 'despacho_item_id', 'created_at']);
        $deHoy = $todos->where('despacho_item_id', $item->id)->values();

        $corte = $deHoy->min('id');
        $antes = $corte
            ? $todos->filter(fn ($p) => $p->id < $corte && (int) $p->despacho_item_id !== (int) $item->id)->sum('monto')
            : $todos->filter(fn ($p) => ! $item->entregado_at || $p->created_at <= $item->entregado_at)->sum('monto');

        $hoy   = (float) $deHoy->sum('monto');
        $total = (float) $orden->valor_total;

        return [
            'pagos' => $deHoy,
            'hoy'   => round($hoy, 2),
            'antes' => round((float) $antes, 2),
            'total' => round($total, 2),
            'saldo' => round(max(0, $total - (float) $antes - $hoy), 2),
        ];
    }

    /** El mensaje de WhatsApp con el enlace, listo para mandar. */
    public static function mensajeWhatsApp(Orden $orden, DespachoItem $item, string $enlace): string
    {
        $nombre = trim(explode(' ', (string) ($orden->cliente?->nombre ?? ''))[0] ?? '');
        $hola   = $nombre !== '' ? "Hola {$nombre}" : 'Hola';
        $cual   = $item->reciboReferencia()
            ? "el recibo de entrega y pago {$item->reciboReferencia()}"
            : 'el acta de entrega';

        return "{$hola}, gracias por tu compra en Decasa. Aquí está {$cual} de tu pedido {$orden->referencia}, "
            . "con lo que recibiste hoy y lo que pagaste: {$enlace}";
    }
}
