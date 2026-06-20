<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioTela extends Model
{
    protected $table = 'inventario_telas';

    protected $fillable = [
        'referencia',
        'color',
        'textura',
        'proveedor',
        'metros_disponibles',
        'metros_reservados',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo'             => 'boolean',
            'metros_disponibles' => 'decimal:2',
            'metros_reservados'  => 'decimal:2',
        ];
    }
}
