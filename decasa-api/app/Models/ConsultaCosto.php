<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultaCosto extends Model
{
    protected $table = 'consultas_costo';

    protected $fillable = [
        'orden_id',
        'asignado_a_id',
        'solicitado_por_id',
        'estado',
        'notas_adicionales',
        'respondido_at',
    ];

    protected function casts(): array
    {
        return [
            'respondido_at' => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function asignadoA()
    {
        return $this->belongsTo(Usuario::class, 'asignado_a_id');
    }

    public function solicitadoPor()
    {
        return $this->belongsTo(Usuario::class, 'solicitado_por_id');
    }

    public function items()
    {
        return $this->hasMany(ConsultaCostoItem::class, 'consulta_id');
    }
}
