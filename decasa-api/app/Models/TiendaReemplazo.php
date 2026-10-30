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
    /**
     * Lo que se guarda o se borra fila por fila deja el caché sin efecto solo.
     * Las escrituras en bloque (`->where(...)->delete()`) no disparan esto:
     * esas siguen llamando a olvidarCache() a mano, como siempre.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::olvidarCache());
        static::deleted(fn () => self::olvidarCache());
    }

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
        $delEquipo = [];
        foreach ($equipoBase as $uid) {
            $pesos[(int) $uid] = $delMes;
            $delEquipo[(int) $uid] = true;
        }

        foreach (self::queSolapan($inicio, $fin) as $r) {
            $dias = self::diasDentro($r, $inicio, $fin);
            if ($dias <= 0) continue;

            $quien = (int) $r->usuario_id;

            if ((int) $r->tienda_id === $tiendaId) {
                // Vino a esta tienda: suma los días que estuvo. Salvo un
                // reemplazo en una tienda donde quien cubre cobra por lo que
                // vendió (regla reparto_reemplazo): ahí no entra al pool.
                if (! self::cobraPorSusVentas($r, $tiendaId, $mes)) {
                    $pesos[$quien] = min($delMes, ($pesos[$quien] ?? 0) + $dias);
                }
            } elseif (isset($delEquipo[$quien])) {
                // Se fue a cubrir a otra parte: esos días no los estuvo aquí.
                // Solo se le resta a quien es del equipo fijo (tiene el mes
                // entero): Genesis vino a Unicentro del 20 al 26 y del 27 se
                // fue a Norte; sus días aquí ya son solo los del 20 al 26, y
                // restarle los de Norte le dejaba 2 en vez de 7 —o 7, según en
                // qué orden se leyeran los movimientos—.
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
     * Quiénes estaban en la tienda un día concreto.
     *
     * El pool se reparte por días porque es la cuenta de todo un mes. Una
     * restauración no: se hizo un día, y ese 5% es de quienes estaban ahí ese
     * día. Repartirla por los días del mes le habría dado a Genesis el 14% de
     * una restauración que hizo ella, por haber llegado a la tienda el 27.
     *
     * Sale del equipo fijo, quitando a quien ese día andaba cubriendo en otra
     * parte y a quien estaba siendo cubierto, y sumando a quien había venido
     * —de reemplazo o trasladado—.
     *
     * @param  array<int> $equipoBase  ids del equipo fijo de la tienda
     * @return array<int>              ids de quienes estaban ese día
     */
    public static function equipoElDia(int $tiendaId, string $fecha, array $equipoBase): array
    {
        $dia = Carbon::parse($fecha)->startOfDay();
        $mes = $dia->copy()->startOfMonth();

        $equipo = [];
        foreach ($equipoBase as $uid) {
            $equipo[(int) $uid] = (int) $uid;
        }

        foreach (self::queSolapan($mes, $mes->copy()->endOfMonth()) as $r) {
            if (! self::cubreElDia($r, $dia)) continue;

            $quien = (int) $r->usuario_id;

            if ((int) $r->tienda_id === $tiendaId) {
                // Quien cubre y cobra por lo que vendió no reparte con el
                // equipo: su restauración es suya entera, y no se lleva parte
                // de las de los demás ni de lo que deja un independiente.
                if (! self::cobraPorSusVentas($r, $tiendaId, $mes->format('Y-m'))) {
                    $equipo[$quien] = $quien;
                }

                // Al que vino a cubrir le pertenece el puesto ese día, así que
                // el cubierto no cuenta: si contaran los dos, el 5% se partiría
                // entre uno más de los que de verdad estaban.
                if ($cubierto = (int) $r->reemplaza_a_id) {
                    unset($equipo[$cubierto]);
                }
            } else {
                // Ese día estaba en otra tienda.
                unset($equipo[$quien]);
            }
        }

        return array_values($equipo);
    }

    /**
     * ¿Este movimiento es un reemplazo en una tienda donde quien cubre cobra
     * un porcentaje de lo que vendió, en vez de entrar al pool por días?
     * (regla `reparto_reemplazo`, dueño 2026-10-29). Un traslado nunca: se
     * cambió de tienda y es del equipo.
     */
    public static function cobraPorSusVentas($r, int $tiendaId, string $mes): bool
    {
        return \App\Support\ReglasComision::hayFilas('reparto_reemplazo')
            && ($r->tipo ?? self::REEMPLAZO) === self::REEMPLAZO
            && (int) $r->reemplaza_a_id
            && \App\Support\ReglasComision::valor('reparto_reemplazo', $mes, $tiendaId) === 'sus_ventas';
    }

    /**
     * Los reemplazos de quienes cobran por lo que vendieron: [ [usuario_id,
     * tienda_id, desde, hasta|null], … ]. Vacío si ninguna tienda tiene esa
     * regla, sin preguntar nada a la base.
     *
     * @return array<int, array{0:int, 1:int, 2:string, 3:?string}>
     */
    public static function reemplazosPorSusVentas(): array
    {
        if (! \App\Support\ReglasComision::hayFilas('reparto_reemplazo')) return [];

        self::$todos ??= static::query()->get();

        return self::$todos
            ->filter(fn ($r) => ($r->tipo ?? self::REEMPLAZO) === self::REEMPLAZO && (int) $r->reemplaza_a_id)
            ->map(fn ($r) => [(int) $r->usuario_id, (int) $r->tienda_id,
                Carbon::parse($r->desde)->toDateString(), $r->hasta ? Carbon::parse($r->hasta)->toDateString() : null])
            ->values()->all();
    }

    /** Si este movimiento estaba vigente ese día. Sin `hasta`, sigue abierto. */
    private static function cubreElDia($r, Carbon $dia): bool
    {
        if (Carbon::parse($r->desde)->startOfDay()->gt($dia)) {
            return false;
        }

        return $r->hasta === null || Carbon::parse($r->hasta)->startOfDay()->gte($dia);
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

    /** Los reemplazos que tocan esta ventana. Se piden una vez por mes. */
    private static array $cache = [];

    private static function queSolapan(Carbon $inicio, Carbon $fin)
    {
        $clave = $inicio->format('Y-m');
        $desde = $inicio->toDateString();
        $hasta = $fin->toDateString();

        // La tabla se lee una vez por petición y cada mes se filtra en
        // memoria, con la misma condición que tenía la consulta: un cálculo
        // de comisiones recorre más de un año, y eran tantas consultas como
        // meses (ver docs/plan-rendimiento.md).
        self::$todos ??= static::query()->get();

        return self::$cache[$clave] ??= self::$todos
            ->filter(fn ($r) => Carbon::parse($r->desde)->toDateString() <= $hasta
                && ($r->hasta === null || Carbon::parse($r->hasta)->toDateString() >= $desde))
            ->values();
    }

    /** Todos los reemplazos. Ver queSolapan(). */
    private static ?\Illuminate\Support\Collection $todos = null;

    /** Se vuelve a preguntar a la base: algo cambió. */
    public static function olvidarCache(): void
    {
        self::$cache = [];
        self::$todos = null;
    }

    /**
     * Días de este reemplazo que caen dentro del mes.
     *
     * Sin `hasta` sigue abierto: cuenta hasta hoy, no hasta fin de mes, para
     * que un reemplazo en curso no cobre por adelantado días que todavía no
     * han pasado. "Hoy" es el de Colombia: con el reloj UTC del servidor, de
     * 7 p.m. en adelante ya contaba el día siguiente.
     */
    private static function diasDentro($r, Carbon $inicio, Carbon $fin): int
    {
        $desde = Carbon::parse($r->desde)->startOfDay()->max($inicio);
        $hasta = $r->hasta
            ? Carbon::parse($r->hasta)->startOfDay()->min($fin)
            : \App\Http\Controllers\ComisionController::hoy()->min($fin);

        return $hasta->lt($desde) ? 0 : (int) $desde->diffInDays($hasta) + 1;
    }
}
