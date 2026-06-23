<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CajaMovimiento extends Model
{
    protected $table = 'caja_movimientos';

    protected $fillable = [
        'tienda_id',
        'usuario_id',
        'tipo',
        'monto',
        'concepto',
        'descripcion',
        'comprobante_url',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}
