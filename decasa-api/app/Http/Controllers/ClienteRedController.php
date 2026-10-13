<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ClienteRed;
use App\Services\ClientesRedes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                  ->orWhereRaw('LOWER(ultimo_interes) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(interes) LIKE ?', [$term]);
                if (strlen($digitos) >= 4) {
                    $q->orWhere('telefono', 'like', '%' . $digitos . '%')
                      ->orWhere('identificador', 'like', '%' . $digitos . '%');
                }
            });
        }
        $canal = $request->query('canal');
        if (in_array($canal, ClienteRed::CANALES, true)) {
            $base->where('canal', $canal);
        } elseif ($canal === 'otros') {
            // Los que no llegaron por los agentes: llamada, visita, referido…
            $base->whereNotIn('canal', ClienteRed::CANALES_AGENTES);
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

    /**
     * POST /api/clientes-redes — registrar a alguien a mano (dueño, 2026-10-13):
     * una llamada, alguien que vino a la tienda, un referido, o uno de WhatsApp o
     * Instagram que nunca pasó por Elena. El apartado es la base de datos de
     * interesados, venga de donde venga.
     *
     * - Si es de WhatsApp, la ficha queda con el celular como identificador, igual
     *   que las que crea el agente: si esa persona escribe después, Elena actualiza
     *   esta misma ficha en vez de crear otra.
     * - No se repite un celular: si ya hay una ficha con ese número, se avisa y se
     *   devuelve cuál es para abrirla.
     * - Un vendedor la registra en su tienda; un supervisor escoge (o ninguna).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:120',
            'canal'       => 'required|in:' . implode(',', ClienteRed::CANALES),
            'telefono'    => 'nullable|string|max:30',
            'usuario_red' => 'nullable|string|max:80',
            'ciudad'      => 'nullable|string|max:80',
            'interes'     => 'nullable|string|max:500',
            'espacio'     => 'nullable|string|max:120',
            'presupuesto' => 'nullable|integer|min:0|max:9999999999',
            'notas'       => 'nullable|string|max:2000',
            'tienda_id'   => 'nullable|integer|exists:tiendas,id',
        ]);

        $telefono = null;
        if (trim((string) ($data['telefono'] ?? '')) !== '') {
            $telefono = ClientesRedes::telefono($data['telefono']);
            if (! $telefono) {
                return response()->json(['message' => 'Ese número no parece un celular válido.'], 422);
            }
        }
        if ($data['canal'] === 'whatsapp' && ! $telefono) {
            return response()->json(['message' => 'Para WhatsApp pon el celular.'], 422);
        }

        // ¿Ya está? Por celular (en cualquier canal) o, en WhatsApp, por el número del chat.
        if ($telefono) {
            $existente = ClienteRed::where('telefono', $telefono)
                ->orWhere(fn ($q) => $q->where('canal', 'whatsapp')->where('identificador', $telefono))
                ->first(['id', 'nombre', 'tienda_id']);
            if ($existente) {
                $laVe = $this->visibles($request)->whereKey($existente->id)->exists();

                return response()->json([
                    'message'     => $laVe
                        ? "Ese celular ya está registrado ({$existente->nombre})."
                        : 'Ese celular ya está registrado en otra tienda.',
                    'existente_id' => $laVe ? $existente->id : null,
                ], 422);
            }
        }

        $usuario = $request->user();
        $tiendaId = $usuario?->rol === 'supervisor'
            ? ($data['tienda_id'] ?? null)
            : ($usuario?->tienda_default_id ?: null);

        $usuarioRed = trim((string) ($data['usuario_red'] ?? ''));
        if ($usuarioRed !== '' && ! str_starts_with($usuarioRed, '@')) $usuarioRed = '@' . $usuarioRed;

        $ficha = ClienteRed::create([
            'canal'         => $data['canal'],
            // WhatsApp: el mismo identificador que usa el agente (el número del chat).
            // El resto no tiene un chat con quien cruzarse: uno propio que no choca.
            'identificador' => $data['canal'] === 'whatsapp' ? $telefono : 'manual_' . strtolower((string) Str::ulid()),
            'nombre'        => trim($data['nombre']),
            'telefono'      => $telefono,
            'usuario_red'   => $usuarioRed ?: null,
            'ciudad'        => $data['ciudad'] ?? null,
            'interes'       => $data['interes'] ?? null,
            'espacio'       => $data['espacio'] ?? null,
            'presupuesto'   => $data['presupuesto'] ?? null,
            'notas'         => $data['notas'] ?? null,
            'estado'        => 'nuevo',
            'tienda_id'     => $tiendaId,
            'cliente_id'    => $telefono ? ClientesRedes::clientePorTelefono($telefono) : null,
            'primer_contacto_at' => now(),
            'ultimo_contacto_at' => now(),
        ]);

        return response()->json($ficha->fresh(['cliente:id,nombre']), 201);
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
            // Lo que busca: lo resume Elena, pero en los que se registran a mano lo
            // escribe el equipo (y lo corrige si cambió).
            'interes'  => 'sometimes|nullable|string|max:500',
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
                'canal_pref'    => match ($ficha->canal) {
                    'whatsapp'  => 'whatsapp',
                    'instagram' => 'red_social',
                    'visita'    => 'fisica',
                    default     => 'otro',   // llamada, referido, otro
                },
                'tipo'          => 'interesado',
                'tienda_id'     => $ficha->tienda_id ?? $request->user()?->tienda_default_id,
                'notas_interes' => mb_substr($notas, 0, 1000) ?: null,
            ]);
            $ficha->update(['cliente_id' => $cliente->id]);

            return response()->json(['cliente_id' => $cliente->id, 'creado' => true], 201);
        });
    }

    /**
     * POST /api/agentes/clientes-redes — lo llaman los agentes (token, sin usuario).
     * El cliente dio su nombre o celular en medio de la conversación, o Elena aprendió
     * qué busca: se guarda la ficha sin crear tarjeta ni avisar a nadie.
     *
     * Como el webhook, solo se exige la forma: lo de adentro de `contacto` lo limpia
     * ClientesRedes. Responde lo mínimo (regla de los agentes: nunca devolver datos).
     */
    public function desdeAgente(Request $request)
    {
        $data = $request->validate([
            'fuente'       => 'required|string|in:whatsapp,instagram',
            'telefono'     => 'required|string|max:80',   // número del chat o ig_<psid>
            'contacto'     => 'required|array',
            'contacto_url' => 'nullable|string|max:255',
        ]);

        $ficha = ClientesRedes::registrarDesdeAgente($data['fuente'], $data['telefono'], $data['contacto'], $data['contacto_url'] ?? null);

        return response()->json(['ok' => true, 'guardado' => (bool) $ficha]);
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
