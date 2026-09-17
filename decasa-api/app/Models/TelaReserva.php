<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Metros de una tela apartados por un ítem de orden, o por una producción
 * para la Reserva (que no tiene orden). Una de las dos, nunca las dos.
 *
 * Nace `reservada` cuando la orden se confirma (o se manda a producir), pasa a `consumida` cuando el
 * taller termina la pieza y a `liberada` si la orden o la pieza se cancela.
 * Un ítem tiene a lo sumo una reserva viva (`reservada`); las cerradas se
 * quedan como historial.
 */
class TelaReserva extends Model
{
    protected $table = 'tela_reservas';

    public const RESERVADA = 'reservada';
    public const CONSUMIDA = 'consumida';
    public const LIBERADA  = 'liberada';

    protected $fillable = ['orden_item_id', 'produccion_id', 'catalogo_tela_id', 'metros', 'estado', 'detalle'];

    protected $casts = ['metros' => 'decimal:2'];

    public function item()
    {
        return $this->belongsTo(OrdenItem::class, 'orden_item_id');
    }

    public function produccion()
    {
        return $this->belongsTo(Produccion::class, 'produccion_id');
    }

    public function tela()
    {
        return $this->belongsTo(CatalogoTela::class, 'catalogo_tela_id');
    }

    public function scopeVivas($query)
    {
        return $query->where('estado', self::RESERVADA);
    }
}
