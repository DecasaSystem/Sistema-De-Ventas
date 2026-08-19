<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un perfil de producción: quién puede trabajar qué pasos del taller
 * (ebanista, tapicero, despachador, o el que el negocio quiera crear).
 * Reemplaza al antiguo TipoProceso::PERFILES fijo — ahora se mantiene desde
 * Gestión y se asigna a cualquier trabajador sin importar su rol.
 */
class PerfilProduccion extends Model
{
    protected $table = 'perfiles_produccion';

    protected $fillable = ['clave', 'nombre', 'activo', 'orden'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden'  => 'integer',
        ];
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'perfil_produccion_id');
    }
}
