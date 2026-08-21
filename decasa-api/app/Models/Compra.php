<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila de la lista de compras: nace como pedido ("hay que comprar 4
 * taladros") y la misma fila se completa cuando alguien la compra. No es un
 * catálogo con historial aparte — es una tarea que cambia de estado.
 */
class Compra extends Model
{
    protected $table = 'compras';

    protected $fillable = [
        'item', 'cantidad', 'notas', 'solicitado_por_id',
        'estado', 'comprador_nombre', 'precio', 'fecha_compra',
        'factura_foto_url', 'registrado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'precio'       => 'decimal:2',
            'fecha_compra' => 'date',
        ];
    }

    public function solicitante()
    {
        return $this->belongsTo(Usuario::class, 'solicitado_por_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(Usuario::class, 'registrado_por_id');
    }

    public function estaComprado(): bool
    {
        return $this->estado === 'comprado';
    }
}
