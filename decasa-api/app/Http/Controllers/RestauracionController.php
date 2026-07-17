<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\Usuario;
use App\Services\NotificacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestauracionController extends Controller
{
    /**
     * GET /api/restauraciones
     */
    public function index(Request $request)
    {
        $usuario = $request->user();

        $query = Orden::with([
            'cliente:id,nombre,telefono',
            'vendedor:id,nombre',
            'tienda:id,nombre',
            'items.produccion.pasos',
            'items.produccion.pasoActual',
        ])
        ->where('tipo', 'restauracion')
        ->withSum('pagos', 'monto');

        if ($usuario->rol === 'vendedor') {
            $query->where('vendedor_id', $usuario->id);
        }

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->whereHas('cliente', fn($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$term]));
        }

        return $query->orderByDesc('created_at')->paginate(20);
    }

    /**
     * POST /api/restauraciones
     * Crea la orden y la manda directo a producción (sin anticipo, sin inventario).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id'                        => 'required|exists:clientes,id',
            'tienda_id'                         => 'required|exists:tiendas,id',
            'notas'                             => 'nullable|string|max:1000',
            'items'                             => 'required|array|min:1',
            'items.*.nombre_mueble'             => 'required|string|max:200',
            'items.*.descripcion_trabajo'       => 'nullable|string|max:500',
            'items.*.cantidad'                  => 'required|integer|min:1',
            'items.*.precio_unitario'           => 'required|numeric|min:0',
            'items.*.retapizar'                 => 'nullable|boolean',
            'items.*.tela'                      => 'nullable|string|max:200',
        ]);

        $valorTotal = collect($data['items'])->sum(
            fn($i) => $i['cantidad'] * $i['precio_unitario']
        );

        $orden = DB::transaction(function () use ($data, $valorTotal, $request) {
            $orden = Orden::create([
                'cliente_id'  => $data['cliente_id'],
                'vendedor_id' => $request->user()->id,
                'tienda_id'   => $data['tienda_id'],
                'canal'       => 'fisica',
                'tipo'        => 'restauracion',
                'estado'      => 'en_produccion',
                'valor_total' => $valorTotal,
                'anticipo_pct' => 0,
                'notas'       => $data['notas'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $specs = [];
                if (!empty($itemData['descripcion_trabajo'])) {
                    $specs['descripcion_trabajo'] = $itemData['descripcion_trabajo'];
                }
                if (!empty($itemData['retapizar'])) {
                    $specs['retapizar'] = true;
                    if (!empty($itemData['tela'])) {
                        $specs['tela'] = $itemData['tela'];
                    }
                }
                $specs = empty($specs) ? null : $specs;

                $item = OrdenItem::create([
                    'orden_id'              => $orden->id,
                    'nombre_custom'         => $itemData['nombre_mueble'],
                    'cantidad'              => $itemData['cantidad'],
                    'precio_unitario'       => $itemData['precio_unitario'],
                    'es_personalizado'      => true,
                    'specs_personalizacion' => $specs,
                ]);

                Produccion::create([
                    'orden_item_id' => $item->id,
                    'fecha_inicio'  => now()->toDateString(),
                    'estado'        => 'pendiente',
                ]);
            }

            OrdenController::asignarNumeroOrden($orden);

            return $orden;
        });

        $orden->loadMissing('cliente:id,nombre');

        // Notificar a supervisores de la tienda que hay una nueva restauración en producción
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->where('tienda_default_id', $orden->tienda_id)
            ->get();

        foreach ($supervisores as $sup) {
            NotificacionService::crear(
                'venta_nueva',
                'Nueva restauración en producción',
                "Restauración de {$orden->cliente->nombre} ingresó directo a producción",
                ['orden_id' => $orden->id],
                $sup->id,
            );
        }

        return response()->json(
            $orden->load(['cliente:id,nombre,telefono', 'items.produccion']),
            201
        );
    }
}
