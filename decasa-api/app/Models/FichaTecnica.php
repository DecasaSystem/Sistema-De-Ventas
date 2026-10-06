<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FichaTecnica extends Model
{
    protected $table = 'fichas_tecnicas';

    protected $fillable = [
        'nombre',
        'categoria',
        'producto_id',
        'costo_materiales',
        'costo_mano_obra',
        'costo_total',
        'ruta_excel',
        'foto_url',
    ];

    protected function casts(): array
    {
        return [
            'costo_materiales' => 'decimal:2',
            'costo_mano_obra'  => 'decimal:2',
            'costo_total'      => 'decimal:2',
        ];
    }

    public function items()
    {
        return $this->hasMany(FichaTecnicaItem::class)->orderBy('orden');
    }

    /** Producto del catálogo que se fabrica con esta ficha (para el margen). */
    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    /** Recalcula los totales de una ficha a partir de sus ítems. */
    public static function recalcularTotales(int $fichaId): void
    {
        $items           = FichaTecnicaItem::where('ficha_tecnica_id', $fichaId)->get(['subtotal', 'es_mano_obra']);
        $costoMateriales = $items->where('es_mano_obra', false)->sum('subtotal');
        $costoManoObra   = $items->where('es_mano_obra', true)->sum('subtotal');

        static::where('id', $fichaId)->update([
            'costo_materiales' => $costoMateriales,
            'costo_mano_obra'  => $costoManoObra,
            'costo_total'      => $costoMateriales + $costoManoObra,
        ]);
    }
}
