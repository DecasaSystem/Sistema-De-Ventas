<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    const UPDATED_AT = null;

    /**
     * Todo pago queda atado a la tienda donde entró el dinero. Cuando no se
     * indica —anticipos, cobro del conductor— es la tienda de la orden, que es
     * donde se está haciendo la venta. Solo el abono manual permite elegir otra.
     *
     * Va aquí y no en cada punto de creación porque hay una decena repartidos
     * entre varios controladores y basta olvidar uno para descuadrar una caja.
     */
    protected static function booted(): void
    {
        static::creating(function (Pago $pago) {
            if ($pago->tienda_id === null && $pago->orden_id) {
                $pago->tienda_id = Orden::where('id', $pago->orden_id)->value('tienda_id');
            }
        });
    }

    protected $fillable = [
        'orden_id',
        'vendedor_id',
        'tienda_id',
        // La entrega en la que se cobró; null si se cobró en la tienda.
        'despacho_item_id',
        'tipo',
        'monto',
        'metodo',
        'referencia',
        'notas',
        'comprobante_url',
        // Todas las fotos del comprobante; comprobante_url lleva la primera.
        'comprobante_fotos',
    ];

    protected function casts(): array
    {
        return [
            'monto'               => 'decimal:2',
            'comprobante_fotos'   => 'array',
            'facturacion_hecha_at' => 'datetime',
        ];
    }

    /**
     * Lo que entra a una caja: el efectivo recibido en la tienda.
     *
     * El efectivo cobrado al entregar (conductor, o la entrega registrada desde
     * el detalle de la orden) no pasa por el cajón y no cuenta. La venta con
     * entrega inmediata sí: se cobra en el mostrador, como anticipo de la orden.
     *
     * Es la única regla de "qué pago es de caja": la usan la pantalla de Caja y
     * el asistente, para que nunca den cifras distintas.
     */
    public function scopeEntraACaja($query)
    {
        return $query->where('metodo', 'efectivo')->whereNull('despacho_item_id');
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    /**
     * ¿Corregir este pago a ese medio le quita a la orden el descuento por
     * pagar en efectivo o transferencia? Igual que al registrarlo: si una
     * parte se pagó con tarjeta, Addi u otro, el descuento se pierde. Solo
     * cuenta si el medio de verdad cambia (dejarlo en tarjeta no lo quita de
     * nuevo) y si la orden todavía tiene el descuento.
     */
    public function pierdeDescuentoSiPasaA(?string $metodo): bool
    {
        return $metodo !== null
            && $metodo !== $this->metodo
            && Orden::metodoPierdeDescuento($metodo)
            && $this->orden?->tieneDescuentoCondicionadoVivo();
    }

    /**
     * Lo contrario: ¿corregir este pago a efectivo o transferencia le devuelve
     * el descuento a la orden? Solo si la orden lo perdió y, con la corrección,
     * ya no queda ningún pago con tarjeta, Addi u otro (si queda uno, el
     * descuento sigue perdido por ese). El reembolso de una garantía no cuenta:
     * es plata que sale, no un pago del cliente.
     *
     * Y solo si con el total rebajado no queda pagado de más: si el cliente ya
     * pagó el precio completo, no hay descuento que devolverle sin sacarle
     * plata de vuelta (eso es otra cosa). Ahí se corrige el medio y ya.
     * `$monto` es el monto con el que queda este pago (por si también se corrige).
     */
    public function recuperaDescuentoSiPasaA(?string $metodo, ?float $monto = null): bool
    {
        if ($metodo === null || $metodo === $this->metodo || Orden::metodoPierdeDescuento($metodo)) return false;

        $orden = $this->orden;
        if (! $orden || $orden->descuento_condicionado_revertido_at === null) return false;

        $quedaOtroConTarjeta = self::where('orden_id', $orden->id)
            ->where('id', '!=', $this->id)
            ->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', '!=', 'reembolso'))
            ->whereNotIn('metodo', Orden::METODOS_CON_DESCUENTO)
            ->exists();
        if ($quedaOtroConTarjeta) return false;

        $perdido = \App\Services\DescuentoCondicionadoService::montoPerdido($orden);
        if ($perdido <= 0) return false;

        $otros = (float) self::where('orden_id', $orden->id)->where('id', '!=', $this->id)->sum('monto');
        return $otros + ($monto ?? (float) $this->monto) <= (float) $orden->valor_total - $perdido + 0.01;
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    /** Tienda donde se recibió el dinero (puede no ser la de la orden). */
    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id');
    }

    public function facturacionTomadaPor()
    {
        return $this->belongsTo(Usuario::class, 'facturacion_tomada_por');
    }
}
