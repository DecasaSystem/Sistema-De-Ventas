<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['nombre', 'ciudad', 'direccion', 'telefono', 'activa', 'cerrada_en', 'es_fabrica',
                           'es_independientes', 'comisiones_compartidas', 'comision_periodicidad'];

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

        // Cerrada antes del día 1 del mes = cerrada en un mes anterior.
        $primero = $mes . '-01';

        return self::$cerradas[$mes] = collect(self::fechasDeCierre())
            ->filter(fn (string $fecha) => $fecha < $primero)
            ->map(fn () => true)->all();
    }

    /**
     * [tienda_id => 'Y-m-d' de cierre] de las que cerraron, leído UNA vez.
     *
     * Comisiones pregunta mes por mes (un cálculo recorre más de un año), y
     * cada pregunta eran dos viajes a la base —¿existe la columna?, ¿cuáles
     * cerraron?—: 40 consultas para una lista de dos o tres tiendas.
     */
    private static function fechasDeCierre(): array
    {
        if (self::$fechasDeCierre !== null) return self::$fechasDeCierre;

        if (! \Illuminate\Support\Facades\Schema::hasColumn('tiendas', 'cerrada_en')) {
            return self::$fechasDeCierre = [];
        }

        return self::$fechasDeCierre = static::whereNotNull('cerrada_en')->get(['id', 'cerrada_en'])
            ->mapWithKeys(fn ($t) => [(int) $t->id => $t->cerrada_en->toDateString()])
            ->all();
    }

    private static array $cerradas = [];
    private static ?array $fechasDeCierre = null;

    public static function olvidarCerradas(): void
    {
        self::$cerradas       = [];
        self::$fechasDeCierre = null;
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
