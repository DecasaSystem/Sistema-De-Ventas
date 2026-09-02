<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Una estadía en otra tienda: quién fue, a dónde, desde cuándo y hasta
 * cuándo, y a quién estaba cubriendo.
 *
 * Existe porque el reparto del pool se hacía "entre tantos" y los reemplazos
 * no siempre son de un mes: alguien se va tres días o quince. Ver la
 * migración `reemplazos_entre_tiendas` para el porqué completo.
 */
class TiendaReemplazo extends Model
{
    protected $table = 'tienda_reemplazos';

    /** Ocupa el puesto de alguien: lo que gana uno lo pierde el otro. */
    public const REEMPLAZO = 'reemplazo';

    /** Se cambió de tienda: desde ese día el equipo es uno más grande. */
    public const TRASLADO = 'traslado';

    protected $fillable = ['tienda_id', 'tipo', 'usuario_id', 'reemplaza_a_id', 'desde', 'hasta', 'nota'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date'];
    }

    public function tienda()      { return $this->belongsTo(Tienda::class, 'tienda_id'); }
    public function usuario()     { return $this->belongsTo(Usuario::class, 'usuario_id'); }
    public function reemplazaA()  { return $this->belongsTo(Usuario::class, 'reemplaza_a_id'); }

    /**
     * Cuánto pesa cada persona en el reparto de una tienda ese mes, en días.
     *
     * Quien está en el equipo pesa el mes entero; de ahí se le restan los días
     * que lo cubrieron y los días que él estuvo en otra tienda. Quien llega
     * suma los días que estuvo.
     *
     * La diferencia entre las dos formas de llegar:
     *
     *   reemplazo → ocupa el puesto de alguien del equipo, así que la suma de
     *               las partes no cambia: si eran tres, se sigue partiendo en
     *               tres y lo único que se mueve es de quién es cada parte.
     *   traslado  → se cambió de tienda y no cubre a nadie: desde ese día el
     *               equipo es uno más grande y el pool se parte entre más.
     *
     * El peso nunca pasa del mes completo ni baja de cero.
     *
     * @param  array<int> $equipoBase  ids del equipo fijo de la tienda
     * @return array<int,float>        [usuario_id => días]
     */
    public static function pesosDelMes(int $tiendaId, string $mes, array $equipoBase): array
    {
        $inicio = Carbon::parse($mes . '-01')->startOfMonth();
        $fin    = $inicio->copy()->endOfMonth();
        $delMes = $fin->day;

        $pesos = [];
        foreach ($equipoBase as $uid) {
            $pesos[(int) $uid] = $delMes;
        }

        foreach (self::queSolapan($inicio, $fin) as $r) {
            $dias = self::diasDentro($r, $inicio, $fin);
            if ($dias <= 0) continue;

            $quien = (int) $r->usuario_id;

            if ((int) $r->tienda_id === $tiendaId) {
                // Vino a esta tienda: suma los días que estuvo.
                $pesos[$quien] = min($delMes, ($pesos[$quien] ?? 0) + $dias);
            } elseif (isset($pesos[$quien])) {
                // Se fue a cubrir a otra parte: esos días no los estuvo aquí.
                $pesos[$quien] = max(0, $pesos[$quien] - $dias);
            }

            // Y a quien cubrieron se le quitan esos mismos días: su parte de
            // esos días es de quien la reemplazó. Es lo que mantiene la cuenta
            // cuadrada —lo que pierde uno lo gana el otro— y por eso el total
            // repartido no cambia.
            //
            // En un traslado no hay nadie a quien cubrir: la columna viene
            // vacía, no se le resta a nadie y el equipo queda uno más grande.
            $cubierto = (int) $r->reemplaza_a_id;
            if ($cubierto && (int) $r->tienda_id === $tiendaId && isset($pesos[$cubierto])) {
                $pesos[$cubierto] = max(0, $pesos[$cubierto] - $dias);
            }
        }

        // Quien acabó con cero días no reparte: no estuvo.
        return array_filter($pesos, fn ($p) => $p > 0);
    }

    /**
     * En qué tiendas le toca pool a alguien ese mes por haber ido a cubrir.
     *
     * @return array<int>  ids de tienda
     */
    public static function tiendasDe(int $usuarioId, string $mes): array
    {
        $inicio = Carbon::parse($mes . '-01')->startOfMonth();
        $fin    = $inicio->copy()->endOfMonth();

        return self::queSolapan($inicio, $fin)
            ->where('usuario_id', $usuarioId)
            ->filter(fn ($r) => self::diasDentro($r, $inicio, $fin) > 0)
            ->pluck('tienda_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * En qué tiendas hubo movimiento ese mes —alguien llegó a cubrir o se
     * trasladó—. Sale de lo que ya está cargado, sin otra consulta.
     *
     * @return array<int>
     */
    public static function tiendasConMovimiento(string $mes): array
    {
        $inicio = Carbon::parse($mes . '-01')->startOfMonth();

        return self::queSolapan($inicio, $inicio->copy()->endOfMonth())
            ->pluck('tienda_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Los reemplazos que tocan esta ventana. Se piden una vez por mes. */
    private static array $cache = [];

    private static function queSolapan(Carbon $inicio, Carbon $fin)
    {
        $clave = $inicio->format('Y-m');

        return self::$cache[$clave] ??= static::query()
            ->whereDate('desde', '<=', $fin->toDateString())
            ->where(fn ($q) => $q->whereNull('hasta')
                                 ->orWhereDate('hasta', '>=', $inicio->toDateString()))
            ->get();
    }

    /** Se vuelve a preguntar a la base: algo cambió. */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }

    /**
     * Días de este reemplazo que caen dentro del mes.
     *
     * Sin `hasta` sigue abierto: cuenta hasta hoy, no hasta fin de mes, para
     * que un reemplazo en curso no cobre por adelantado días que todavía no
     * han pasado.
     */
    private static function diasDentro($r, Carbon $inicio, Carbon $fin): int
    {
        $desde = Carbon::parse($r->desde)->startOfDay()->max($inicio);
        $hasta = $r->hasta
            ? Carbon::parse($r->hasta)->startOfDay()->min($fin)
            : Carbon::now()->startOfDay()->min($fin);

        return $hasta->lt($desde) ? 0 : $desde->diffInDays($hasta) + 1;
    }
}
