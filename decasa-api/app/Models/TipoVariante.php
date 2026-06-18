<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoVariante extends Model
{
    protected $table    = 'tipos_variante';
    protected $fillable = ['nombre', 'afecta_precio', 'activo'];
    protected $casts    = ['afecta_precio' => 'boolean', 'activo' => 'boolean'];

    public function opciones()
    {
        return $this->hasMany(TipoVarianteOpcion::class)->where('activo', true)->orderBy('nombre');
    }
}
