<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoVarianteOpcion extends Model
{
    protected $table    = 'tipo_variante_opciones';
    protected $fillable = ['tipo_variante_id', 'nombre', 'activo'];
    protected $casts    = ['activo' => 'boolean'];

    public function tipo()
    {
        return $this->belongsTo(TipoVariante::class, 'tipo_variante_id');
    }
}
