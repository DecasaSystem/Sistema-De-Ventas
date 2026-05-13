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
    ];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }
}
