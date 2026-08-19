<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un trabajador de nómina: mano de obra de taller que casi nunca tiene
 * cuenta en la app (a diferencia de Usuario, que sí inicia sesión).
 */
class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = ['nombre', 'cedula', 'cargo', 'valor_label', 'valor_base', 'activo'];

    protected function casts(): array
    {
        return [
            'valor_base' => 'decimal:2',
            'activo'     => 'boolean',
        ];
    }

    public function items()
    {
        return $this->hasMany(NominaItem::class);
    }
}
