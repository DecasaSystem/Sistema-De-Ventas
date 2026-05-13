<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduccionPaso extends Model
{
    protected $table = 'produccion_pasos';

    public $timestamps = false;

    protected $fillable = [
        'produccion_id',
        'tipo_proceso',
        'orden',
        'estado',
        'completado_por',
        'completado_at',
    ];

    protected function casts(): array
    {
        return [
            'completado_at' => 'datetime',
        ];
    }

    public function produccion()
    {
        return $this->belongsTo(Produccion::class, 'produccion_id');
    }

    public function completadoPor()
    {
        return $this->belongsTo(Usuario::class, 'completado_por');
    }

    public static function labelProceso(string $tipo): string
    {
        return match ($tipo) {
            'ebanisteria' => 'Ebanistería',
            'tapizado'    => 'Tapizado',
            'laca'        => 'Laca',
            default       => $tipo,
        };
    }
}
