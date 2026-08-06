<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaTienda extends Model
{
    protected $table = 'metas_tienda';

    protected $fillable = ['tienda_id', 'mes', 'meta', 'divisor_asesores'];

    protected function casts(): array
    {
        return ['meta' => 'decimal:2'];
    }

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    /**
     * La meta que rige en un mes, aunque nadie la haya cargado ese mes.
     *
     * La meta es la misma todos los meses. Se sembro una vez para julio de
     * 2026 y no habia nada que la repitiera, asi que en agosto todas las
     * tiendas aparecian sin meta: el pool daba cero y nadie cobraba comision.
     *
     * En vez de tener que acordarse de crearlas cada mes, se arrastra la
     * ultima que se haya puesto. Si algun mes la meta cambia, se carga para
     * ese mes y esa manda.
     *
     * Devuelve [tienda_id_mes => fila] para todas las tiendas que alguna vez
     * tuvieron meta, resuelto para el mes pedido.
     */
    public static function vigentesEn(string $mes): array
    {
        $out = [];
        foreach (static::where('mes', '<=', $mes)->orderBy('mes')->get() as $m) {
            // Al ir de mes viejo a nuevo, la ultima que queda es la que rige.
            $out[$m->tienda_id] = $m;
        }
        return $out;
    }

    /** Igual pero para todos los meses a la vez, keyed 'tienda_mes'. */
    public static function mapaVigente(array $meses): array
    {
        $todas = static::orderBy('mes')->get()->groupBy('tienda_id');
        $out = [];
        foreach ($todas as $tiendaId => $filas) {
            foreach ($meses as $mes) {
                $vigente = null;
                foreach ($filas as $f) {
                    if ($f->mes <= $mes) $vigente = $f; else break;
                }
                if ($vigente) $out[$tiendaId . '_' . $mes] = $vigente;
            }
        }
        return $out;
    }
}
