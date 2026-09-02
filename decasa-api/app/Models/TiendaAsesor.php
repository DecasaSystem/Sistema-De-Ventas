<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiendaAsesor extends Model
{
    protected $table = 'tienda_asesores_comision';

    protected $fillable = ['tienda_id', 'mes', 'vendedor_id'];

    public function tienda()
    {
        return $this->belongsTo(Tienda::class);
    }

    public function vendedor()
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    /**
     * Quiénes son el equipo de cada tienda en un mes, aunque nadie los haya
     * vuelto a registrar ese mes.
     *
     * El equipo casi nunca cambia: se arma una vez y así sigue hasta que
     * alguien entra o se pasa de tienda. Estaban guardados solo para julio y
     * en agosto la pantalla salía vacía, como si ninguna tienda tuviera gente
     * — es el mismo tropiezo que ya habían tenido las metas (ver
     * MetaTienda::vigentesEn).
     *
     * Se arrastra la última lista que se haya dejado. El mes en que se cambie
     * algo, esa lista manda de ahí en adelante.
     *
     * @return array<int, \Illuminate\Support\Collection>  [tienda_id => filas]
     */
    public static function vigentesEn(string $mes): array
    {
        // La misma pregunta se hace varias veces por petición —al abrir los
        // renglones del equipo, al repartir el pool, al pintar las metas— y es
        // una consulta a toda la tabla cada vez. Se resuelve una y se guarda.
        if (isset(self::$cache[$mes])) {
            return self::$cache[$mes];
        }

        $filas = static::with('vendedor:id,nombre')
            ->where('mes', '<=', $mes)->orderBy('mes')->get();

        // Al recorrer de mes viejo a nuevo, el último que queda por tienda es
        // el que rige.
        $ultimoMes = [];
        foreach ($filas as $f) {
            $ultimoMes[$f->tienda_id] = $f->mes;
        }

        $out = [];
        foreach ($filas as $f) {
            if ($f->mes === $ultimoMes[$f->tienda_id]) {
                $out[$f->tienda_id][] = $f;
            }
        }

        return self::$cache[$mes] = array_map(fn ($v) => collect($v), $out);
    }

    /** [mes => equipos]. Ver vigentesEn(). */
    private static array $cache = [];

    /** Se vuelve a preguntar: el equipo cambió. */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }

    /**
     * Deja escrita en ESTE mes la lista que hoy rige por arrastre.
     *
     * Hace falta antes de tocar el equipo: si en agosto se ve la lista de
     * julio y se quita a alguien, borrar esa fila cambiaría julio —un mes ya
     * pagado— en vez de agosto. Copiándola primero, cada mes queda con su
     * propia historia.
     */
    public static function materializar(int $tiendaId, string $mes): void
    {
        if (static::where('tienda_id', $tiendaId)->where('mes', $mes)->exists()) {
            return;
        }

        foreach (static::vigentesEn($mes)[$tiendaId] ?? [] as $fila) {
            static::create([
                'tienda_id'   => $tiendaId,
                'mes'         => $mes,
                'vendedor_id' => $fila->vendedor_id,
            ]);
        }

        self::olvidarCache();
    }
}
