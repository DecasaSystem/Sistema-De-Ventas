<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué producto, y cuántas unidades, fueron en una entrega.
 *
 * Una entrega (`DespachoItem`) puede llevar parte de una orden: el reloj hoy,
 * el mueble cuando el taller lo termine. Ver la migración
 * `entregas_por_producto`.
 */
class EntregaLinea extends Model
{
    protected $table = 'entrega_lineas';

    public $timestamps = false;

    public const ENTREGADO   = 'entregado';
    public const CON_NOVEDAD = 'con_novedad';
    public const DEVUELTO    = 'devuelto';

    /** Los resultados en los que el producto se quedó en la casa. */
    public const SE_QUEDO = [self::ENTREGADO, self::CON_NOVEDAD];

    protected $fillable = ['despacho_item_id', 'orden_item_id', 'cantidad', 'resultado'];

    public function entrega()
    {
        return $this->belongsTo(DespachoItem::class, 'despacho_item_id');
    }

    public function item()
    {
        return $this->belongsTo(OrdenItem::class, 'orden_item_id');
    }
}
