<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un rol/puesto de trabajo, configurable por empresa (antes era un enum fijo
 * en código). Cada uno se apoya en un `arquetipo` — el comportamiento de
 * fondo que ya existía (vendedor, supervisor, conductor, taller,
 * despachador) y que sigue determinando el cálculo de comisiones, caja, etc.
 * sin que este modelo lo toque.
 */
class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'clave', 'nombre', 'arquetipo', 'activo', 'orden',
        'acceso_redes', 'acceso_comisiones', 'recarga_telas', 'acceso_surtir',
        'acceso_costos', 'acceso_proveedores', 'acceso_despacho', 'acceso_produccion', 'acceso_reserva', 'acceso_nomina',
        'acceso_compras',
    ];

    protected function casts(): array
    {
        return [
            'activo'              => 'boolean',
            'orden'                => 'integer',
            'acceso_redes'         => 'boolean',
            'acceso_comisiones'    => 'boolean',
            'recarga_telas'        => 'boolean',
            'acceso_surtir'        => 'boolean',
            'acceso_costos'        => 'boolean',
            'acceso_proveedores'   => 'boolean',
            'acceso_despacho'      => 'boolean',
            'acceso_produccion'    => 'boolean',
            'acceso_reserva'       => 'boolean',
            'acceso_nomina'        => 'boolean',
            'acceso_compras'       => 'boolean',
        ];
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'rol_id');
    }
}
