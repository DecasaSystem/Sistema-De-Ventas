<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un tipo de mano de obra sin costo fijo (Esqueletería, Tapicería…). Se escoge al agregar
 * mano de obra a una ficha y el costo se escribe en cada ficha. Ver TipoManoObraController.
 */
class TipoManoObra extends Model
{
    protected $table = 'tipos_mano_obra';

    protected $fillable = ['nombre', 'activo', 'orden'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer'];
    }
}
