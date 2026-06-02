<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'orden_id',
        'vendedor_id',
        'tipo',
        'monto',
        'metodo',
        'referencia',
        'notas',
        'comprobante_url',
    ];

    protected function casts(): array
    {
        return [
            'monto'               => 'decimal:2',
            'facturacion_hecha_at' => 'datetime',
        ];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    public function facturacionTomadaPor()
    {
        return $this->belongsTo(Usuario::class, 'facturacion_tomada_por');
    }
}
