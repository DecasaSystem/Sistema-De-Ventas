<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una entrega: qué se le llevó al cliente en un viaje, con su acta, sus
 * fotos y su pago.
 *
 * Nació como "una orden dentro de un despacho" y por eso se llama así. Desde
 * que se entregan productos y no órdenes (ver `EntregaLinea`), una orden
 * puede tener varias: el reloj hoy, el mueble cuando salga del taller.
 */
class DespachoItem extends Model
{
    protected $table = 'despacho_items';

    public $timestamps = false;

    protected $fillable = [
        'despacho_id',
        'orden_id',
        'posicion',
        'estado',
        'foto_producto',
        'foto_pago',
        // Todas las fotos del comprobante; foto_pago lleva la primera.
        'fotos_pago',
        'entregado_at',
        // Acta de satisfacción firmada por quien recibe
        'firma_recibido_url',
        'recibido_por_nombre',
        'recibido_por_cedula',
        'conforme',
        'observaciones_entrega',
        'foto_novedad_url',
        'firma_omitida_motivo',
    ];

    protected function casts(): array
    {
        return [
            'entregado_at' => 'datetime',
            'conforme'     => 'boolean',
            'fotos_pago'   => 'array',
        ];
    }

    /** ¿La entrega quedó respaldada con firma o con un motivo de por qué no? */
    public function tieneActa(): bool
    {
        return $this->firma_recibido_url !== null || $this->firma_omitida_motivo !== null;
    }

    public function despacho()
    {
        return $this->belongsTo(Despacho::class, 'despacho_id');
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    /** Qué productos van en esta entrega. Vacío = todo lo que falte. */
    public function lineas()
    {
        return $this->hasMany(EntregaLinea::class, 'despacho_item_id');
    }

    public function tienePago(): bool
    {
        return $this->orden->pagos()
            ->where('created_at', '>=', $this->despacho->created_at)
            ->exists();
    }

    /**
     * ¿Con esta entrega el cliente queda con todo lo que compró?
     *
     * Se mira lo que ya tenía más lo que va en estas líneas contra lo que
     * lleva la orden. Sin líneas escritas, esta entrega lleva todo lo que
     * falta (es como funcionaban las entregas antes de las parciales).
     */
    public function completaLaOrden(): bool
    {
        $orden  = $this->orden()->with('items')->first();
        $lineas = $this->lineas()->get();

        if ($lineas->isEmpty()) return true;

        $vaAhora = $lineas->whereIn('resultado', EntregaLinea::SE_QUEDO)
            ->groupBy('orden_item_id')->map(fn ($g) => (int) $g->sum('cantidad'));

        foreach ($orden->items as $item) {
            if ($item->pendienteEntregar() > (int) ($vaAhora[$item->id] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Se puede cerrar esta entrega?
     *
     * Siempre la foto del producto. El pago solo se exige cuando con esta
     * entrega el cliente queda con todo y todavía debe: en una entrega parcial
     * no se le cobra el saldo, porque aún le falta recibir algo — puede
     * abonar, pero no es obligatorio.
     */
    public function puedeEntregar(): bool
    {
        // Evidencia de llegada siempre obligatoria
        if ($this->foto_producto === null) return false;

        // Si se devolvió todo no hay nada que cobrar: el cliente no se quedó
        // con nada. Exigirle el pago acá dejaría al conductor trabado en la
        // puerta de la casa sin poder cerrar la entrega.
        if ($this->seDevolvioTodo()) return true;

        // Sin saldo pendiente: solo se necesita foto del producto
        if ($this->orden->saldoPendiente() <= 0.01) return true;

        // Entrega parcial: el saldo se cobra en la última.
        if (! $this->completaLaOrden()) return true;

        // Con saldo y es la última: también foto del pago y pago registrado
        return $this->foto_pago !== null && $this->tienePago();
    }

    /** ¿Volvió en el camión todo lo que llevaba esta entrega? */
    public function seDevolvioTodo(): bool
    {
        $devueltas = Devolucion::where('despacho_item_id', $this->id)
            ->get()
            ->groupBy('orden_item_id')
            ->map(fn ($g) => (int) $g->sum('cantidad'));

        if ($devueltas->isEmpty()) return false;

        // Con líneas, lo que "llevaba" es lo de las líneas; sin ellas, todo
        // lo que le faltaba a la orden.
        $lineas = $this->lineas()->get();
        if ($lineas->isNotEmpty()) {
            $llevaba = $lineas->groupBy('orden_item_id')->map(fn ($g) => (int) $g->sum('cantidad'));
            foreach ($llevaba as $itemId => $cant) {
                if ($cant > (int) ($devueltas[$itemId] ?? 0)) return false;
            }
            return true;
        }

        foreach ($this->orden->items as $item) {
            if ($item->pendienteEntregar() > (int) ($devueltas[$item->id] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    public function devoluciones()
    {
        return $this->hasMany(Devolucion::class, 'despacho_item_id');
    }
}
