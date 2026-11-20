<?php

namespace App\Services;

use App\Http\Controllers\ComisionController;
use App\Models\Comision;
use App\Models\Orden;
use App\Models\OrdenMensaje;
use App\Models\Usuario;
use App\Support\CachesDePeticion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La bonificación que se pagó por una venta que después se canceló
 * (usuario, 2026-11-19).
 *
 * Antes lo pagado se quedaba como estaba: la venta dejaba de contar, pero la
 * plata ya había salido y nadie la recuperaba. Decisiones del usuario:
 *
 *  - Se le descuenta a quien la cobró de más, y en una tienda con meta al
 *    EQUIPO: esa venta ayudó a armar el pool de todos. Se rehace la cuenta del
 *    mes sin ella y a cada uno se le descuenta lo que cobró de más según sus
 *    días. Si sin la venta la tienda no llegaba a la meta, es el pool entero.
 *  - Solo del próximo pago: queda como un anticipo más en el libro
 *    (AnticiposComision), que se resta solo cuando se le pague; si no alcanza,
 *    sigue debiendo el resto. Nunca da un pago negativo.
 *  - Solo las cancelaciones de aquí en adelante: lo ya cancelado no se toca.
 *
 * Cómo se calcula: una foto de lo que le toca a cada uno en el mes ANTES de
 * cancelar y otra DESPUÉS, con la misma cuenta de la pantalla
 * (ComisionController::resumenDelMes). Se cobra solo lo que cambió por esta
 * cancelación, nunca más de lo que se le pagó:
 *
 *     de más = max(0, pagado − le toca después) − max(0, pagado − le toca antes)
 *
 * Así, lo que ya estuviera de más por otra razón no se le carga a esta orden.
 * En el 5 % directo (independientes, restauraciones, la parte del almacén, la
 * tienda sin meta) es lo que se pagó por esa orden. Las restauraciones del
 * bolsón de los independientes, con la misma resta sobre su parte del bolsón.
 *
 * Lo mismo cuando la orden no se cancela pero BAJA de valor después de pagarse
 * (se quita o se cambia un producto, se cancela lo que faltaba, una devolución;
 * usuario, 2026-11-19): ComisionController::sincronizarValorOrden ahora le baja
 * el valor también al renglón pagado —así la meta y el pool del mes cuentan lo
 * que la venta vale hoy— y llama a alBajarElValor() antes. En el 5 % directo
 * se cobra la misma proporción en que bajó (pagó $500.000 sobre $10 M, la
 * orden queda en $6 M: $200.000). Si la orden sube, nada cambia.
 *
 * Las tiendas trimestrales no entran: su pool queda quieto al primer pago
 * (cargarPoolsTrimestrales), así que cancelar después no lo mueve.
 */
class BonificacionCobradaDeMas
{
    /** Prefijo de la clave en `comision_anticipos` (cabe en los 40 caracteres). */
    public const PREFIJO = 'demas:';

    private const FORMAS_POOL = ['pool', ComisionController::ORIGEN_PARTE_POOL];

    public static function esCobroDeMas(?string $clave): bool
    {
        return $clave !== null && str_starts_with($clave, self::PREFIJO);
    }

    /**
     * Antes de cancelar, dentro de la transacción. Deja anotado para después
     * de guardar la cancelación el descuento de lo que se haya pagado de más.
     */
    public static function alCancelar(Orden $orden, Usuario $usuario): void
    {
        self::registrar($orden, $usuario);
    }

    /**
     * La orden va a valer menos (se quitó o se cambió un producto, una
     * devolución, un reembolso de garantía…) y su bonificación ya se pagó
     * (usuario, 2026-11-19). Lo llama ComisionController::sincronizarValorOrden
     * ANTES de bajarles el valor a los renglones.
     */
    public static function alBajarElValor(Orden $orden, ?Usuario $usuario = null): void
    {
        self::registrar($orden, $usuario ?? (auth()->user() instanceof Usuario ? auth()->user() : null));
    }

    /**
     * Un super admin le cambió la fecha de la venta y la orden se va de un mes
     * cuyo pool ya se pagó (CambiarFechaDeVenta, usuario 2026-11-20). Sin ella
     * ese mes pudo quedar por debajo: se cobra igual que al cancelar. Lo que
     * gana el mes al que llega lo paga solo el libro del pool (le toca − pagado).
     * Antes de mover la fecha, dentro de la transacción.
     */
    public static function alMoverDeMes(Orden $orden, Usuario $usuario): void
    {
        self::registrar($orden, $usuario, 'mover');
    }

    /**
     * Órdenes que ya tienen la foto de "antes" esperando el final de la
     * transacción. Cancelar desde Devoluciones también baja el valor: con dos
     * fotos, lo que cambió entre la primera y la segunda se cobraría dos veces.
     * La primera ya cubre todo el cambio.
     *
     * @var array<int, true>
     */
    private static array $pendientes = [];

    private static bool $calculando = false;

    private static function registrar(Orden $orden, ?Usuario $usuario, ?string $motivo = null): void
    {
        if (self::$calculando || ! AnticiposComision::hayTablas()) return;
        if (isset(self::$pendientes[(int) $orden->id])) return;

        try {
            $antes = self::foto($orden);
        } catch (\Throwable $e) {
            // Nunca frenar el cambio de la orden por esto: se avisa en el log.
            Log::error('[bonificacion-de-mas] foto antes: ' . $e->getMessage(), ['orden_id' => $orden->id]);
            return;
        }
        if (! $antes) return;

        self::$pendientes[(int) $orden->id] = true;
        DB::afterCommit(function () use ($orden, $usuario, $antes, $motivo) {
            unset(self::$pendientes[(int) $orden->id]);
            try {
                self::cobrar($orden->fresh(), $usuario, $antes, $motivo);
            } catch (\Throwable $e) {
                Log::error('[bonificacion-de-mas] cobrar: ' . $e->getMessage(), ['orden_id' => $orden->id]);
            }
        });
    }

    public static function olvidarPendientes(): void
    {
        self::$pendientes = [];
    }

    /**
     * Lo que le toca y lo que se le pagó a cada uno en los meses de la orden
     * que ya tienen algo pagado. Null si no hay nada pagado: no hay qué cobrar.
     *
     * @return array<string, array>|null  [mes => cuenta]
     */
    public static function foto(Orden $orden): ?array
    {
        $meses = Comision::where('orden_id', $orden->id)->pluck('mes_venta')
            ->push(ComisionController::mesDeOrden($orden))
            ->filter()->unique()
            ->filter(fn ($mes) => Comision::where('mes_venta', $mes)->where('estado', 'pagada')->exists())
            ->values();

        if ($meses->isEmpty()) return null;

        $out = [];
        foreach ($meses as $mes) {
            $out[$mes] = self::cuentaDelMes((string) $mes, (int) $orden->id);
        }

        return $out;
    }

    /**
     * La cuenta de un mes: pool por persona y tienda, lo directo de esta orden
     * y el bolsón de los independientes.
     */
    private static function cuentaDelMes(string $mes, int $ordenId): array
    {
        // Como el simulador de reglas: resumenDelMes escribe (abre renglones
        // de quien no vendió), así que corre dentro de una transacción que se
        // deshace siempre, y sin cachés de antes ni de después.
        // Mientras se calcula, nada de lo que corra adentro abre otra foto.
        self::$calculando = true;
        // olvidarTodo() también borra la lista de pendientes: se guarda y se repone.
        $pendientes = self::$pendientes;
        DB::beginTransaction();
        try {
            CachesDePeticion::olvidarTodo();
            $filas = app(ComisionController::class)->resumenDelMes($mes);
            $indep = ComisionIndependientes::delMes($mes);
        } finally {
            DB::rollBack();
            CachesDePeticion::olvidarTodo();
            self::$pendientes = $pendientes;
            self::$calculando = false;
        }

        $pool    = [];
        $directo = [];
        $vistas  = [];
        foreach ($filas as $f) {
            if (! empty($f['es_independiente'])) continue;
            // Persona y tienda: la lista puede venir sin claves y en otro orden.
            $clave = (int) $f['vendedor_id'] . '_' . (int) $f['tienda_id'];
            $pagadoPool = 0.0;
            foreach ($f['ordenes'] ?? [] as $o) {
                $vistas[(int) $o['id']] = true;
                if (($o['estado'] ?? null) !== 'pagada') continue;
                if (in_array($o['forma_pago'] ?? 'pool', self::FORMAS_POOL, true)) {
                    $pagadoPool += (float) $o['monto_comision'];
                } elseif ((int) ($o['orden_id'] ?? 0) === $ordenId) {
                    $directo[(int) $o['id']] = ['vendedor_id' => (int) $f['vendedor_id'],
                        'pagado' => (float) $o['monto_comision'], 'valor' => (float) $o['valor_orden']];
                }
            }
            if (($f['periodicidad'] ?? 'mensual') === 'trimestral') continue;
            $pool[$clave] = [
                'vendedor_id' => (int) $f['vendedor_id'],
                'le_toca'     => (float) ($f['comision_asesor'] ?? 0),
                'pagado'      => $pagadoPool,
            ];
        }

        // Lo pagado de esta orden que no salió en las tarjetas (las ventas
        // propias de los independientes van por su servicio, no por ahí).
        foreach (Comision::where('orden_id', $ordenId)->where('estado', 'pagada')->get() as $c) {
            if (isset($vistas[(int) $c->id])) continue;
            if (in_array($c->forma_pago_pagada, self::FORMAS_POOL, true)) continue;
            $directo[(int) $c->id] = ['vendedor_id' => (int) $c->vendedor_id,
                'pagado' => (float) $c->monto_comision, 'valor' => (float) $c->valor_orden];
        }

        $bolson = [];
        foreach ($indep['independientes'] ?? [] as $i) {
            $vid = (int) $i['vendedor_id'];
            $bolson[$vid] = [
                'le_toca' => (float) ($i['comision_restauraciones_lista'] ?? 0),
                'pagado'  => (float) Comision::where('vendedor_id', $vid)->where('mes_venta', $mes)
                    ->where('origen', ComisionIndependientes::ORIGEN_BOLSON)->where('estado', 'pagada')
                    ->sum('monto_comision'),
            ];
        }

        return ['pool' => $pool, 'directo' => $directo, 'bolson' => $bolson];
    }

    /**
     * Después de guardar la cancelación: rehace la cuenta y anota a cada uno
     * lo que cobró de más por esta orden.
     *
     * @return array<int, float>  [vendedor_id => monto anotado]
     */
    public static function cobrar(Orden $orden, ?Usuario $usuario, array $antes, ?string $motivo = null): array
    {
        $cancelada = $orden->estado === 'cancelado';
        $semovio   = $motivo === 'mover';
        $porPersona = [];   // vid => [mes => monto]
        $sumar = function (int $vid, string $mes, float $monto) use (&$porPersona) {
            if ($monto < 0.5) return;
            $porPersona[$vid][$mes] = ($porPersona[$vid][$mes] ?? 0) + $monto;
        };
        $deMas = fn (float $pagado, float $antes, float $despues) =>
            max(0.0, $pagado - $despues) - max(0.0, $pagado - $antes);

        foreach ($antes as $mes => $a) {
            $d = self::cuentaDelMes((string) $mes, (int) $orden->id);

            foreach ($a['pool'] as $clave => $p) {
                if ($p['pagado'] <= 0) continue;
                $despues = (float) ($d['pool'][$clave]['le_toca'] ?? 0);
                $sumar($p['vendedor_id'], $mes, $deMas($p['pagado'], $p['le_toca'], $despues));
            }
            // El 5 % directo: cancelada, todo lo pagado por esa orden; si solo
            // bajó, la misma proporción en que bajó su valor.
            foreach ($a['directo'] as $id => $r) {
                if ($cancelada) {
                    $sumar($r['vendedor_id'], $mes, $r['pagado']);
                    continue;
                }
                $nuevo = (float) (Comision::whereKey($id)->value('valor_orden') ?? 0);
                if ($r['valor'] > 0 && $nuevo < $r['valor']) {
                    $sumar($r['vendedor_id'], $mes, $r['pagado'] * ($r['valor'] - $nuevo) / $r['valor']);
                }
            }
            foreach ($a['bolson'] as $vid => $b) {
                if ($b['pagado'] <= 0) continue;
                $despues = (float) ($d['bolson'][$vid]['le_toca'] ?? 0);
                $sumar((int) $vid, $mes, $deMas($b['pagado'], $b['le_toca'], $despues));
            }
        }

        if (! $porPersona) return [];

        $ref = $orden->referencia;
        $anotado = [];
        $porque = $semovio
            ? "la orden {$ref} se pasó a otro mes después de pagar la bonificación"
            : ($cancelada
                ? "la orden {$ref} se canceló después de pagar la bonificación"
                : "la orden {$ref} bajó de valor después de pagar la bonificación");
        DB::transaction(function () use ($porPersona, $orden, $usuario, $porque, &$anotado) {
            foreach ($porPersona as $vid => $meses) {
                foreach ($meses as $mes => $monto) {
                    $monto = round($monto);
                    if ($monto < 1) continue;
                    DB::table('comision_anticipos')->insert([
                        'vendedor_id' => $vid, 'tipo' => AnticiposComision::ANTICIPO, 'mes' => $mes,
                        'monto' => $monto,
                        'clave' => self::PREFIJO . "{$vid}:{$orden->id}:" . bin2hex(random_bytes(4)),
                        'editado_a_mano' => true,
                        'nota' => mb_substr("Cobrado de más: {$porque}", 0, 200),
                        'creado_por' => $usuario?->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $anotado[$vid] = ($anotado[$vid] ?? 0) + $monto;
                    ComisionController::anotar('cobro_de_mas', null, $mes, [
                        'vendedor_id' => $vid, 'orden_id' => $orden->id, 'monto' => $monto,
                    ]);
                }
            }
        });

        if (! $anotado) return [];

        $nombres = Usuario::whereIn('id', array_keys($anotado))->pluck('nombre', 'id');
        $pesos   = fn ($m) => '$' . number_format($m, 0, ',', '.');
        $lista   = collect($anotado)->map(fn ($m, $vid) => ($nombres[$vid] ?? "#{$vid}") . ' ' . $pesos($m))->implode(', ');

        OrdenMensaje::create([
            'orden_id' => $orden->id, 'usuario_id' => $usuario?->id,
            'mensaje'  => '💸 La bonificación de esta venta ya se había pagado'
                        . ($semovio ? ' y la venta se pasó a otro mes' : ($cancelada ? '' : ' y la orden bajó de valor'))
                        . ". Se descuenta del próximo pago: {$lista}.",
        ]);

        foreach ($anotado as $vid => $monto) {
            NotificacionService::crear(
                'cancelado',
                'Se te descuenta una bonificación',
                ucfirst($porque) . ". Se te descuentan {$pesos($monto)} del próximo pago.",
                ['orden_id' => $orden->id],
                (int) $vid,
            );
        }

        return $anotado;
    }
}
