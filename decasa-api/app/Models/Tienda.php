<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['nombre', 'ciudad', 'direccion', 'telefono', 'activa', 'es_fabrica', 'es_independientes'];

    protected function casts(): array
    {
        return [
            'activa'            => 'boolean',
            'es_fabrica'        => 'boolean',
            'es_independientes' => 'boolean',
        ];
    }

    /**
     * Sede a la que cuelgan las órdenes de los vendedores independientes. No es
     * un punto de venta: no aparece en rankings de tiendas ni tiene caja propia,
     * existe porque toda orden necesita una tienda.
     */
    public static function sedeIndependientes(): ?self
    {
        return static::where('es_independientes', true)->first();
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
