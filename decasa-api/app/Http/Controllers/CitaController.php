<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\ConversacionWa;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CitaController extends Controller
{
    // GET /api/citas  — vendedor ve las suyas; supervisor ve las de su tienda
    public function index(Request $request)
    {
        $usuario = $request->user();
        $estado  = $request->query('estado'); // pendiente|confirmada|completada|cancelada|null

        $q = Cita::with(['asesor:id,nombre', 'tienda:id,nombre'])
                 ->orderBy('created_at', 'desc');

        if ($usuario->rol === 'supervisor') {
            if ($usuario->tienda_default_id) {
                $q->where('tienda_id', $usuario->tienda_default_id);
            }
        } else {
            $q->where('asesor_id', $usuario->id);
        }

        if ($estado) {
            $q->where('estado', $estado);
        }

        return response()->json($q->limit(100)->get());
    }

    // POST /api/citas  — crear cita manual (presencial o por mensaje directo)
    public function store(Request $request)
    {
        $usuario = $request->user();

        $data = $request->validate([
            'nombre_cliente' => 'required|string|max:200',
            'telefono'       => 'nullable|string|max:30',
            'fecha_cita'     => 'required|date|after_or_equal:today',
            'hora'           => 'required|string|max:10',
            'motivo'         => 'nullable|string|max:500',
        ]);

        $meses      = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $diasSemana = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
        $fecha      = Carbon::parse($data['fecha_cita']);
        $diaTexto   = "{$diasSemana[$fecha->dayOfWeek]} {$fecha->day} de {$meses[$fecha->month - 1]}";

        $cita = Cita::create([
            'asesor_id'      => $usuario->id,
            'tienda_id'      => $usuario->tienda_default_id ?? null,
            'nombre_cliente' => $data['nombre_cliente'],
            'telefono'       => $data['telefono'] ?? null,
            'fuente'         => 'manual',
            'dia'            => $diaTexto,
            'hora'           => $data['hora'],
            'motivo'         => $data['motivo'] ?? null,
            'estado'         => 'pendiente',
            'fecha_cita'     => $data['fecha_cita'],
        ]);

        return response()->json($cita->load(['asesor:id,nombre', 'tienda:id,nombre']), 201);
    }

    // PATCH /api/citas/{id}  — actualizar estado y/o notas
    public function update(Request $request, $id)
    {
        $usuario = $request->user();
        $cita    = Cita::findOrFail($id);

        // Solo el asesor dueño o un supervisor puede actualizar
        if ($cita->asesor_id !== $usuario->id && $usuario->rol !== 'supervisor') {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'estado' => 'sometimes|in:pendiente,confirmada,completada,cancelada',
            'notas'  => 'sometimes|nullable|string|max:1000',
        ]);

        $cita->update($data);

        // Si se completa desde Citas y tiene conversación de Redes vinculada, también terminarla
        if (($data['estado'] ?? null) === 'completada' && $cita->conversacion_wa_id) {
            ConversacionWa::where('id', $cita->conversacion_wa_id)
                ->where('estado', '!=', 'terminada')
                ->update(['estado' => 'terminada', 'terminada_at' => now()]);
        }

        return response()->json($cita->load(['asesor:id,nombre', 'tienda:id,nombre']));
    }
}
