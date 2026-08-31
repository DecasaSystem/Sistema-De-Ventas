<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cómo se llama y con qué icono aparece un módulo en esta empresa.
 *
 * La `clave` es lo único que conoce el código; el nombre y el icono son
 * decisión del negocio. Por eso la clave no se edita desde ningún lado: si
 * cambiara, el módulo dejaría de encontrarse y la pantalla se quedaría con el
 * nombre de repuesto que trae escrito.
 */
class Modulo extends Model
{
    protected $table = 'modulos';

    protected $fillable = ['clave', 'nombre', 'icono', 'visible', 'orden'];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'orden'   => 'integer',
        ];
    }
}
