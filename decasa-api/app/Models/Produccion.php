<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Produccion extends Model
{
    protected $table = 'produccion';

    public $timestamps = false;

    protected $fillable = [
        'orden_item_id',
        'fecha_inicio',
        'fecha_compromiso',
        'fecha_real',
        'estado',
        'motivo_retraso',
        'despachado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio'      => 'date',
            'fecha_compromiso'  => 'date',
            'fecha_real'        => 'date',
        ];
    }

    public function ordenItem()
    {
        return $this->belongsTo(OrdenItem::class, 'orden_item_id');
    }

    public function pasos()
    {
        return $this->hasMany(ProduccionPaso::class, 'produccion_id')->orderBy('orden');
    }

    public function pasoActual()
    {
        return $this->hasOne(ProduccionPaso::class, 'produccion_id')
            ->whereIn('estado', ['en_proceso', 'pendiente'])
            ->orderBy('orden');
    }

    public function despachador()
    {
        return $this->belongsTo(Usuario::class, 'despachado_por');
    }

    public function diasRestantes(): int
    {
        return now()->diffInDays($this->fecha_compromiso, false);
    }
}
