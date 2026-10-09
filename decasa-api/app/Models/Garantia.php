<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Un producto que se dañó después de entregado y el cliente lo reclama.
 *
 * Nace cuando alguien de la tienda lo reporta y se cierra cuando se resuelve:
 * arreglado en el taller y vuelto a entregar, arreglado en la casa, cambiado
 * (y el reemplazo entregado), o declarado "no procede" con su causal. Toda la
 * lógica de qué pasa con el stock, el taller y la orden vive en
 * `App\Services\GarantiaService`; aquí solo los datos y las cuentas del anexo.
 */
class Garantia extends Model
{
    protected $table = 'garantias';

    /** Las que todavía piden algo de alguien. */
    public const ABIERTAS = ['pendiente', 'por_recoger', 'en_taller', 'a_domicilio', 'cambio', 'por_devolver'];

    /**
     * Cuánto dura la garantía según de qué sea el daño (anexo, numeral 3).
     * "Otro" no tiene plazo propio: accesorios, colchones (los responde el
     * proveedor)... lo valora quien decide.
     */
    public const MESES = [
        'madera:elite_promocional' => 60,
        'madera:economica'         => 24,
        'tela_espuma'              => 6,
    ];

    /**
     * Por qué no procede. Son las exclusiones del anexo (numeral 4, art. 16 de
     * la Ley 1480) más las dos que no son exclusión sino falta de cobertura.
     */
    public const CAUSALES = [
        'vencida'       => 'La garantía ya venció',
        'sol_humedad'   => 'Exposición al sol, la humedad, la lluvia o el calor (num. I)',
        'mal_uso'       => 'Uso y cuidado contrarios al instructivo (num. II)',
        'maltrato'      => 'Usado fuera de su capacidad, golpeado o expuesto a líquidos (num. III)',
        'intervenido'   => 'Desarmado, modificado o reparado por personas no autorizadas (num. IV)',
        'fuerza_mayor'  => 'Fuerza mayor o caso fortuito (num. V)',
        'tercero'       => 'Hecho de un tercero (num. VI)',
        'arrastre'      => 'Desajuste por maltrato, arrastre o mal levantamiento (num. VII)',
        'proveedor'     => 'La responde el proveedor (colchones)',
        'no_cubierto'   => 'El daño no lo cubre la garantía',
    ];

    protected $fillable = [
        'orden_id', 'orden_item_id', 'cantidad',
        'tipo_dano', 'linea', 'motivo', 'fotos', 'donde_esta', 'preferencia_cliente',
        'fecha_reporte', 'fecha_entrega', 'vence_el', 'responder_antes_de', 'reportado_por_id',
        'estado', 'decision', 'decidido_por_id', 'decidido_at', 'notas_decision', 'causal_no_procede',
        'procesos_reparacion', 'produccion_id', 'recibido_en_taller_at', 'recibido_por_id',
        'visita_por_id', 'visita_fecha', 'visita_notas', 'visita_fotos',
        'orden_item_nuevo_id', 'diferencia_valor',
        'monto_reembolso', 'pago_reembolso_id', 'destino_devuelto', 'tienda_devuelto_id',
        'despacho_item_id', 'resuelta_at', 'resuelta_por_id',
    ];

    protected function casts(): array
    {
        return [
            'fotos'                 => 'array',
            'visita_fotos'          => 'array',
            'procesos_reparacion'   => 'array',
            'fecha_reporte'         => 'date',
            'fecha_entrega'         => 'date',
            'vence_el'              => 'date',
            'responder_antes_de'    => 'date',
            'visita_fecha'          => 'date',
            'decidido_at'           => 'datetime',
            'recibido_en_taller_at' => 'datetime',
            'resuelta_at'           => 'datetime',
            'diferencia_valor'      => 'decimal:2',
            'monto_reembolso'       => 'decimal:2',
        ];
    }

    public function orden()        { return $this->belongsTo(Orden::class, 'orden_id'); }
    public function item()         { return $this->belongsTo(OrdenItem::class, 'orden_item_id'); }
    public function itemNuevo()    { return $this->belongsTo(OrdenItem::class, 'orden_item_nuevo_id'); }
    public function produccion()   { return $this->belongsTo(Produccion::class, 'produccion_id'); }
    public function reportadoPor() { return $this->belongsTo(Usuario::class, 'reportado_por_id'); }
    public function decididoPor()  { return $this->belongsTo(Usuario::class, 'decidido_por_id'); }
    public function visitaPor()    { return $this->belongsTo(Usuario::class, 'visita_por_id'); }
    public function recibidoPor()  { return $this->belongsTo(Usuario::class, 'recibido_por_id'); }
    public function resueltaPor()  { return $this->belongsTo(Usuario::class, 'resuelta_por_id'); }

    public function estaAbierta(): bool
    {
        return in_array($this->estado, self::ABIERTAS, true);
    }

    public function scopeAbiertas($query)
    {
        return $query->whereIn('estado', self::ABIERTAS);
    }

    /** Meses de garantía para ese tipo de daño, o null si no tiene plazo propio. */
    public static function mesesDeGarantia(string $tipoDano, ?string $linea): ?int
    {
        if ($tipoDano === 'madera') {
            return self::MESES['madera:' . ($linea ?: 'elite_promocional')];
        }

        return self::MESES[$tipoDano] ?? null;
    }

    /** Hasta cuándo va la garantía, contada desde la entrega (anexo, numeral 2). */
    public static function venceEl(?Carbon $entrega, string $tipoDano, ?string $linea): ?Carbon
    {
        $meses = self::mesesDeGarantia($tipoDano, $linea);
        if (! $entrega || $meses === null) return null;

        return $entrega->copy()->addMonthsNoOverflow($meses);
    }

    /**
     * ¿Se reportó a tiempo? Null cuando no se puede saber (sin fecha de
     * entrega o un daño sin plazo propio): lo decide la persona.
     */
    public function dentroDeGarantia(): ?bool
    {
        if (! $this->vence_el) return null;

        return $this->fecha_reporte->lte($this->vence_el);
    }

    /**
     * Cuánto devolverle si se le reembolsa: lo que de verdad pagó por esas
     * unidades. Es lo que la orden deja de cobrar al sacarlas (con los
     * descuentos como los recalcula `Orden::recalcularTotal`), menos lo que
     * todavía debía. Así, devolviendo el sugerido, el saldo queda donde
     * estaba: ni le queda a favor ni debiendo. Es una sugerencia: el
     * supervisor puede poner otro valor (descontar el transporte, por ejemplo).
     */
    public function montoSugerido(): float
    {
        $orden = $this->orden;
        $item  = $this->item;
        if (! $orden || ! $item) return 0.0;

        $orden->loadMissing('items');
        $subtotal = (float) $orden->items->filter->estaVivo()
            ->sum(fn ($i) => $i->cantidad * $i->precio_unitario);
        $despues  = max(0, $subtotal - (int) $this->cantidad * (float) $item->precio_unitario);

        $desc     = min((float) $orden->descuento_total, $despues);
        $cond     = min((float) $orden->descuento_condicionado, max(0, $despues - $desc));
        $totalDespues = max(0, $despues - $desc - $cond);

        $deja = max(0, (float) $orden->valor_total - $totalDespues);

        return round(min($deja, max(0, $orden->totalPagado() - $totalDespues)), 2);
    }

    /** Los 30 días que promete el anexo desde que el taller recibe el mueble. */
    public function devolverAntesDe(): ?Carbon
    {
        return $this->recibido_en_taller_at
            ? $this->recibido_en_taller_at->copy()->startOfDay()->addDays(30)
            : null;
    }
}
