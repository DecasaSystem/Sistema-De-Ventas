<?php

namespace App\Http\Controllers;

use App\Exports\ReporteExport;
use App\Models\Cliente;
use App\Models\Orden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ClienteController extends Controller
{
    /**
     * GET /api/clientes?search=juan&page=1&tipo=interesado
     * Búsqueda por nombre, cédula o teléfono con paginación.
     * Filtro opcional por tipo: oficial|interesado
     */
    public function index(Request $request)
    {
        $query = Cliente::query();

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(nombre) LIKE ?',   [$term])
                  ->orWhereRaw('LOWER(cedula) LIKE ?',   [$term])
                  ->orWhereRaw('LOWER(telefono) LIKE ?', [$term]);
            });
        }

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        $clientes = $query->orderBy('nombre')->paginate(20);

        return response()->json($clientes);
    }

    /**
     * POST /api/clientes
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:120',
            'cedula'           => 'nullable|string|max:20|unique:clientes,cedula',
            'telefono'         => 'nullable|string|max:20',
            'email'            => 'nullable|email|max:120',
            'direccion'        => 'nullable|string|max:200',
            'canal_pref'       => 'nullable|in:fisica,whatsapp,red_social,otro',
            'tipo'             => 'nullable|in:oficial,interesado',
            'tienda_id'        => 'nullable|exists:tiendas,id',
            'categorias_interes' => 'nullable|array',
            'categorias_interes.*' => 'string|max:50',
            'notas_interes'    => 'nullable|string|max:1000',
        ]);

        // Auto-asignar tienda del vendedor si no se proporcionó
        if (empty($data['tienda_id']) && $request->user()?->tienda_default_id) {
            $data['tienda_id'] = $request->user()->tienda_default_id;
        }

        $cliente = Cliente::create($data);

        return response()->json($cliente, 201);
    }

    /**
     * PUT /api/clientes/{id}
     * Actualiza campos del cliente (ej. tipo).
     */
    public function update(Request $request, int $id)
    {
        $cliente = Cliente::findOrFail($id);

        $data = $request->validate([
            'nombre'            => 'sometimes|string|max:120',
            'cedula'            => 'sometimes|string|max:20|unique:clientes,cedula,' . $id,
            'telefono'          => 'sometimes|nullable|string|max:20',
            'email'             => 'sometimes|nullable|email|max:120',
            'direccion'         => 'sometimes|nullable|string|max:200',
            'canal_pref'        => 'sometimes|nullable|in:fisica,whatsapp,red_social,otro',
            'tipo'              => 'sometimes|in:oficial,interesado',
            'categorias_interes' => 'sometimes|nullable|array',
            'categorias_interes.*' => 'string|max:50',
            'notas_interes'     => 'sometimes|nullable|string|max:1000',
        ]);

        $cliente->update($data);

        return response()->json($cliente);
    }

    /**
     * GET /api/clientes/{id}
     * Retorna el cliente con sus estadísticas.
     */
    public function show(int $id)
    {
        $cliente = Cliente::findOrFail($id);

        // Estadísticas en una sola query
        $stats = DB::table('ordenes as o')
            ->leftJoin('pagos as p', 'p.orden_id', '=', 'o.id')
            ->where('o.cliente_id', $id)
            ->selectRaw('
                COUNT(DISTINCT o.id)                          AS total_ordenes,
                MAX(o.created_at)                             AS ultima_compra,
                SUM(o.valor_total) - COALESCE(SUM(p.monto),0) AS saldo_pendiente_total
            ')
            ->first();

        // Canal más usado
        $canalFrec = DB::table('ordenes')
            ->where('cliente_id', $id)
            ->whereNotNull('canal')
            ->select('canal', DB::raw('COUNT(*) as total'))
            ->groupBy('canal')
            ->orderByDesc('total')
            ->value('canal');

        $cliente->total_ordenes        = (int) ($stats->total_ordenes ?? 0);
        $cliente->ultima_compra        = $stats->ultima_compra;
        $cliente->saldo_pendiente_total = (float) ($stats->saldo_pendiente_total ?? 0);
        $cliente->canal_frecuente      = $canalFrec;

        return response()->json($cliente);
    }

    /**
     * GET /api/clientes/verificar-duplicado?nombre=&telefono=&email=
     *
     * Advertencia suave: devuelve posibles clientes que ya existen para que el
     * vendedor confirme antes de crear un duplicado. Coincide si el email es
     * igual, o si el nombre Y el teléfono son iguales.
     */
    public function verificarDuplicado(Request $request)
    {
        $nombre   = mb_strtolower(trim((string) $request->query('nombre', '')));
        $telefono = preg_replace('/\D/', '', (string) $request->query('telefono', ''));
        $email    = mb_strtolower(trim((string) $request->query('email', '')));

        if ($nombre === '' && $email === '' && $telefono === '') {
            return response()->json([]);
        }

        $candidatos = Cliente::query()
            ->when($nombre !== '',   fn ($q) => $q->orWhereRaw('LOWER(nombre) = ?', [$nombre]))
            ->when($email !== '',    fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [$email]))
            ->when($telefono !== '', fn ($q) => $q->orWhere('telefono', 'like', '%' . $telefono . '%'))
            ->limit(80)
            ->get(['id', 'nombre', 'telefono', 'email', 'cedula', 'tipo']);

        $matches = $candidatos->filter(function ($c) use ($nombre, $telefono, $email) {
            $mismoEmail  = $email !== '' && mb_strtolower((string) $c->email) === $email;
            $mismoNombre = $nombre !== '' && mb_strtolower((string) $c->nombre) === $nombre;
            $mismoTel    = $telefono !== '' && preg_replace('/\D/', '', (string) $c->telefono) === $telefono;
            return $mismoEmail || ($mismoNombre && $mismoTel);
        })->take(5)->values();

        return response()->json($matches);
    }

    /**
     * DELETE /api/clientes/{id}  (solo supervisor)
     *
     * Elimina un cliente. Se bloquea si tiene órdenes registradas para no
     * romper el historial (no hay borrado suave en esta tabla).
     */
    public function destroy(int $id)
    {
        $cliente = Cliente::findOrFail($id);

        $numOrdenes = $cliente->ordenes()->count();
        if ($numOrdenes > 0) {
            return response()->json([
                'message' => "No se puede eliminar a \"{$cliente->nombre}\" porque tiene {$numOrdenes} orden(es) registrada(s).",
            ], 422);
        }

        $cliente->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/clientes/exportar?tipo=oficial|interesado|&search=
     */
    public function exportar(Request $request)
    {
        $tipo   = $request->query('tipo', '');
        $search = $request->query('search', '');

        $clientes = DB::table('clientes')
            ->when($search, function ($q) use ($search) {
                $term = '%' . mb_strtolower($search) . '%';
                $q->where(function ($q2) use ($term) {
                    $q2->whereRaw('LOWER(nombre) LIKE ?',   [$term])
                       ->orWhereRaw('LOWER(cedula) LIKE ?',   [$term])
                       ->orWhereRaw('LOWER(telefono) LIKE ?', [$term]);
                });
            })
            ->when($tipo, fn($q) => $q->where('tipo', $tipo))
            ->orderBy('nombre')
            ->get();

        $ids = $clientes->pluck('id');

        // Resumen de compras (sin canceladas), sin cruzar pagos para evitar duplicados
        $ordenStats = DB::table('ordenes')
            ->whereIn('cliente_id', $ids)
            ->where('estado', '!=', 'cancelado')
            ->selectRaw('cliente_id, COUNT(*) AS total_ordenes, SUM(valor_total) AS valor_total_compras, MAX(created_at) AS ultima_compra')
            ->groupBy('cliente_id')
            ->get()
            ->keyBy('cliente_id');

        // Total pagado por cliente
        $pagoStats = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereIn('o.cliente_id', $ids)
            ->selectRaw('o.cliente_id, SUM(p.monto) AS total_pagado')
            ->groupBy('o.cliente_id')
            ->get()
            ->keyBy('cliente_id');

        [$rows, $headings, $filename, $title] = match (true) {
            $tipo === 'oficial'    => $this->rowsOficiales($clientes, $ordenStats, $pagoStats),
            $tipo === 'interesado' => $this->rowsInteresados($clientes),
            default                => $this->rowsTodos($clientes, $ordenStats, $pagoStats),
        };

        return Excel::download(
            new ReporteExport(collect($rows), $headings, $title),
            $filename
        );
    }

    private function rowsOficiales($clientes, $ordenStats, $pagoStats): array
    {
        $rows = $clientes->map(function ($c) use ($ordenStats, $pagoStats) {
            $os = $ordenStats->get($c->id);
            $ps = $pagoStats->get($c->id);
            $valorTotal = (float) ($os->valor_total_compras ?? 0);
            $pagado     = (float) ($ps->total_pagado ?? 0);
            return [
                $c->nombre,
                $c->cedula      ?? '',
                $c->telefono    ?? '',
                $c->email       ?? '',
                $c->direccion   ?? '',
                $c->canal_pref  ?? '',
                (int) ($os->total_ordenes ?? 0),
                number_format($valorTotal, 0, '.', ','),
                number_format($pagado, 0, '.', ','),
                number_format(max(0, $valorTotal - $pagado), 0, '.', ','),
                $os?->ultima_compra ? date('Y-m-d', strtotime($os->ultima_compra)) : '',
                date('Y-m-d', strtotime($c->created_at)),
            ];
        });

        return [
            $rows,
            ['Nombre', 'Cédula', 'Teléfono', 'Email', 'Dirección', 'Canal preferido',
             'Total órdenes', 'Valor total compras (COP)', 'Total pagado (COP)',
             'Saldo pendiente (COP)', 'Última compra', 'Fecha registro'],
            'clientes_oficiales_' . now()->format('Ymd') . '.xlsx',
            'Clientes Oficiales',
        ];
    }

    private function rowsInteresados($clientes): array
    {
        $rows = $clientes->map(fn($c) => [
            $c->nombre,
            $c->cedula     ?? '',
            $c->telefono   ?? '',
            $c->email      ?? '',
            $c->direccion  ?? '',
            $c->canal_pref ?? '',
            implode(', ', json_decode($c->categorias_interes ?? '[]', true) ?? []),
            $c->notas_interes ?? '',
            date('Y-m-d', strtotime($c->created_at)),
        ]);

        return [
            $rows,
            ['Nombre', 'Cédula', 'Teléfono', 'Email', 'Dirección', 'Canal preferido',
             'Categorías de interés', 'Notas de interés', 'Fecha registro'],
            'clientes_interesados_' . now()->format('Ymd') . '.xlsx',
            'Clientes Interesados',
        ];
    }

    private function rowsTodos($clientes, $ordenStats, $pagoStats): array
    {
        $rows = $clientes->map(function ($c) use ($ordenStats, $pagoStats) {
            $os = $ordenStats->get($c->id);
            $ps = $pagoStats->get($c->id);
            $valorTotal = (float) ($os->valor_total_compras ?? 0);
            $pagado     = (float) ($ps->total_pagado ?? 0);
            return [
                $c->tipo === 'oficial' ? 'Oficial' : 'Interesado',
                $c->nombre,
                $c->cedula     ?? '',
                $c->telefono   ?? '',
                $c->email      ?? '',
                $c->direccion  ?? '',
                $c->canal_pref ?? '',
                (int) ($os->total_ordenes ?? 0),
                number_format($valorTotal, 0, '.', ','),
                number_format($pagado, 0, '.', ','),
                number_format(max(0, $valorTotal - $pagado), 0, '.', ','),
                $os?->ultima_compra ? date('Y-m-d', strtotime($os->ultima_compra)) : '',
                implode(', ', json_decode($c->categorias_interes ?? '[]', true) ?? []),
                $c->notas_interes ?? '',
                date('Y-m-d', strtotime($c->created_at)),
            ];
        });

        return [
            $rows,
            ['Tipo', 'Nombre', 'Cédula', 'Teléfono', 'Email', 'Dirección', 'Canal preferido',
             'Total órdenes', 'Valor total compras (COP)', 'Total pagado (COP)',
             'Saldo pendiente (COP)', 'Última compra', 'Categorías de interés',
             'Notas de interés', 'Fecha registro'],
            'todos_los_clientes_' . now()->format('Ymd') . '.xlsx',
            'Todos los Clientes',
        ];
    }

    /**
     * GET /api/clientes/{id}/ordenes
     */
    public function ordenes(Request $request, int $id)
    {
        Cliente::findOrFail($id);

        $ordenes = Orden::with([
            'tienda:id,nombre',
            'vendedor:id,nombre',
        ])
            ->withSum('pagos', 'monto')
            ->where('cliente_id', $id)
            ->orderByDesc('created_at')
            ->paginate(15);

        $ordenes->getCollection()->transform(function ($o) {
            $o->total_pagado    = (float) ($o->pagos_sum_monto ?? 0);
            $o->saldo_pendiente = (float) $o->valor_total - $o->total_pagado;
            return $o;
        });

        return response()->json($ordenes);
    }
}
