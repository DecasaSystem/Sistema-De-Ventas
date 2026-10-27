<?php

namespace App\Http\Controllers;

use App\Models\Comision;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Support\Esquema;
use App\Support\ReglasComision;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas de comisión que se cambian sin programar (Fase 1 de
 * docs/plan-comisiones-personalizables.md).
 *
 * Decisiones del dueño (2026-10-27):
 *   - las ve quien tiene acceso a Comisiones; las cambia solo quien tiene el
 *     permiso aparte `acceso_reglas_comision`;
 *   - se pueden poner para todos, para una tienda o para una persona, y gana
 *     la más específica;
 *   - hacia un mes que ya pasó, solo si ese mes no tiene comisiones pagadas
 *     de quienes toca la regla, y con confirmación. Lo pagado nunca se mueve.
 *
 * Cada cambio es una fila "desde tal mes": la historia queda (cada mes se
 * calcula con lo que regía entonces). Cambiar el valor de una fila que ya
 * existe con el mismo "desde" la reemplaza; quitarla vuelve a lo de antes.
 */
class ReglaComisionController extends Controller
{
    /** Los medios de pago que existen (PagoController), para la lista del datáfono. */
    private const METODOS_PAGO = ['efectivo', 'transferencia', 'tarjeta', 'addi', 'otro'];

    /** Lo que se puede poner en cada regla que no es una fracción ni un sí/no. */
    private const RANGOS = [
        'iva_divisor'  => [1, 2],
        'dia_pago'     => [1, 31],
        'meses_espera' => [0, 12],
    ];

    // GET /api/comisiones/reglas
    public function index(Request $request)
    {
        $usuario = $request->user();
        if (! $usuario->acceso_comisiones) {
            return response()->json(['error' => 'Sin acceso'], 403);
        }

        $mes = ComisionController::hoy()->format('Y-m');
        $filas = self::hayTabla()
            ? DB::table('comision_reglas as r')
                ->leftJoin('usuarios as u', 'u.id', '=', 'r.creado_por')
                ->orderBy('r.clave')->orderBy('r.desde_mes')->orderBy('r.id')
                ->get(['r.*', 'u.nombre as creado_por_nombre'])
            : collect();

        $tiendas  = Tienda::pluck('nombre', 'id');
        $personas = Usuario::whereIn('id', $filas->where('alcance', 'usuario')->pluck('alcance_id'))->pluck('nombre', 'id');

        return response()->json([
            'puede_editar' => self::puedeEditar($usuario),
            'mes_actual'   => $mes,
            'metodos_pago' => self::METODOS_PAGO,
            'grupos'       => collect(ReglasComision::GRUPOS)->map(fn ($g, $id) => ['id' => $id] + $g)->values(),
            'se_ven_al_recalcular' => ReglasComision::SE_VEN_AL_RECALCULAR,
            // Para escoger a quién va una excepción.
            'tiendas'  => Tienda::where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'personas' => Usuario::where('activo', true)->whereIn('rol', ['vendedor', 'supervisor'])
                ->orderBy('nombre')->get(['id', 'nombre', 'tienda_default_id'])
                ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->nombre, 'tienda' => $tiendas[$u->tienda_default_id] ?? null]),
            'catalogo'     => collect(ReglasComision::CATALOGO)->map(fn ($r, $clave) => [
                'clave'    => $clave,
                'tipo'     => $r['tipo'],
                'nombre'   => $r['nombre'],
                'explica'  => $r['explica'],
                'alcances' => $r['alcances'],
                'defecto'  => $r['defecto'],
                'rango'    => self::RANGOS[$clave] ?? null,
                // Las formas de calcular que se pueden escoger (tipo opción).
                'opciones' => isset($r['opciones'])
                    ? collect($r['opciones'])->map(fn ($o, $v) => ['valor' => $v] + $o)->values() : null,
                // Lo que rige hoy para todos (sin excepciones de tienda o persona).
                'vigente'  => ReglasComision::valor($clave, $mes),
            ])->values(),
            'reglas' => $filas->map(fn ($f) => [
                'id'          => (int) $f->id,
                'clave'       => $f->clave,
                'alcance'     => $f->alcance,
                'alcance_id'  => $f->alcance_id !== null ? (int) $f->alcance_id : null,
                'alcance_nombre' => match ($f->alcance) {
                    'tienda'  => $tiendas[$f->alcance_id] ?? "Tienda #{$f->alcance_id}",
                    'usuario' => $personas[$f->alcance_id] ?? "#{$f->alcance_id}",
                    default   => 'Todos',
                },
                'valor'       => $f->valor === null ? null : ReglasComision::normalizar($f->clave, json_decode((string) $f->valor, true)),
                'desde_mes'   => $f->desde_mes,
                'nota'        => $f->nota,
                'creado_por'  => $f->creado_por_nombre,
                'creado_at'   => $f->created_at,
                'actualizado_at' => $f->updated_at,
            ])->values(),
        ]);
    }

    /**
     * GET /api/comisiones/reglas/vigentes?mes=YYYY-MM
     *
     * Lo que rige en un mes, corto, para los textos (Fase 3): la Ayuda, el
     * desglose, los totales de la tienda y el Excel decían "5 %" y "por días"
     * escritos a mano; con una regla cambiada habrían dicho 5 % mientras se
     * pagaba 6 %. La pantalla resuelve cada caso igual que el cálculo:
     * persona > tienda > general.
     */
    public function vigentes(Request $request)
    {
        $mes = (string) $request->query('mes', '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $mes = ComisionController::hoy()->format('Y-m');
        }

        // La Ayuda la abre cualquiera (dueño, 2026-10-15): la regla general no
        // es de nadie. Quién tiene una excepción (a qué persona se le paga
        // distinto) sí: eso solo lo ve quien tiene Comisiones.
        if (! $request->user()->acceso_comisiones) {
            return response()->json(['mes' => $mes, 'general' => ReglasComision::foto($mes), 'excepciones' => []]);
        }

        $excepciones = ReglasComision::excepcionesDelMes($mes);
        $ids      = collect($excepciones)->groupBy('alcance');
        $tiendas  = Tienda::whereIn('id', $ids->get('tienda', collect())->pluck('alcance_id'))->pluck('nombre', 'id');
        $personas = Usuario::whereIn('id', $ids->get('usuario', collect())->pluck('alcance_id'))->pluck('nombre', 'id');

        return response()->json([
            'mes'         => $mes,
            'general'     => ReglasComision::foto($mes),
            // Con nombres, para decirlas en palabras ("Norte: pool en partes iguales").
            'excepciones' => array_map(fn ($e) => $e + [
                'alcance_nombre' => $e['alcance'] === 'tienda'
                    ? ($tiendas[$e['alcance_id']] ?? "Tienda #{$e['alcance_id']}")
                    : ($personas[$e['alcance_id']] ?? "#{$e['alcance_id']}"),
                'regla'       => ReglasComision::CATALOGO[$e['clave']]['nombre'],
                'valor_texto' => ReglasComision::valorTexto($e['clave'], $e['valor']),
            ], $excepciones),
        ]);
    }

    // POST /api/comisiones/reglas
    public function guardar(Request $request)
    {
        $usuario = $request->user();
        if ($error = self::sinPermiso($usuario)) return $error;

        $data = self::leerRegla($request);
        if (! is_array($data)) return $data;
        [$alcanceId, $valor] = [$data['alcance_id'], $data['valor']];

        if ($frenado = self::frenarHaciaAtras($data['desde_mes'], $data['alcance'], $alcanceId, ! empty($data['confirmar']))) {
            return $frenado;
        }

        $llave = ['clave' => $data['clave'], 'alcance' => $data['alcance'],
                  'alcance_id' => $alcanceId, 'desde_mes' => $data['desde_mes']];
        $antes = DB::table('comision_reglas')->where($llave)->first();

        DB::table('comision_reglas')->updateOrInsert($llave, [
            'valor'      => $valor === null ? null : json_encode($valor),
            'nota'       => isset($data['nota']) ? trim($data['nota']) : null,
            'creado_por' => $usuario->id,
            'updated_at' => now(),
        ] + ($antes ? [] : ['created_at' => now()]));

        $id = (int) DB::table('comision_reglas')->where($llave)->value('id');

        self::despuesDeCambiar($data['clave'], $data['alcance'], $alcanceId, $usuario, $data['desde_mes'], [
            'regla_id' => $id, 'clave' => $data['clave'], 'alcance' => $data['alcance'], 'alcance_id' => $alcanceId,
            'antes' => $antes && $antes->valor !== null ? json_decode((string) $antes->valor, true) : null,
            'despues' => $valor, 'nota' => $data['nota'] ?? null,
        ], $antes ? 'regla_cambiada' : 'regla_nueva');

        return response()->json(['ok' => true, 'id' => $id], $antes ? 200 : 201);
    }

    // DELETE /api/comisiones/reglas/{id}
    public function quitar(Request $request, int $id)
    {
        $usuario = $request->user();
        if ($error = self::sinPermiso($usuario)) return $error;

        $fila = DB::table('comision_reglas')->where('id', $id)->first();
        if (! $fila) {
            return response()->json(['message' => 'Esa regla ya no existe.'], 404);
        }

        // Quitarla cambia los meses desde su "desde": las mismas condiciones
        // que ponerla.
        $alcanceId = $fila->alcance_id !== null ? (int) $fila->alcance_id : null;
        if ($frenado = self::frenarHaciaAtras($fila->desde_mes, $fila->alcance, $alcanceId, $request->boolean('confirmar'))) {
            return $frenado;
        }

        DB::table('comision_reglas')->where('id', $id)->delete();

        self::despuesDeCambiar($fila->clave, $fila->alcance, $alcanceId, $usuario, $fila->desde_mes, [
            'regla_id' => (int) $fila->id, 'clave' => $fila->clave, 'alcance' => $fila->alcance, 'alcance_id' => $alcanceId,
            'antes' => $fila->valor !== null ? json_decode((string) $fila->valor, true) : null, 'nota' => $fila->nota,
        ], 'regla_quitada');

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/comisiones/reglas/simular
     *
     * "Con esta regla, este mes quedaría así": la comisión de cada persona
     * antes y después, sin guardar nada. Corre el mismo resumen de la
     * pantalla dos veces, cada una dentro de una transacción que se deshace
     * (el resumen abre renglones de pool y anota anticipos: nada de eso puede
     * quedar de una simulación).
     *
     * Se simula un mes ya hecho como si la regla hubiera regido desde ese mes.
     * Las reglas que cambian algo guardado en cada renglón
     * (ReglasComision::SE_VEN_AL_RECALCULAR) no se ven aquí: se avisa.
     */
    public function simular(Request $request)
    {
        $usuario = $request->user();
        if ($error = self::sinPermiso($usuario)) return $error;

        $data = self::leerRegla($request);
        if (! is_array($data)) return $data;

        $mes = (string) $request->input('mes', $data['desde_mes']);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            return response()->json(['message' => 'Mes inválido (YYYY-MM).'], 422);
        }

        $antes   = self::tarjetasDelMes($mes);
        $despues = self::tarjetasDelMes($mes, function () use ($data, $mes) {
            DB::table('comision_reglas')->updateOrInsert(
                ['clave' => $data['clave'], 'alcance' => $data['alcance'], 'alcance_id' => $data['alcance_id'], 'desde_mes' => $mes],
                ['valor' => $data['valor'] === null ? null : json_encode($data['valor']), 'updated_at' => now()]
            );
        });

        $personas = collect(array_keys($antes + $despues))->map(function ($k) use ($antes, $despues) {
            $a = $antes[$k] ?? null;
            $d = $despues[$k] ?? null;
            $base = $a ?? $d;

            return [
                'vendedor_id' => $base['vendedor_id'],
                'vendedor'    => $base['vendedor'],
                'tienda'      => $base['tienda'],
                'antes'       => $a['comision'] ?? 0,
                'despues'     => $d['comision'] ?? 0,
                'diferencia'  => ($d['comision'] ?? 0) - ($a['comision'] ?? 0),
            ];
        })->sortBy([['diferencia', 'desc'], ['vendedor', 'asc']])->values();

        return response()->json([
            'mes'        => $mes,
            'personas'   => $personas,
            'antes'      => $personas->sum('antes'),
            'despues'    => $personas->sum('despues'),
            'diferencia' => $personas->sum('diferencia'),
            'cambian'    => $personas->where('diferencia', '!=', 0)->count(),
            // Lo que este simulador no alcanza a mostrar.
            'aviso'      => in_array($data['clave'], ReglasComision::SE_VEN_AL_RECALCULAR, true)
                ? 'Esta regla cambia algo que se guarda en cada venta (la base o la fecha de cobro): '
                  . 'el simulador no lo alcanza a mostrar y se verá después del recálculo.'
                : null,
        ]);
    }

    /**
     * Las tarjetas de Comisiones de un mes (persona y tienda → comisión),
     * calculadas dentro de una transacción que se deshace siempre.
     *
     * @return array<string, array{vendedor_id:int, vendedor:?string, tienda:?string, comision:float}>
     */
    private static function tarjetasDelMes(string $mes, ?callable $antes = null): array
    {
        DB::beginTransaction();
        try {
            if ($antes) $antes();
            \App\Support\CachesDePeticion::olvidarTodo();
            $filas = app(ComisionController::class)->resumenDelMes($mes);
        } finally {
            DB::rollBack();
            // Lo recordado en esta pasada salió de datos que ya no existen.
            \App\Support\CachesDePeticion::olvidarTodo();
        }

        $out = [];
        foreach ($filas as $f) {
            $out[$f['vendedor_id'] . '_' . $f['tienda_id']] = [
                'vendedor_id' => (int) $f['vendedor_id'],
                'vendedor'    => $f['vendedor_nombre'],
                'tienda'      => $f['tienda_nombre'],
                'comision'    => round((float) $f['comision_total']),
            ];
        }

        return $out;
    }

    /**
     * Lee y valida una regla de la petición (guardar y simular usan lo mismo).
     *
     * @return array{clave:string, alcance:string, alcance_id:?int, valor:mixed, desde_mes:string, nota:?string, confirmar:bool}|\Illuminate\Http\JsonResponse
     */
    private static function leerRegla(Request $request)
    {
        $data = $request->validate([
            'clave'      => ['required', 'string', 'in:' . implode(',', array_keys(ReglasComision::CATALOGO))],
            'alcance'    => 'required|in:general,tienda,usuario',
            'alcance_id' => 'nullable|integer',
            'valor'      => 'present',
            'desde_mes'  => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'nota'       => 'nullable|string|max:200',
            'confirmar'  => 'sometimes|boolean',
        ]);

        $regla = ReglasComision::CATALOGO[$data['clave']];

        if (! in_array($data['alcance'], $regla['alcances'], true)) {
            return response()->json(['message' => "«{$regla['nombre']}» no se puede cambiar "
                . ($data['alcance'] === 'tienda' ? 'por tienda.' : 'por persona.')], 422);
        }

        $alcanceId = null;
        if ($data['alcance'] !== 'general') {
            $alcanceId = isset($data['alcance_id']) ? (int) $data['alcance_id'] : 0;
            $existe = $data['alcance'] === 'tienda'
                ? Tienda::whereKey($alcanceId)->exists()
                : Usuario::whereKey($alcanceId)->exists();
            if (! $existe) {
                return response()->json(['message' => $data['alcance'] === 'tienda'
                    ? 'Escoge la tienda.' : 'Escoge la persona.'], 422);
            }
        }

        // Nulo solo en una excepción: "desde aquí, sin excepción" (rige la de
        // arriba). La regla de todos siempre tiene un valor.
        $valor = null;
        if ($data['valor'] !== null) {
            $valor = ReglasComision::normalizar($data['clave'], $data['valor']);
            $motivo = $valor === null ? null : self::fueraDeRango($data['clave'], $regla, $valor);
            if ($valor === null || $motivo) {
                return response()->json(['message' => $motivo ?: "Ese valor no sirve para «{$regla['nombre']}»."], 422);
            }
        } elseif ($data['alcance'] === 'general') {
            return response()->json(['message' => 'La regla para todos necesita un valor.'], 422);
        }

        return [
            'clave' => $data['clave'], 'alcance' => $data['alcance'], 'alcance_id' => $alcanceId,
            'valor' => $valor, 'desde_mes' => $data['desde_mes'], 'nota' => $data['nota'] ?? null,
            'confirmar' => ! empty($data['confirmar']),
        ];
    }

    /** Quien puede cambiar reglas: acceso a Comisiones y el permiso aparte. */
    public static function puedeEditar(Usuario $u): bool
    {
        return (bool) $u->acceso_comisiones && (bool) ($u->acceso_reglas_comision ?? false);
    }

    private static function sinPermiso(Usuario $u)
    {
        if (! self::hayTabla()) {
            return response()->json(['message' => 'Falta correr la migración de reglas de comisión.'], 503);
        }
        if (! self::puedeEditar($u)) {
            return response()->json(['message' => 'Cambiar las reglas de comisión necesita el permiso "Reglas de comisión".'], 403);
        }

        return null;
    }

    private static function hayTabla(): bool
    {
        return Esquema::tabla('comision_reglas');
    }

    /** El porqué, si el valor se sale de lo que tiene sentido para esa regla. */
    private static function fueraDeRango(string $clave, array $regla, mixed $valor): ?string
    {
        if ($regla['tipo'] === 'fraccion' && ($valor < 0 || $valor > 1)) {
            return "«{$regla['nombre']}» va de 0 % a 100 %.";
        }
        if (isset(self::RANGOS[$clave])) {
            [$min, $max] = self::RANGOS[$clave];
            if ($valor < $min || $valor > $max) return "«{$regla['nombre']}» va de {$min} a {$max}.";
        }
        if ($regla['tipo'] === 'lista') {
            if (! $valor) return 'Escoge al menos un medio de pago.';
            if ($otros = array_diff($valor, self::METODOS_PAGO)) {
                return 'Medio de pago que no existe: ' . implode(', ', $otros) . '.';
            }
        }

        return null;
    }

    /**
     * Una regla desde un mes que ya pasó (dueño, 2026-10-27): solo si ninguno
     * de esos meses tiene comisiones pagadas de quienes toca la regla —lo
     * pagado no se mueve, y el mes quedaría con dos reglas—, y con
     * confirmación de quien la pone.
     */
    private static function frenarHaciaAtras(string $desdeMes, string $alcance, ?int $alcanceId, bool $confirmado)
    {
        $mesActual = ComisionController::hoy()->format('Y-m');
        if ($desdeMes >= $mesActual) return null;

        $pagadas = Comision::where('estado', 'pagada')->where('mes_venta', '>=', $desdeMes)
            ->when($alcance === 'tienda', fn ($q) => $q->where('tienda_id', $alcanceId))
            ->when($alcance === 'usuario', fn ($q) => $q->where('vendedor_id', $alcanceId))
            ->distinct()->orderBy('mes_venta')->pluck('mes_venta');

        if ($pagadas->isNotEmpty()) {
            $meses = $pagadas->map(fn ($m) => Carbon::parse($m . '-01')->locale('es')->isoFormat('MMMM [de] YYYY'))->implode(', ');
            return response()->json([
                'message' => "No se puede desde {$desdeMes}: ya hay comisiones pagadas de {$meses}. "
                    . 'Ponla desde el mes en curso, o desde el mes siguiente al último pagado.',
                'meses_pagados' => $pagadas->values(),
            ], 422);
        }

        if (! $confirmado) {
            return response()->json([
                'message' => "Esto cambia lo que está sin pagar desde {$desdeMes}. Nada de esos meses se ha pagado todavía.",
                'requiere_confirmacion' => true,
            ], 409);
        }

        return null;
    }

    /** Lo que sigue a cualquier cambio: bitácora, fechas de pago y recálculo. */
    private static function despuesDeCambiar(string $clave, string $alcance, ?int $alcanceId, Usuario $usuario, string $desdeMes, array $detalle, string $accion): void
    {
        ReglasComision::olvidarCache();

        ComisionController::anotar($accion, $alcance === 'tienda' ? $alcanceId : null, $desdeMes, $detalle);

        // La fecha en que se puede cobrar está guardada en cada renglón.
        if (in_array($clave, ['dia_pago', 'meses_espera'], true)) {
            ComisionController::rehacerFechasDisponibles(
                $alcance === 'tienda' ? $alcanceId : null,
                $alcance === 'usuario' ? $alcanceId : null,
            );
        }

        // Lo demás (montos, estados, la base de cada orden) lo pone al día el
        // recálculo de siempre, por fuera de la petición.
        ComisionController::pedirRecalculo($usuario->nombre);
    }
}
