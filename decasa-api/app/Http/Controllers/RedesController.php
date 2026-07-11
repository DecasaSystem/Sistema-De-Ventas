<?php

namespace App\Http\Controllers;

use App\Events\NuevaConversacionWa;
use App\Models\Cita;
use App\Models\ConversacionWa;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RedesController extends Controller
{
    // GET /api/redes/conversaciones
    public function index(Request $request)
    {
        $usuario = $request->user();
        $estado  = $request->query('estado'); // pendiente | tomada | terminada | null (todas)

        $q = ConversacionWa::with('tomadaPor:id,nombre')
            ->orderByRaw("FIELD(estado, 'pendiente', 'tomada', 'terminada')")
            ->orderBy('created_at', 'desc');

        // Solo vendedores ven limitado a su tienda; supervisores y admin ven todo
        if ($usuario->rol === 'vendedor' && $usuario->tienda_default_id) {
            $q->where(function ($query) use ($usuario) {
                $query->where('tienda_id', $usuario->tienda_default_id)
                      ->orWhereNull('tienda_id');
            });
        }

        if ($estado) {
            $q->where('estado', $estado);
        }

        return response()->json($q->limit(100)->get());
    }

    // POST /api/redes/webhook  — recibe notificaciones del agente WA (sin auth, con token secreto)
    public function webhook(Request $request)
    {
        $secret = config('app.agent_token');
        if ($secret && $request->header('X-Agent-Token') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'tipo'           => 'required|string',
            'telefono'       => 'required|string',
            'nombre_cliente' => 'nullable|string',
            'resumen'        => 'required|string',
            'historial'      => 'nullable|array',
            'carrito'        => 'nullable|array',
            'datos_cita'     => 'nullable|array',
            'tienda_id'      => 'nullable|integer|exists:tiendas,id',
            'whatsapp_url'   => 'nullable|string',
            'contacto_url'   => 'nullable|string',
            'fuente'         => 'nullable|string|in:whatsapp,instagram',
        ]);

        $tipos_validos  = ['pedido', 'cita', 'asesor', 'personalizacion', 'otro'];
        $data['tipo']   = in_array($data['tipo'], $tipos_validos) ? $data['tipo'] : 'otro';
        $data['fuente'] = $data['fuente'] ?? 'whatsapp';

        // Idempotencia: si llega el mismo webhook dos veces (retry de WhatsApp) no duplicar
        $hash = hash('sha256', ($data['telefono'] ?? '') . '|' . ($data['resumen'] ?? '') . '|' . date('Y-m-d H:i'));
        $data['hash_idempotencia'] = $hash;

        $existente = ConversacionWa::where('hash_idempotencia', $hash)->first();
        if ($existente) {
            return response()->json($existente, 200);
        }

        $conv = ConversacionWa::create($data);

        try {
            broadcast(new NuevaConversacionWa($conv));
        } catch (\Throwable $e) {
            \Log::warning('[broadcast] Fallo webhook NuevaConversacionWa: ' . $e->getMessage());
        }

        $esInstagram = ($conv->fuente === 'instagram');
        $canal       = $esInstagram ? 'Instagram' : 'WhatsApp';

        $titulos = [
            'pedido'          => "Nuevo pedido ({$canal})",
            'cita'            => "Cita agendada ({$canal})",
            'asesor'          => "Cliente solicita asesor ({$canal})",
            'personalizacion' => "Solicitud de personalización ({$canal})",
            'otro'            => "Mensaje de {$canal}",
        ];
        $titulo  = $titulos[$conv->tipo] ?? "Mensaje de {$canal}";
        $mensaje = ($conv->nombre_cliente ? $conv->nombre_cliente . ': ' : '') . $conv->resumen;

        // Solo usuarios con acceso al módulo de redes
        $queryUsuarios = Usuario::whereIn('rol', ['vendedor', 'supervisor'])
            ->where('activo', true)
            ->where('acceso_redes', true);
        if ($conv->tipo === 'cita' && $conv->tienda_id) {
            $queryUsuarios->where(function ($q) use ($conv) {
                $q->where('rol', 'supervisor')
                  ->orWhere('tienda_default_id', $conv->tienda_id);
            });
        }
        $usuarios = $queryUsuarios->get();

        foreach ($usuarios as $u) {
            NotificacionService::crear('redes', $titulo, $mensaje, ['conversacion_id' => $conv->id], $u->id);
        }

        return response()->json($conv, 201);
    }

    // POST /api/redes/conversaciones/{id}/tomar — atómico: primero que llega toma
    public function tomar(Request $request, $id)
    {
        $usuario = $request->user();

        // Transacción mínima: solo el UPDATE atómico
        $convId = DB::transaction(function () use ($id, $usuario) {
            $c = ConversacionWa::lockForUpdate()->findOrFail($id);

            if ($c->estado !== 'pendiente') {
                return null;
            }

            $c->update([
                'estado'     => 'tomada',
                'tomada_por' => $usuario->id,
                'tomada_at'  => now(),
            ]);

            $this->silenciarBot($c->telefono, true);

            return $c->id;
        });

        if (!$convId) {
            return response()->json(['error' => 'Esta conversación ya fue tomada por otro vendedor.'], 409);
        }

        // Cargar el modelo con la relación fuera de la transacción
        $conv = ConversacionWa::with('tomadaPor:id,nombre')->findOrFail($convId);

        // Si es una cita, crear registro en módulo Citas
        $citaCreada = false;
        $citaId     = null;

        if ($conv->tipo === 'cita') {
            try {
                $dc   = $conv->datos_cita ?? [];
                $cita = Cita::firstOrCreate(
                    ['conversacion_wa_id' => $conv->id],
                    [
                        'asesor_id'      => $usuario->id,
                        'tienda_id'      => $conv->tienda_id ?? null,
                        'nombre_cliente' => $conv->nombre_cliente,
                        'telefono'       => $conv->telefono,
                        'contacto_url'   => $conv->contacto_url,
                        'fuente'         => $conv->fuente ?? 'whatsapp',
                        'dia'            => $dc['dia']    ?? 'Por definir',
                        'hora'           => $dc['hora']   ?? 'Por definir',
                        'motivo'         => $dc['motivo'] ?? null,
                        'estado'         => 'confirmada',
                        'fecha_cita'     => !empty($dc['dia']) ? $this->parsearFechaCita($dc['dia']) : null,
                    ]
                );
                $citaCreada = $cita->wasRecentlyCreated;
                $citaId     = $cita->id;
                \Log::info('tomar: Cita ' . ($citaCreada ? 'creada' : 'ya existía'), ['conv_id' => $conv->id, 'cita_id' => $citaId]);
            } catch (\Throwable $e) {
                \Log::error('tomar: error creando Cita', ['conv_id' => $conv->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            broadcast(new NuevaConversacionWa($conv));
        } catch (\Throwable $e) {
            \Log::warning('[broadcast] Fallo al emitir NuevaConversacionWa: ' . $e->getMessage());
        }

        return response()->json(array_merge($conv->toArray(), [
            'cita_creada' => $citaCreada,
            'cita_id'     => $citaId,
        ]));
    }

    // Los bots de WhatsApp/Instagram comparten esta misma base de datos y se
    // auto-silencian leyendo estado_usuario.transferido (join por clientes_wa.telefono,
    // que llega igual en ambos lados — con prefijo ig_<psid> cuando es Instagram, ver
    // InstagramAgent/db.js). Antes solo el propio bot podía activar ese flag (cuando el
    // cliente pedía asesor); ahora el clic real de "Tomar"/"Terminar" en este panel es
    // la fuente de verdad, para que la IA no le siga respondiendo al cliente mientras un
    // asesor humano ya se está haciendo cargo (y evitar que hablen los dos al tiempo).
    private function silenciarBot(?string $telefono, bool $transferido): void
    {
        if (!$telefono) return;

        try {
            DB::update(
                'INSERT INTO estado_usuario (usuario_id, transferido)
                 SELECT id, ? FROM clientes_wa WHERE telefono = ?
                 ON DUPLICATE KEY UPDATE transferido = VALUES(transferido)',
                [$transferido, $telefono]
            );
        } catch (\Throwable $e) {
            \Log::warning('[redes] no se pudo sincronizar transferido con el bot: ' . $e->getMessage());
        }
    }

    private function parsearFechaCita(string $dia): ?string
    {
        static $meses = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
            'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
            'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        ];

        // Matches: "martes 3 de junio", "3 de junio", "martes 03 de junio de 2026"
        if (!preg_match('/(\d{1,2})\s+de\s+(\w+)(?:\s+de\s+(\d{4}))?/i', $dia, $m)) {
            return null;
        }

        $mesNom = strtolower($m[2]);
        if (!isset($meses[$mesNom])) return null;

        $tz  = 'America/Bogota';
        $hoy = Carbon::today($tz);
        $anio = isset($m[3]) && $m[3] ? (int) $m[3] : $hoy->year;

        $fecha = Carbon::createFromDate($anio, $meses[$mesNom], (int) $m[1], $tz);

        // If the calculated date is in the past (and no explicit year was given), assume next year
        if (!isset($m[3]) && $fecha->lt($hoy)) {
            $fecha->addYear();
        }

        return $fecha->toDateString();
    }

    // DELETE /api/redes/conversaciones/terminadas — solo supervisor
    public function limpiarTerminadas()
    {
        $eliminadas = ConversacionWa::where('estado', 'terminada')->delete();
        return response()->json(['eliminadas' => $eliminadas]);
    }

    // POST /api/redes/conversaciones/{id}/terminar
    public function terminar(Request $request, $id)
    {
        $usuario = $request->user();
        $conv    = ConversacionWa::with('tomadaPor:id,nombre')->findOrFail($id);

        // Solo quien la tomó o un supervisor puede terminarla
        if ($conv->tomada_por !== $usuario->id && $usuario->rol !== 'supervisor') {
            return response()->json(['error' => 'Solo quien tomó la conversación puede terminarla.'], 403);
        }

        if ($conv->estado === 'terminada') {
            return response()->json(['error' => 'Ya está terminada.'], 409);
        }

        $conv->update([
            'estado'       => 'terminada',
            'terminada_at' => now(),
        ]);

        $this->silenciarBot($conv->telefono, false);

        // Si la conversación tiene una cita vinculada, también completarla
        Cita::where('conversacion_wa_id', $conv->id)
            ->whereNotIn('estado', ['completada', 'cancelada'])
            ->update(['estado' => 'completada', 'updated_at' => now()]);

        try {
            broadcast(new NuevaConversacionWa($conv->fresh('tomadaPor:id,nombre')));
        } catch (\Throwable $e) {}

        return response()->json($conv->fresh('tomadaPor:id,nombre'));
    }

    // GET /api/redes/metricas — panel de métricas de los agentes de IA (WhatsApp + Instagram).
    // Base cross-canal: conversaciones_wa (propiedad de Laravel, tiene fuente/estado/tomada_por).
    // Embudo conversacional del bot de Instagram: ig_eventos (propiedad del agente IG).
    public function metricas(Request $request)
    {
        [$desde, $hasta] = $this->rangoMetricas($request);
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $base  = ConversacionWa::whereBetween('created_at', $rango);
        $total = (clone $base)->count();

        $porFuente = (clone $base)->selectRaw('fuente, COUNT(*) as n')->groupBy('fuente')->pluck('n', 'fuente');
        $porTipo   = (clone $base)->selectRaw('tipo, COUNT(*) as n')->groupBy('tipo')->pluck('n', 'tipo');
        $porEstado = (clone $base)->selectRaw('estado, COUNT(*) as n')->groupBy('estado')->pluck('n', 'estado');

        // Tiempo promedio de respuesta del equipo: de creada a tomada, en minutos.
        $tiempoRespuesta = (clone $base)->whereNotNull('tomada_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, tomada_at)) as m')->value('m');

        $porVendedor = DB::table('conversaciones_wa as c')
            ->leftJoin('usuarios as u', 'u.id', '=', 'c.tomada_por')
            ->whereBetween('c.created_at', $rango)
            ->whereNotNull('c.tomada_por')
            ->selectRaw("COALESCE(u.nombre, 'Sin asignar') as vendedor, COUNT(*) as n")
            ->groupBy('vendedor')->orderByDesc('n')->limit(15)->get();

        // Serie diaria para el gráfico de tendencia.
        $serie = (clone $base)->selectRaw('DATE(created_at) as dia, COUNT(*) as n')
            ->groupBy('dia')->orderBy('dia')->get();

        // Embudo del bot de Instagram. La tabla ig_eventos la crea el agente IG; si aún no
        // existe (o está vacía) se omite esta sección sin romper el resto del panel.
        $instagram = null;
        $igTop = [];
        try {
            $ev = DB::table('ig_eventos')->whereBetween('created_at', $rango)
                ->selectRaw('tipo, COUNT(*) as n')->groupBy('tipo')->pluck('n', 'tipo');
            $conv = (int) ($ev['conversacion'] ?? 0);
            $ped  = (int) ($ev['pedido'] ?? 0);
            $instagram = [
                'conversaciones'   => $conv,
                'busquedas'        => (int) ($ev['busqueda'] ?? 0),
                'productos_vistos' => (int) ($ev['producto_visto'] ?? 0),
                'transferencias'   => (int) ($ev['transferencia'] ?? 0),
                'citas'            => (int) ($ev['cita'] ?? 0),
                'pedidos'          => $ped,
                'sin_resolver'     => (int) ($ev['sin_resolver'] ?? 0),
                'tasa_conversion'  => $conv ? round($ped / $conv * 100, 1) : 0,
            ];
            $igTop = DB::table('ig_eventos')->whereBetween('created_at', $rango)
                ->where('tipo', 'producto_visto')->whereNotNull('detalle')
                ->selectRaw('detalle as nombre, COUNT(*) as veces')
                ->groupBy('detalle')->orderByDesc('veces')->limit(8)->get();
        } catch (\Throwable $e) {
            \Log::info('[metricas] ig_eventos no disponible: ' . $e->getMessage());
        }

        return response()->json([
            'desde'                   => $desde,
            'hasta'                   => $hasta,
            'total'                   => $total,
            'por_fuente'              => $porFuente,
            'por_tipo'                => $porTipo,
            'por_estado'              => $porEstado,
            'tiempo_respuesta_min'    => $tiempoRespuesta !== null ? round($tiempoRespuesta, 1) : null,
            'por_vendedor'            => $porVendedor,
            'serie'                   => $serie,
            'instagram'               => $instagram,
            'instagram_top_productos' => $igTop,
        ]);
    }

    private function rangoMetricas(Request $r): array
    {
        $hoy = Carbon::now('America/Bogota');
        switch ($r->query('periodo')) {
            case 'hoy':
                return [$hoy->toDateString(), $hoy->toDateString()];
            case 'semana':
                return [$hoy->copy()->startOfWeek()->toDateString(), $hoy->toDateString()];
            case 'mes_anterior':
                return [$hoy->copy()->subMonth()->startOfMonth()->toDateString(), $hoy->copy()->subMonth()->endOfMonth()->toDateString()];
            case 'anio':
                return [$hoy->copy()->startOfYear()->toDateString(), $hoy->toDateString()];
            default: // 'mes' o rango personalizado
                return [
                    $r->query('desde', $hoy->copy()->startOfMonth()->toDateString()),
                    $r->query('hasta', $hoy->toDateString()),
                ];
        }
    }
}
