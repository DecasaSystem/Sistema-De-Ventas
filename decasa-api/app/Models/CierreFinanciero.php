<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El cierre de un mes ya revisado: congela su estado de resultados. Ver
 * App\Services\Finanzas\Cierres.
 */
class CierreFinanciero extends Model
{
    protected $table = 'cierres_financieros';

    protected $fillable = [
        'mes', 'snapshot', 'estado', 'cerrado_por', 'cerrado_at', 'reabierto_por', 'reabierto_at', 'motivo_reapertura',
    ];

    protected function casts(): array
    {
        return [
            'snapshot'     => 'array',
            'cerrado_at'   => 'datetime',
            'reabierto_at' => 'datetime',
        ];
    }

    public function cerradoPor()
    {
        return $this->belongsTo(Usuario::class, 'cerrado_por');
    }
}
