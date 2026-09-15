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
        'variante_detalle',
        'tienda_origen_id',
        'cantidad',
        // Cuántas ya se entregaron. Caché de entrega_lineas: ver EntregaLinea.
        'cantidad_entregada',
        'precio_unitario',
        'es_personalizado',
        'fabricar_pedido',
        'es_restauracion',
        // Un mueble que ya existe y del que solo hay uno: no está en el
        // catálogo y tampoco pasa por el taller, porque ya está hecho.
        'producto_unico',
        // Mueble de stock al que se le cambia la tela en la fábrica: se
        // aparta como catálogo y a la vez entra al taller.
        'retapizar',
        'es_regalo',
        'usa_stock_tienda',
        // "Se lo lleva de una": el cliente sale de la tienda con esto en la
        // mano. Por producto, no por orden: el reloj se lo lleva, el mueble no.
        'llevar_ahora',
        'specs_personalizacion',
        'boceto_url',
        'boceto_fotos',
        'fecha_entrega_prom',
        // El cliente lo devolvió para cambiarlo por otro. Se queda en la orden
        // como rastro, pero deja de sumar al total.
        'devuelto_en',
        'motivo_devolucion',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario'       => 'decimal:2',
            'es_personalizado'      => 'boolean',
            'fabricar_pedido'       => 'boolean',
            'es_restauracion'       => 'boolean',
            'producto_unico'        => 'boolean',
            'retapizar'             => 'boolean',
            'es_regalo'             => 'boolean',
            'usa_stock_tienda'      => 'boolean',
            'llevar_ahora'          => 'boolean',
            'cantidad_entregada'    => 'integer',
            'specs_personalizacion' => 'array',
            'boceto_fotos'          => 'array',
            'fecha_entrega_prom'    => 'date',
            'devuelto_en'           => 'date',
        ];
    }

    /**
     * Clasifica el ítem para mostrarlo distinto en la orden:
     *   catalogo        → producto de inventario (sale de stock)
     *   retapizar       → producto de inventario que pasa por el taller a cambiarle la tela
     *   producto_unico  → mueble que ya existe, fuera de catálogo, no va al taller
     *   diseno_especial → producto que no existe en catálogo (a fabricar desde cero)
     *   fabricar        → producto del catálogo sin stock, mandado a producción
     *   personalizado   → producto existente al que se le cambian detalles
     */
    public function getTipoItemAttribute(): string
    {
        // Va antes que 'diseno_especial': una restauración también es un
        // personalizado sin producto_id, y sin esta marca se confundirían.
        if ($this->es_restauracion)      return 'restauracion';
        // Lo mismo el mueble único: por dentro es un personalizado sin
        // catálogo, y si no se mirara aquí saldría como "diseño especial",
        // que es exactamente lo contrario —algo que hay que fabricar—.
        if ($this->producto_unico)       return 'producto_unico';
        // Antes que 'catalogo': por dentro es un ítem de stock, y sin esta
        // marca saldría como uno más de inventario estando en el taller.
        if ($this->retapizar)            return 'retapizar';
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
        // Lo que se eligió al vender, palabra por palabra. Manda sobre todo lo
        // demás: si mañana alguien renombra la opción en el catálogo, la orden
        // tiene que seguir diciendo lo que se le vendió al cliente.
        if (($this->variante_detalle ?? '') !== '') {
            return $this->variante_detalle;
        }

        $partes = [];

        if ($this->relationLoaded('variante') ? $this->variante : ($this->variante_id ? $this->variante : null)) {
            $v = $this->variante;
            $partes = array_filter([$v->marca, $v->marca_tela, $v->nombre_color, $v->medida]);
        }

        // La combinación añade la medida/talla concreta dentro de esa tela.
        // Sólo el valor: el tipo se llama "Cama Miami medidas" y al lado del
        // nombre del producto no aporta nada, estorba.
        if ($this->combo_config_id && $this->comboConfig) {
            $opcion = $this->comboConfig->opcion->nombre ?? null;
            if ($opcion) $partes[] = $opcion;
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

    /**
     * El texto de variante que se guarda al crear un ítem.
     *
     * Lo normal es que lo mande la pantalla, que es la que sabe todo lo que se
     * eligió (un producto puede llevar medida y color a la vez). Si no viene
     * —una app abierta desde antes, o la orden creada desde otro lado— se
     * reconstruye de la opción o de la tela, para que el ítem no se guarde
     * mudo teniendo el dato a mano.
     */
    public static function detalleDeVariante(?string $detalle, $comboConfigId = null, $varianteId = null): ?string
    {
        $detalle = trim((string) $detalle);
        if ($detalle !== '') {
            return mb_substr($detalle, 0, 200);
        }

        if ($comboConfigId && $cfg = ProductoVarianteConfig::with('opcion')->find($comboConfigId)) {
            if ($cfg->opcion?->nombre) {
                return $cfg->opcion->nombre;
            }
        }

        if ($varianteId && $v = ProductoVariante::find($varianteId)) {
            $texto = trim(implode(' · ', array_filter([$v->marca, $v->marca_tela, $v->nombre_color, $v->medida])));
            if ($texto !== '') {
                return $texto;
            }
        }

        return null;
    }

    /**
     * Lo que se guarda de un mueble de stock que se manda a cambiar de tela.
     *
     * La línea de variante pasa a decir "de qué tela a qué tela": es lo que
     * necesita el taller para saber qué sofá recoger y quien despacha para
     * no mandar el que no es. La tela que tiene HOY se guarda aparte en las
     * specs (`tela_original`), y las marcas variante_marca/variante_color se
     * quitan: describen la tela vieja y en la orden saldrían como si fuera
     * la que se vendió.
     *
     * Se usa igual al crear la orden, al agregar el ítem desde editar y al
     * marcar uno que ya estaba: si cada sitio lo armara a su manera, la
     * orden diría una cosa y el taller otra.
     *
     * @return array{0: string, 1: array}  [variante_detalle, specs]
     */
    public static function armarRetapizado(?string $telaActual, array $specs): array
    {
        unset($specs['variante_marca'], $specs['variante_color']);

        $telaActual = trim((string) $telaActual);
        if ($telaActual !== '') {
            $specs = ['tela_original' => $telaActual] + $specs;
        }

        $detalle = ($telaActual !== '' ? $telaActual : 'Tela actual') . ' → ' . trim((string) ($specs['tela'] ?? ''));

        return [mb_substr(trim($detalle), 0, 200), $specs];
    }

    /**
     * Al dejar de retapizarlo vuelve a ser un ítem de stock cualquiera: la
     * línea de variante vuelve a la tela que tiene, y las specs se quedan
     * solo con el rastro de la variante, como las de cualquier otro.
     *
     * @return array{0: ?string, 1: ?array}  [variante_detalle, specs]
     */
    public function deshacerRetapizado(): array
    {
        $specs   = $this->specs_personalizacion ?? [];
        $detalle = $specs['tela_original'] ?? null;
        if ($detalle === null) {
            $detalle = self::detalleDeVariante(null, $this->combo_config_id, $this->variante_id);
        }

        $rastro = null;
        if ($this->variante_id && $v = ProductoVariante::find($this->variante_id)) {
            $rastro = ['variante_marca' => $v->marca_tela, 'variante_color' => $v->nombre_color];
        }

        return [$detalle, $rastro];
    }

    public function comboConfig()
    {
        return $this->belongsTo(ProductoVarianteConfig::class, 'combo_config_id');
    }

    /** ¿Sigue siendo parte de lo que el cliente va a recibir? */
    public function estaVivo(): bool
    {
        return $this->devuelto_en === null;
    }

    /**
     * Cuántas unidades le faltan al cliente por recibir de este producto.
     * Lo devuelto para cambio ya no cuenta: no es de la orden.
     */
    public function pendienteEntregar(): int
    {
        if (! $this->estaVivo()) return 0;

        return max(0, (int) $this->cantidad - (int) $this->cantidad_entregada);
    }

    /**
     * ¿Tiene trabajo en el taller?
     *
     * Lo personalizado y lo que se fabrica desde cero, sí; el mueble único no
     * (ya está hecho). Y el de stock al que se le cambia la tela también,
     * aunque por inventario sea un ítem de catálogo: ese sofá no se puede
     * entregar hasta que vuelva de la fábrica.
     *
     * Es LA pregunta que se hacía en varios sitios como
     * `es_personalizado && ! producto_unico`; está aquí para que la marca de
     * retapizar cuente en todos ellos y no se olvide en ninguno.
     */
    public function vaAlTaller(): bool
    {
        if ($this->retapizar) return true;

        return $this->es_personalizado && ! $this->producto_unico;
    }

    /**
     * ¿Se puede entregar hoy?
     *
     * Lo de catálogo sí siempre (está apartado en la tienda), lo que ya está
     * hecho también; lo que pasa por el taller —se fabrica, o se retapiza—,
     * solo cuando el taller lo dio por listo. Antes la orden entera esperaba
     * a que TODO estuviera listo, y el reloj se quedaba en la tienda hasta
     * que saliera el mueble.
     */
    public function estaListoParaEntregar(): bool
    {
        if ($this->pendienteEntregar() <= 0) return false;
        if (! $this->vaAlTaller())            return true;

        $produccion = $this->relationLoaded('produccion') ? $this->produccion : $this->produccion()->first();

        // Sin producción —una orden vieja, o todavía en cotización— no hay
        // quién la haya dado por lista.
        return $produccion !== null && $produccion->estado === 'listo';
    }

    public function lineasEntrega()
    {
        return $this->hasMany(EntregaLinea::class, 'orden_item_id');
    }

    /** Los que cuentan para el total: lo devuelto ya no se cobra. */
    public function scopeVivos($query)
    {
        return $query->whereNull('devuelto_en');
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
