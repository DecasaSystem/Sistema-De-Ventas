<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    // Sin esto Eloquent pluraliza en inglés y busca "proveedors".
    protected $table = 'proveedores';

    protected $fillable = [
        'nombre', 'contacto', 'telefono', 'productos', 'direccion', 'notas', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
