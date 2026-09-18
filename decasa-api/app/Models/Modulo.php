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
 *
 * Un módulo puede haber nacido de otro: `plantilla` dice de cuál. Espumas
 * nace de Telas, y el programa le muestra la misma pantalla con el nombre, el
 * icono y la `config` (unidad, singular, decimales) que la empresa le puso.
 * Los módulos de siempre no tienen plantilla.
 */
class Modulo extends Model
{
    protected $table = 'modulos';

    protected $fillable = ['clave', 'plantilla', 'nombre', 'icono', 'config', 'visible', 'orden'];

    /**
     * De qué módulos se puede sacar copia, y con qué config nacen.
     *
     * Telas es "un inventario por cantidad": marca, tipo, color, referencia,
     * foto, y una cantidad que se recarga y se descuenta. Eso mismo sirve para
     * espumas, hilos, láminas o cualquier insumo que se compre y se gaste.
     */
    public const PLANTILLAS = [
        'telas' => [
            'nombre' => 'Inventario por cantidad (como Telas)',
            'config' => ['unidad' => 'm', 'singular' => 'tela', 'decimales' => 2],
        ],
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'orden'   => 'integer',
            'config'  => 'array',
        ];
    }

    public function items()
    {
        return $this->hasMany(ModuloItem::class, 'modulo_id');
    }

    /** Nació de otro módulo: se puede borrar y su pantalla es la de la plantilla. */
    public function esCopia(): bool
    {
        return $this->plantilla !== null;
    }

    /**
     * La config completa: lo que puso la empresa encima de lo que trae la
     * plantilla, para que un campo que no se llenó tenga siempre un valor.
     */
    public function configCompleta(): array
    {
        $base = self::PLANTILLAS[$this->plantilla]['config'] ?? [];

        return array_merge($base, array_filter($this->config ?? [], fn ($v) => $v !== null && $v !== ''));
    }
}
