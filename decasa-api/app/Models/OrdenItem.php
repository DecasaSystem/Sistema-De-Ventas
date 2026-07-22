<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenItem extends Model
{
    protected $table = 'orden_items';

    public $timestamps = false;

    protected $appends = ['tipo_item'];

    protected $fillable = [
        'orden_id',
        'producto_id',
        'nombre_custom',
        'categoria_custom',
        'variante_id',
        'combo_config_id',
        'tienda_origen_id',
        'cantidad',
        'precio_unitario',
        'es_personalizado',
        'fabricar_pedido',
        'usa_stock_tienda',
        'specs_personalizacion',
        'boceto_url',
        'boceto_fotos',
        'fecha_entrega_prom',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario'       => 'decimal:2',
            'es_personalizado'      => 'boolean',
            'fabricar_pedido'       => 'boolean',
            'usa_stock_tienda'      => 'boolean',
            'specs_personalizacion' => 'array',
            'boceto_fotos'          => 'array',
            'fecha_entrega_prom'    => 'date',
        ];
    }

    /**
     * Clasifica el ítem para mostrarlo distinto en la orden:
     *   catalogo        → producto de inventario (sale de stock)
     *   diseno_especial → producto que no existe en catálogo (a fabricar desde cero)
     *   fabricar        → producto del catálogo sin stock, mandado a producción
     *   personalizado   → producto existente al que se le cambian detalles
     */
    public function getTipoItemAttribute(): string
    {
        if (! $this->es_personalizado)   return 'catalogo';
        if ($this->producto_id === null) return 'diseno_especial';
        if ($this->fabricar_pedido)      return 'fabricar';
        return 'personalizado';
    }

    public function getBocetosListAttribute(): array
    {
        if ($this->boceto_fotos) {
            return $this->boceto_fotos;
        }
        return $this->boceto_url ? [$this->boceto_url] : [];
    }

    public function orden()
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function produccion()
    {
        return $this->hasOne(Produccion::class, 'orden_item_id');
    }

    public function variante()
    {
        return $this->belongsTo(ProductoVariante::class, 'variante_id');
    }

    public function tiendaOrigen()
    {
        return $this->belongsTo(Tienda::class, 'tienda_origen_id');
    }
}
