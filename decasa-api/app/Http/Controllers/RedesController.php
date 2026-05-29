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

        // Vendedores y supervisores solo ven las de su tienda (+ las sin tienda asignada)
        if (in_array($usuario->rol, ['vendedor', 'supervisor']) && $usuario->tienda_default_id) {
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
            // Reverb offline — conversación guardada en BD, broadcast ignorado
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

        // Para citas con tienda definida: notificar solo a vendedores/supervisores de esa tienda
        $queryUsuarios = Usuario::whereIn('rol', ['vendedor', 'supervisor'])->where('activo', true);
        if ($conv->tipo === 'cita' && $conv->tienda_id) {
            $queryUsuarios->where('tienda_default_id', $conv->tienda_id);
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

        $conv = DB::transaction(function () use ($id, $usuario) {
            $c = ConversacionWa::lockForUpdate()->findOrFail($id);

            if ($c->estado !== 'pendiente') {
                return null;
            }

            $c->update([
                'estado'    => 'tomada',
                'tomada_por' => $usuario->id,
                'tomada_at' => now(),
            ]);

            return $c->fresh('tomadaPor:id,nombre');
        });

        if (!$conv) {
            return response()->json(['error' => 'Esta conversación ya fue tomada por otro vendedor.'], 409);
        }

        // Si es una cita, crear registro en módulo Citas (con o sin datos_cita)
        $citaCreada = false;
        $citaId     = null;

        if ($conv->tipo === 'cita') {
            try {
                $dc = $conv->datos_cita ?? [];
                [$cita, $citaCreada] = Cita::firstOrCreate(
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
                        'estado'         => 'pendiente',
                        'fecha_cita'     => !empty($dc['dia']) ? $this->parsearFechaCita($dc['dia']) : null,
                    ]
                );
                $citaId = $cita->id;
                \Log::info('tomar: Cita ' . ($citaCreada ? 'creada' : 'ya existía'), ['conv_id' => $conv->id, 'cita_id' => $citaId]);
            } catch (\Throwable $e) {
                \Log::error('tomar: error creando Cita', ['conv_id' => $conv->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            broadcast(new NuevaConversacionWa($conv));
        } catch (\Throwable $e) {}

        return response()->json(array_merge($conv->toArray(), [
            'cita_creada' => $citaCreada,
            'cita_id'     => $citaId,
        ]));
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

        try {
            broadcast(new NuevaConversacionWa($conv->fresh('tomadaPor:id,nombre')));
        } catch (\Throwable $e) {}

        return response()->json($conv->fresh('tomadaPor:id,nombre'));
    }
}
