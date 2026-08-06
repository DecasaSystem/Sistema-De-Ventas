<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenItem extends Model
{
    protected $table = 'orden_items';

    public $timestamps = false;

    // bocetos_list junta boceto_url y boceto_fotos en una sola lista; se expone
    // para que la pantalla de editar pueda mostrarlos y reemplazarlos sin tener
    // que rearmar esa mezcla por su cuenta.
    protected $appends = ['tipo_item', 'bocetos_list', 'variante_texto'];

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
        'es_restauracion',
        'es_regalo',
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
            'es_restauracion'       => 'boolean',
            'es_regalo'             => 'boolean',
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
        // Va antes que 'diseno_especial': una restauración también es un
        // personalizado sin producto_id, y sin esta marca se confundirían.
        if ($this->es_restauracion)      return 'restauracion';
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

    /**
     * Qué variante exacta se vendió, en una sola línea: tela, color, medida.
     *
     * El nombre del producto solo no basta para despachar. "SOFA CONFORT" hay
     * de varias telas, y el que arma el pedido no puede adivinar cuál: si no
     * está escrito, se manda el que no es. Por eso se muestra en la orden y en
     * el PDF, no solo en el inventario.
     *
     * Se arma aquí y no en cada pantalla para que la orden, el PDF y el acta
     * digan exactamente lo mismo.
     */
    public function getVarianteTextoAttribute(): ?string
    {
        $partes = [];

        if ($this->relationLoaded('variante') ? $this->variante : ($this->variante_id ? $this->variante : null)) {
            $v = $this->variante;
            $partes = array_filter([$v->marca, $v->marca_tela, $v->nombre_color, $v->medida]);
        }

        // La combinación añade la medida/talla concreta dentro de esa tela.
        if ($this->combo_config_id && $this->comboConfig) {
            $cfg   = $this->comboConfig;
            $tipo  = $cfg->tipo->nombre ?? null;
            $opcion = $cfg->opcion->nombre ?? null;
            if ($opcion) $partes[] = trim(($tipo ? "$tipo " : '').$opcion);
        }

        // Los personalizados no llevan variante de catálogo: la tela elegida
        // queda en las specs. Es el mismo dato para quien despacha.
        if (! $partes) {
            $specs  = $this->specs_personalizacion ?? [];
            $partes = array_filter([
                $specs['variante_marca'] ?? null,
                $specs['variante_color'] ?? null,
            ]);
        }

        $texto = trim(implode(' · ', $partes));
        return $texto !== '' ? $texto : null;
    }

    public function comboConfig()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'combo_config_id');
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
