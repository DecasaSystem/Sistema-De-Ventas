<?php

namespace App\Models;

use App\Support\Esquema;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que cambió una caja sin ser un movimiento nuevo.
 *
 * El saldo de la caja se vuelve a sumar cada vez con los pagos en efectivo
 * (`Pago::entraACaja`). Si un pago viejo se corrige (de efectivo a
 * transferencia, otro monto) o se borra con su orden, el saldo cambia y antes
 * no quedaba nada en la caja que lo explicara (usuario, 2026-11-23: "se restó
 * y ni siquiera se sabe por qué"). Cada uno de esos cambios deja aquí un
 * renglón con cuánto subió o bajó, quién y por qué.
 *
 * Solo explica: el saldo ya trae el cambio, así que estos renglones NO se suman
 * al saldo (sería contarlo dos veces).
 *
 * Lo anota `Pago` solo (al actualizarse o borrarse), así ningún camino que
 * toque pagos se queda sin dejar rastro.
 */
class CajaBitacora extends Model
{
    protected $table = 'caja_bitacora';

    protected $fillable = [
        'tienda_id', 'caja_usuario_id', 'pago_id', 'orden_id', 'referencia',
        'usuario_id', 'motivo', 'efecto', 'concepto', 'descripcion',
    ];

    protected function casts(): array
    {
        return ['efecto' => 'decimal:2'];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    private const TIPOS = [
        'anticipo'    => 'anticipo',
        'abono'       => 'abono',
        'saldo_final' => 'pago final',
        'reembolso'   => 'devolución de plata',
    ];

    /**
     * La caja a la que pertenece un pago con esos datos, o null si no es de
     * caja. Misma regla que `Pago::scopeEntraACaja` y
     * `CajaController::efectivoDeTienda`: efectivo, no cobrado al entregar; de
     * la caja propia si quien lo tiene vende por su cuenta, si no de la tienda
     * donde entró (o la de la orden, en los pagos viejos sin tienda).
     */
    public static function cajaDe(array $a): ?array
    {
        if (($a['metodo'] ?? null) !== 'efectivo' || ! empty($a['despacho_item_id'])) {
            return null;
        }

        $vendedor = $a['vendedor_id'] ?? null;
        if ($vendedor && Usuario::where('id', $vendedor)->value('independiente')) {
            return ['tienda_id' => null, 'caja_usuario_id' => (int) $vendedor];
        }

        $tienda = $a['tienda_id'] ?? null;
        if (! $tienda && ! empty($a['orden_id'])) {
            $tienda = Orden::where('id', $a['orden_id'])->value('tienda_id');
        }

        return $tienda ? ['tienda_id' => (int) $tienda, 'caja_usuario_id' => null] : null;
    }

    /** Un pago cambió: si con eso cambió alguna caja, se anota. */
    public static function anotarCambio(Pago $pago): void
    {
        if (! Esquema::tabla('caja_bitacora')) return;

        $antes   = array_merge($pago->getAttributes(), $pago->getOriginal());
        $despues = $pago->getAttributes();

        $cajaAntes   = self::cajaDe($antes);
        $cajaDespues = self::cajaDe($despues);
        if (! $cajaAntes && ! $cajaDespues) return;

        $montoAntes   = (float) ($antes['monto'] ?? 0);
        $montoDespues = (float) ($despues['monto'] ?? 0);

        $quien = auth()->user();
        $comun = self::comun($pago, $quien);
        $cuenta = self::describir($antes) . ' → ' . self::describir($despues);
        $hizo   = $quien ? " Lo cambió {$quien->nombre}." : '';
        $concepto = 'Corrección: ' . self::tipo($pago) . ' de ' . ($comun['referencia'] ?? 'una orden');

        if ($cajaAntes == $cajaDespues) {
            $efecto = round($montoDespues - $montoAntes, 2);
            if (abs($efecto) < 0.005) return;
            self::create($cajaAntes + $comun + [
                'motivo'      => 'correccion',
                'efecto'      => $efecto,
                'concepto'    => $concepto,
                'descripcion' => "{$cuenta}.{$hizo}",
            ]);
            return;
        }

        // Cambió de caja (o dejó de serlo, o empezó a serlo): sale de una y
        // entra a la otra.
        if ($cajaAntes && abs($montoAntes) >= 0.005) {
            self::create($cajaAntes + $comun + [
                'motivo'      => 'correccion',
                'efecto'      => -$montoAntes,
                'concepto'    => $concepto,
                'descripcion' => "{$cuenta}: ya no es efectivo de esta caja.{$hizo}",
            ]);
        }
        if ($cajaDespues && abs($montoDespues) >= 0.005) {
            self::create($cajaDespues + $comun + [
                'motivo'      => 'correccion',
                'efecto'      => $montoDespues,
                'concepto'    => $concepto,
                'descripcion' => "{$cuenta}: ahora es efectivo de esta caja.{$hizo}",
            ]);
        }
    }

    /** Un pago se borró (con su orden o su borrador): sale de la caja. */
    public static function anotarBorrado(Pago $pago, ?string $motivo = null): void
    {
        if (! Esquema::tabla('caja_bitacora')) return;

        $caja = self::cajaDe($pago->getAttributes());
        $monto = (float) $pago->monto;
        if (! $caja || abs($monto) < 0.005) return;

        $quien = auth()->user();
        $comun = self::comun($pago, $quien);

        self::create($caja + $comun + [
            'motivo'      => 'borrado',
            'efecto'      => -$monto,
            'concepto'    => 'Se borró ' . self::conArticulo($pago) . ' de ' . ($comun['referencia'] ?? 'una orden'),
            'descripcion' => trim(
                self::describir($pago->getAttributes()) . ' del ' . optional($pago->created_at)->timezone('America/Bogota')?->format('d/m/Y') . '.'
                . ($motivo ? " {$motivo}." : '')
                . ($quien ? " Lo hizo {$quien->nombre}." : '')
            ),
        ]);
    }

    private static function comun(Pago $pago, $quien): array
    {
        $ref = null;
        if ($pago->orden_id) {
            $orden = Orden::query()
                ->select('id', 'numero_orden', 'serie', 'serie_numero', 'cotizacion_numero', 'estado')
                ->find($pago->orden_id);
            $ref = $orden?->referencia ?? ('#' . $pago->orden_id);
        }

        return [
            'pago_id'    => $pago->id,
            'orden_id'   => $pago->orden_id,
            'referencia' => $ref ? mb_substr((string) $ref, 0, 40) : null,
            'usuario_id' => $quien?->id,
        ];
    }

    private static function tipo(Pago $pago): string
    {
        return self::TIPOS[$pago->tipo] ?? 'pago';
    }

    private static function conArticulo(Pago $pago): string
    {
        return ($pago->tipo === 'reembolso' ? 'la ' : 'el ') . self::tipo($pago);
    }

    private static function describir(array $a): string
    {
        $monto = (float) ($a['monto'] ?? 0);
        $texto = ($monto < 0 ? '−' : '') . '$' . number_format(abs($monto), 0, ',', '.') . ' en ' . ($a['metodo'] ?? '—');
        if (! empty($a['despacho_item_id'])) $texto .= ' (cobrado al entregar)';
        return $texto;
    }
}
