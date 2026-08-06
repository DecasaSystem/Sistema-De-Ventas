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
        'trabajadores',
        'rechazos',
        'ultimo_rechazo',
        'rechazado_por_id',
        'rechazado_at',
    ];

    protected function casts(): array
    {
        return [
            'completado_at'  => 'datetime',
            'rechazado_at'   => 'datetime',
            'trabajadores'   => 'array',
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

    /**
     * El nombre visible de un proceso.
     *
     * Ya no es una lista fija: los procesos los mantiene el taller desde
     * Produccion, asi que sale del catalogo.
     */
    public static function labelProceso(string $tipo): string
    {
        return TipoProceso::nombreDe($tipo);
    }
}
