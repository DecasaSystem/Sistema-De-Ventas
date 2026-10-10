<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * De qué es un gasto: arriendo, energía, software… Editables desde Finanzas.
 * Se desactivan, no se borran: los gastos viejos las nombran.
 *
 * - `naturaleza`: lo que entiende el negocio (fijo = el mismo monto cada vez).
 * - `varia_con_ventas`: lo que pide el punto de equilibrio (¿sube si se vende
 *   más?). La luz es "variable" para el negocio pero no sube con las ventas.
 * - `area`: en qué línea del estado de resultados cae (producción, ventas,
 *   administración, financiero).
 */
class CategoriaGasto extends Model
{
    protected $table = 'categorias_gasto';

    public const AREAS = ['produccion', 'ventas', 'administracion', 'financiero'];

    protected $fillable = [
        'nombre', 'grupo', 'naturaleza', 'varia_con_ventas', 'area',
        'codigo_puc', 'icono', 'orden', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'varia_con_ventas' => 'boolean',
            'activo'           => 'boolean',
            'orden'            => 'integer',
        ];
    }
}
