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
        // Anular en vez de borrar: deja de sumar y se sigue viendo.
        'anulado_at',
        'anulado_por_id',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'anulado_at' => 'datetime',
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

    public function anuladoPor()
    {
        return $this->belongsTo(Usuario::class, 'anulado_por_id');
    }
}
