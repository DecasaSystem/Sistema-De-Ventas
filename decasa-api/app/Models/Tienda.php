<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['nombre', 'ciudad', 'direccion', 'telefono', 'activa', 'cerrada_en', 'es_fabrica',
                           'es_independientes', 'comisiones_compartidas'];

    protected function casts(): array
    {
        return [
            'activa'            => 'boolean',
            'cerrada_en'        => 'date',
            'es_fabrica'        => 'boolean',
            'es_independientes' => 'boolean',
            // Si aqui la comision es del equipo o de cada quien. Ver la
            // migracion `cada_tienda_decide_si_comparte_comisiones`.
            'comisiones_compartidas' => 'boolean',
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

    /**
     * Las tiendas que ya estaban cerradas antes de que empezara ese mes.
     *
     * Comisiones arrastra meta y equipo de mes a mes; a una tienda cerrada
     * no se le arrastran a los meses de después del cierre (ver la migración
     * `tienda_cerrada_en`). El mes del cierre no cuenta como "después": ahí
     * todavía vendió y su gente estuvo.
     *
     * Las pruebas montan `tiendas` a mano y casi ninguna tiene la columna:
     * sin ella no hay cerradas, que es lo mismo que había antes.
     *
     * @return array<int, true>  [tienda_id => true]
     */
    public static function cerradasAntesDe(string $mes): array
    {
        if (isset(self::$cerradas[$mes])) return self::$cerradas[$mes];

        if (! \Illuminate\Support\Facades\Schema::hasColumn('tiendas', 'cerrada_en')) {
            return self::$cerradas[$mes] = [];
        }

        // Cerrada antes del día 1 del mes = cerrada en un mes anterior.
        return self::$cerradas[$mes] = static::whereNotNull('cerrada_en')
            ->whereDate('cerrada_en', '<', $mes . '-01')
            ->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
    }

    private static array $cerradas = [];

    public static function olvidarCerradas(): void
    {
        self::$cerradas = [];
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
