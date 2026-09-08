<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Despacho extends Model
{
    protected $table = 'despachos';

    protected $fillable = [
        'camion_id',
        'conductor_id',
        'entregado_por_id',
        'supervisor_id',
        'fecha_despacho',
        'estado',
        'tipo',
        'notas',
        'nombre_ruta',
        'instrucciones',
    ];

    protected function casts(): array
    {
        return ['fecha_despacho' => 'date'];
    }

    public function camion()
    {
        return $this->belongsTo(Camion::class, 'camion_id');
    }

    public function conductor()
    {
        return $this->belongsTo(Usuario::class, 'conductor_id');
    }

    /** Quien entrega cuando es una entrega directa (vendedor/supervisor, sin ruta). */
    public function entregadoPor()
    {
        return $this->belongsTo(Usuario::class, 'entregado_por_id');
    }

    public function esDirecta(): bool
    {
        return $this->tipo === 'directa';
    }

    /** El nombre de quien hizo la entrega, sea conductor o entrega directa. */
    public function quienEntrega(): ?string
    {
        return $this->conductor?->nombre ?? $this->entregadoPor?->nombre;
    }

    public function supervisor()
    {
        return $this->belongsTo(Usuario::class, 'supervisor_id');
    }

    public function items()
    {
        return $this->hasMany(DespachoItem::class, 'despacho_id');
    }

    public function ordenes()
    {
        return $this->hasManyThrough(
            Orden::class,
            DespachoItem::class,
            'despacho_id',
            'id',
            'id',
            'orden_id'
        );
    }
}
