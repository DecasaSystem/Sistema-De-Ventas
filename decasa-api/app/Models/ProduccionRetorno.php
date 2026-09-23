<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una pieza que ya había salido del taller y volvió antes de entregarse.
 *
 * Guarda las dos decisiones que se toman al devolverla —a qué paso vuelve y
 * cuáles pasos se rehacen— además del porqué y de quién lo mandó. Se cierra
 * sola (`resuelto_at`) cuando la pieza vuelve a quedar lista; ver
 * `Produccion::booted()`.
 */
class ProduccionRetorno extends Model
{
    protected $table = 'produccion_retornos';

    public $timestamps = false;

    protected $fillable = [
        'produccion_id',
        'paso_destino_id',
        'pasos_rehacer',
        'motivo',
        'foto_url',
        'despacho_item_id',
        'devuelto_por_id',
        'resuelto_at',
    ];

    protected function casts(): array
    {
        return [
            'pasos_rehacer' => 'array',
            'created_at'    => 'datetime',
            'resuelto_at'   => 'datetime',
        ];
    }

    public function produccion()
    {
        return $this->belongsTo(Produccion::class, 'produccion_id');
    }

    public function pasoDestino()
    {
        return $this->belongsTo(ProduccionPaso::class, 'paso_destino_id');
    }

    public function devueltoPor()
    {
        return $this->belongsTo(Usuario::class, 'devuelto_por_id');
    }

    /** ¿La pieza sigue en el taller por culpa de este retorno? */
    public function estaAbierto(): bool
    {
        return $this->resuelto_at === null;
    }

    public function scopeAbiertos($query)
    {
        return $query->whereNull('resuelto_at');
    }
}
