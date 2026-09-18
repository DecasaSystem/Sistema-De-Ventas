<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un ítem de un módulo creado a partir de Telas: una espuma, un hilo, una
 * lámina. Tiene lo mismo que una tela —marca, tipo, color, referencia, foto—
 * y una cantidad que se recarga y se descuenta en la unidad del módulo.
 *
 * No se aparta desde las órdenes: eso es de las telas, que están amarradas a
 * lo que se vende. Aquí la cantidad disponible es la cantidad libre.
 */
class ModuloItem extends Model
{
    protected $table = 'modulo_items';

    protected $fillable = [
        'modulo_id', 'marca', 'tipo', 'color', 'referencia', 'textura', 'foto_url',
        'cantidad_disponible', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo'              => 'boolean',
            'cantidad_disponible' => 'decimal:2',
        ];
    }

    public function modulo()
    {
        return $this->belongsTo(Modulo::class, 'modulo_id');
    }

    /** Lo que ve la pantalla, con la misma forma que le da Telas a lo suyo. */
    public function paraPantalla(): array
    {
        $disponible = round((float) $this->cantidad_disponible, 2);

        return [
            'id'                  => $this->id,
            'marca'               => $this->marca,
            'tipo'                => $this->tipo,
            'color'               => $this->color,
            'referencia'          => $this->referencia,
            'textura'             => $this->textura,
            'foto_url'            => $this->foto_url,
            'cantidad_disponible' => $disponible,
            'cantidad_reservada'  => 0.0,
            'cantidad_libre'      => $disponible,
        ];
    }
}
