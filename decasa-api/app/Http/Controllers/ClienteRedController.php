<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ClienteRed;
use App\Services\ClientesRedes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Clientes → Redes: las personas que escribieron por WhatsApp o Instagram, con
 * el nombre y el celular que le dieron a Elena antes de pasar con un asesor
 * (dueño, 2026-10-08). Las fichas las crea el webhook de Redes
 * (ClientesRedes::registrarDesdeAviso); aquí el equipo las consulta, les cambia
 * el estado, les pone notas y las convierte en cliente cuando compran.
 *
 * Mismo criterio de visibilidad que la bandeja de Redes: un vendedor con tienda
 * ve las de su tienda y las que no tienen tienda (casi todas: solo las citas
 * traen sede); supervisores ven todo.
 */
class ClienteRedController extends Controller
{
    /** GET /api/clientes-redes?search=&estado=&canal=&page= */
    public function index(Request $request)
    {
        $base = $this->visibles($request);

        if ($search = trim((string) $request->query('search'))) {
            $term = '%' . mb_strtolower($search) . '%';
            $digitos = preg_replace('/\D/', '', $search);
            $base->where(function ($q) use ($term, $digitos) {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(usuario_red) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(ultimo_interes) LIKE ?', [$term]);
                if (strlen($digitos) >= 4) {
                    $q->orWhere('telefono', 'like', '%' . $digitos . '%')
                      ->orWhere('identificador', 'like', '%' . $digitos . '%');
                }
            });
        }
        if (in_array($canal = $request->query('canal'), ['whatsapp', 'instagram'], true)) {
            $base->where('canal', $canal);
        }

        // Los chips de estado muestran cuántos hay con los mismos filtros (sin el de estado).
        $conteos = (clone $base)->selectRaw('estado, COUNT(*) as n')->groupBy('estado')->pluck('n', 'estado');

        if (in_array($estado = $request->query('estado'), ClienteRed::ESTADOS, true)) {
            $base->where('estado', $estado);
        }

        $pagina = $base->with('cliente:id,nombre')
            ->orderByDesc('ultimo_contacto_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json(array_merge($pagina->toArray(), [
            'conteos' => collect(ClienteRed::ESTADOS)->mapWithKeys(fn ($e) => [$e => (int) ($conteos[$e] ?? 0)]),
        ]));
    }

    /** GET /api/clientes-redes/{id} — ficha + sus tarjetas de Redes. */
    public function show(Request $request, int $id)
    {
        $ficha = $this->visibles($request)->with(['cliente:id,nombre,telefono', 'tienda:id,nombre'])->findOrFail($id);

        $conversaciones = $ficha->conversaciones()
            ->with('tomadaPor:id,nombre')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'tipo', 'resumen', 'estado', 'tomada_por', 'carrito', 'created_at']);

        return response()->json(array_merge($ficha->toArray(), ['conversaciones' => $conversaciones]));
    }

    /** PUT /api/clientes-redes/{id} — estado, notas y datos de contacto. */
    public function update(Request $request, int $id)
    {
        $ficha = $this->visibles($request)->findOrFail($id);

        $data = $request->validate([
            'estado'   => 'sometimes|in:' . implode(',', ClienteRed::ESTADOS),
            'notas'    => 'sometimes|nullable|string|max:2000',
            'nombre'   => 'sometimes|nullable|string|max:120',
            'telefono' => 'sometimes|nullable|string|max:30',
            'ciudad'   => 'sometimes|nullable|string|max:80',
        ]);

        if (array_key_exists('telefono', $data) && $data['telefono'] !== null && $data['telefono'] !== '') {
            $normalizado = ClientesRedes::telefono($data['telefono']);
            if (! $normalizado) {
                return response()->json(['message' => 'Ese número no parece un celular válido.'], 422);
            }
            $data['telefono'] = $normalizado;
        }

        $ficha->update($data);

        return response()->json($ficha->fresh(['cliente:id,nombre']));
    }

    /**
     * POST /api/clientes-redes/{id}/convertir
     * Lo pasa a la lista de clientes (tipo "interesado") o lo enlaza con el cliente
     * que ya existe con ese celular. No crea duplicados: si ya está enlazado, devuelve
     * el mismo.
     */
    public function convertir(Request $request, int $id)
    {
        $ficha = $this->visibles($request)->findOrFail($id);

        if ($ficha->cliente_id && Cliente::whereKey($ficha->cliente_id)->exists()) {
            return response()->json(['cliente_id' => $ficha->cliente_id, 'creado' => false]);
        }
        if (! $ficha->nombre) {
            return response()->json(['message' => 'Ponle el nombre antes de pasarlo a clientes.'], 422);
        }

        return DB::transaction(function () use ($ficha, $request) {
            $existente = ClientesRedes::clientePorTelefono($ficha->telefono);
            if ($existente) {
                $ficha->update(['cliente_id' => $existente]);
                return response()->json(['cliente_id' => $existente, 'creado' => false]);
            }

            $notas = collect([
                $ficha->espacio ? "Para: {$ficha->espacio}" : null,
                $ficha->presupuesto ? 'Presupuesto: $' . number_format($ficha->presupuesto, 0, ',', '.') : null,
                $ficha->productos_interes ? 'Vio: ' . implode(', ', $ficha->productos_interes) : null,
                $ficha->ultimo_interes ? 'Último: ' . $ficha->ultimo_interes : null,
            ])->filter()->implode("\n");

            $cliente = Cliente::create([
                'nombre'        => $ficha->nombre,
                'telefono'      => $ficha->telefono,
                'canal_pref'    => $ficha->canal === 'whatsapp' ? 'whatsapp' : 'red_social',
                'tipo'          => 'interesado',
                'tienda_id'     => $ficha->tienda_id ?? $request->user()?->tienda_default_id,
                'notas_interes' => mb_substr($notas, 0, 1000) ?: null,
            ]);
            $ficha->update(['cliente_id' => $cliente->id]);

            return response()->json(['cliente_id' => $cliente->id, 'creado' => true], 201);
        });
    }

    private function visibles(Request $request)
    {
        $usuario = $request->user();
        $q = ClienteRed::query();
        if ($usuario?->rol === 'vendedor' && $usuario->tienda_default_id) {
            $q->where(function ($w) use ($usuario) {
                $w->where('tienda_id', $usuario->tienda_default_id)->orWhereNull('tienda_id');
            });
        }
        return $q;
    }
}
