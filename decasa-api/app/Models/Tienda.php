<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['nombre', 'ciudad', 'direccion', 'telefono', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'tienda_default_id');
    }

    public function inventarios()
    {
        return $this->hasMany(Inventario::class, 'tienda_id');
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class, 'tienda_id');
    }
}
