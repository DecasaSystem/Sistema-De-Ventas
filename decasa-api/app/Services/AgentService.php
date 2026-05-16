<?php

namespace App\Services;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;

class AgentService
{
    // ─── Multiplicadores de escala por sección ────────────────────────────────
    private const ESCALA = [
        'TAPICERIA'       => 1.00,
        'TELA'            => 1.00,
        'CORTE Y COSTURA' => 1.00,
        'ESQUELETERIA'    => 0.85,
        'CARPINTERIA'     => 0.85,
        'HERRAJES'        => 0.90,
        'ACABADOS'        => 0.90,
        'LACA'            => 0.80,
        'PINTURA'         => 0.80,
        'MATERIALES'      => 0.90,
        'default'         => 0.90,
    ];

    // ─── Definición de tools para OpenAI ─────────────────────────────────────

    private function toolsDefinition(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'obtener_ficha_tecnica',
                    'description' => 'Obtiene la ficha técnica (costos de producción) de un producto. Opcionalmente escala los costos si se piden más puestos que los del producto base.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre_producto' => ['type' => 'string', 'description' => 'Nombre o parte del nombre del producto'],
                            'puestos_nuevo'   => ['type' => 'integer', 'description' => 'Número de puestos deseado. Si difiere del base, se calculan costos escalados.'],
                        ],
                        'required' => ['nombre_producto'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'buscar_fichas_por_categoria',
                    'description' => 'Lista las fichas técnicas disponibles, opcionalmente filtradas por categoría o búsqueda.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'categoria' => ['type' => 'string', 'description' => 'Categoría del mueble (comedor, sala, alcoba, etc.)'],
                            'busqueda'  => ['type' => 'string', 'description' => 'Texto de búsqueda libre'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'calcular_costo_personalizado',
                    'description' => 'Estima el costo de fabricación de un producto a medida usando precios del catálogo de materiales y fichas técnicas similares como referencia.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'descripcion_producto' => ['type' => 'string', 'description' => 'Descripción del mueble a fabricar'],
                            'categoria'            => ['type' => 'string', 'description' => 'Categoría: comedor, sala, alcoba, etc.'],
                            'num_puestos'          => ['type' => 'integer', 'description' => 'Número de puestos o módulos'],
                        ],
                        'required' => ['descripcion_producto', 'categoria'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_inventario',
                    'description' => 'Consulta el stock de productos por tienda. Devuelve tres campos por producto/tienda: total_fisico (unidades físicas en la tienda), reservado (apartado para órdenes pendientes) y libre (fisico - reservado, disponible para vender). Siempre muestra los tres campos al usuario. Con con_reservas=true muestra qué órdenes/clientes/vendedores tienen reservados esos productos.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'producto'        => ['type' => 'string', 'description' => 'Nombre o parte del nombre del producto (opcional)'],
                            'tienda_id'       => ['type' => 'integer', 'description' => 'ID de tienda específica (opcional)'],
                            'nombre_tienda'   => ['type' => 'string', 'description' => 'Nombre parcial de la tienda (alternativa a tienda_id)'],
                            'solo_bajo_stock' => ['type' => 'boolean', 'description' => 'Solo mostrar productos bajo stock mínimo'],
                            'todas_tiendas'   => ['type' => 'boolean', 'description' => 'Agrupar resultados mostrando todas las tiendas por producto'],
                            'con_reservas'    => ['type' => 'boolean', 'description' => 'Muestra qué órdenes están reservando las unidades, con cliente y vendedor de cada una'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'productos_mas_vendidos',
                    'description' => 'Muestra los productos más vendidos por cantidad o valor en un período dado.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'periodo'   => ['type' => 'string', 'description' => 'Período: hoy, semana, mes, mes_anterior, anio, o "YYYY-MM-DD,YYYY-MM-DD"'],
                            'tienda_id' => ['type' => 'integer', 'description' => 'Filtrar por tienda específica (opcional)'],
                            'categoria' => [
                                'type' => 'string',
                                'enum' => ['sillas_aux', 'sillas_barra', 'sillas_comedor', 'sofas', 'sofa_camas', 'sofas_modulares', 'camas', 'colchones', 'comedores', 'escritorios', 'mesas_noche', 'mesas_aux', 'mesas_centro', 'mesas_tv', 'cajoneros'],
                                'description' => 'Filtrar por categoría de producto (opcional)',
                            ],
                            'top_n'     => ['type' => 'integer', 'description' => 'Cuántos productos mostrar (default 10)'],
                            'criterio'  => ['type' => 'string', 'enum' => ['cantidad', 'valor'], 'description' => 'Ordenar por cantidad o por valor'],
                        ],
                        'required' => ['periodo'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'ventas_por_categoria',
                    'description' => 'Muestra el resumen de ventas agrupado por categoría de producto.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'periodo'   => ['type' => 'string', 'description' => 'Período: hoy, semana, mes, mes_anterior, anio'],
                            'tienda_id' => ['type' => 'integer', 'description' => 'Filtrar por tienda (opcional)'],
                            'categoria' => [
                                'type' => 'string',
                                'enum' => ['sillas_aux', 'sillas_barra', 'sillas_comedor', 'sofas', 'sofa_camas', 'sofas_modulares', 'camas', 'colchones', 'comedores', 'escritorios', 'mesas_noche', 'mesas_aux', 'mesas_centro', 'mesas_tv', 'cajoneros'],
                                'description' => 'Filtrar por categoría (opcional)',
                            ],
                        ],
                        'required' => ['periodo'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'ventas_producto_especifico',
                    'description' => 'Muestra el historial de ventas detallado de un producto específico o de todos los productos de una categoría, con desglose mensual y por tienda. El período es opcional — si no se especifica, busca en todo el historial. Úsala para "cuántas sillas auxiliares alicia se han vendido", "las más vendidas de sillas auxiliares", etc.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre_producto' => ['type' => 'string', 'description' => 'Nombre o palabras clave del producto (búsqueda flexible). Dejar vacío si se filtra solo por categoría.'],
                            'categoria'       => [
                                'type' => 'string',
                                'enum' => ['sillas_aux', 'sillas_barra', 'sillas_comedor', 'sofas', 'sofa_camas', 'sofas_modulares', 'camas', 'colchones', 'comedores', 'escritorios', 'mesas_noche', 'mesas_aux', 'mesas_centro', 'mesas_tv', 'cajoneros'],
                                'description' => 'Filtrar por categoría de producto',
                            ],
                            'periodo'         => ['type' => 'string', 'description' => 'Período opcional: hoy, semana, mes, mes_anterior, anio. Si no se indica, busca todo el historial.'],
                            'tienda_id'       => ['type' => 'integer', 'description' => 'Filtrar por tienda (opcional)'],
                            'nombre_tienda'   => ['type' => 'string', 'description' => 'Nombre parcial de tienda (alternativa a tienda_id)'],
                            'agrupar_por'     => ['type' => 'string', 'enum' => ['tienda', 'mes', 'ambos'], 'description' => 'Cómo agrupar los resultados'],
                            'top_n'           => ['type' => 'integer', 'description' => 'Cuando se filtra por categoría, muestra los N productos más vendidos de esa categoría (default 10)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'clientes_top',
                    'description' => 'Muestra los clientes que más han comprado por valor o número de órdenes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'top_n'     => ['type' => 'integer', 'description' => 'Cuántos clientes mostrar (default 10)'],
                            'periodo'   => ['type' => 'string', 'description' => 'Período: hoy, semana, mes, mes_anterior, anio'],
                            'tienda_id' => ['type' => 'integer', 'description' => 'Filtrar por tienda (opcional)'],
                            'criterio'  => ['type' => 'string', 'enum' => ['valor', 'ordenes'], 'description' => 'Ordenar por valor o por número de órdenes'],
                        ],
                        'required' => ['periodo'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'estado_produccion',
                    'description' => 'Consulta el estado actual de los items en el taller/fábrica de producción. Úsala SIEMPRE cuando el usuario pregunte: "hay productos en producción", "qué hay en el taller", "qué se está fabricando", "hay items retrasados", "qué está listo". Esta herramienta consulta la tabla de producción directamente, NO los estados de órdenes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'estado'          => ['type' => 'string', 'enum' => ['pendiente', 'en_proceso', 'listo', 'retrasado', 'completado', 'entregado'], 'description' => 'Filtrar por estado de producción (opcional). "listo" = fabricado pero no despachado aún.'],
                            'paso'            => ['type' => 'string', 'description' => 'Filtrar por paso de producción: tapiceria, esqueleteria, laca, etc. (opcional)'],
                            'solo_retrasados' => ['type' => 'boolean', 'description' => 'Solo mostrar items retrasados'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_ordenes',
                    'description' => 'Lista órdenes y muestra un resumen de conteos por estado. Estados válidos: pendiente_anticipo, en_produccion, listo_entrega, en_camino, entregado, cancelado. Frases comunes → estado correcto: "listas para entregar/lista entrega/listos para despacho" → listo_entrega | "en camino/con el conductor/en ruta" → en_camino | "en producción/fabricando" → en_produccion | "pendiente de anticipo/esperando pago" → pendiente_anticipo. NUNCA uses tienda_id si no lo conoces con certeza — usa nombre_tienda con el nombre parcial en su lugar.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'estado'         => [
                                'type' => 'string',
                                'enum' => ['pendiente_anticipo', 'en_produccion', 'listo_entrega', 'en_camino', 'entregado', 'cancelado'],
                                'description' => 'Filtrar por estado exacto',
                            ],
                            'nombre_tienda'  => ['type' => 'string', 'description' => 'Nombre parcial de la tienda (ej: "Jardines", "Bolívar"). Se hace búsqueda por LIKE. Usar esto en lugar de tienda_id cuando no se conoce el ID.'],
                            'tienda_id'      => ['type' => 'integer', 'description' => 'ID numérico exacto de la tienda (solo si se conoce con certeza)'],
                            'cliente_nombre'  => ['type' => 'string', 'description' => 'Buscar por nombre de cliente (opcional)'],
                            'nombre_producto' => ['type' => 'string', 'description' => 'Filtrar órdenes que contengan este producto (nombre parcial)'],
                            'solo_con_saldo'  => ['type' => 'boolean', 'description' => 'Solo órdenes con saldo pendiente de pago'],
                            'periodo'        => ['type' => 'string', 'description' => 'Período: hoy, semana, mes, mes_anterior, anio (opcional)'],
                            'limit'          => ['type' => 'integer', 'description' => 'Cuántas órdenes mostrar (default 20)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_trabajadores',
                    'description' => 'Consulta información de los trabajadores (usuarios) del sistema: cantidad total, listado por rol o tienda, y detalle de cada uno. Úsala para preguntas como "cuántos trabajadores hay", "quiénes son los conductores", "quién trabaja en la tienda X", "lista de vendedores", etc.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'rol'          => [
                                'type' => 'string',
                                'enum' => ['vendedor', 'supervisor', 'conductor', 'ebanista', 'despachador'],
                                'description' => 'Filtrar por rol específico (opcional)',
                            ],
                            'nombre_tienda' => ['type' => 'string', 'description' => 'Nombre parcial de la tienda para filtrar trabajadores de esa tienda'],
                            'tienda_id'     => ['type' => 'integer', 'description' => 'ID de tienda para filtrar (opcional)'],
                            'solo_activos'  => ['type' => 'boolean', 'description' => 'Solo mostrar trabajadores activos (default true)'],
                            'solo_conteo'   => ['type' => 'boolean', 'description' => 'Solo devuelve el conteo total y por rol, sin listar nombres'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_clientes',
                    'description' => 'Consulta información de clientes: total de clientes, búsqueda por nombre/teléfono, historial de compras de un cliente, y estadísticas generales. Úsala cuando el usuario pregunte: "cuántos clientes hay", "busca al cliente X", "qué ha comprado el cliente Y", "clientes nuevos", etc.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'busqueda'     => ['type' => 'string', 'description' => 'Buscar cliente por nombre o teléfono (búsqueda parcial)'],
                            'cliente_id'   => ['type' => 'integer', 'description' => 'ID exacto del cliente para ver su detalle completo'],
                            'solo_conteo'  => ['type' => 'boolean', 'description' => 'Solo devuelve el número total de clientes registrados'],
                            'con_ordenes'  => ['type' => 'boolean', 'description' => 'Incluir resumen de órdenes por cliente'],
                            'periodo'      => ['type' => 'string', 'description' => 'Filtrar clientes con actividad en el período: hoy, semana, mes, mes_anterior, anio'],
                            'tienda_id'    => ['type' => 'integer', 'description' => 'Filtrar por tienda (opcional)'],
                            'nombre_tienda'=> ['type' => 'string', 'description' => 'Nombre parcial de la tienda (alternativa a tienda_id)'],
                            'limit'        => ['type' => 'integer', 'description' => 'Cuántos clientes mostrar (default 20)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'buscar_productos_catalogo',
                    'description' => 'Lista los productos del catálogo filtrados por categoría o búsqueda de nombre. Úsala cuando el usuario pregunte "qué modelos de sillas auxiliares tenemos", "cuántos productos hay en la categoría sofás", etc.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'categoria' => [
                                'type' => 'string',
                                'enum' => ['sillas_aux', 'sillas_barra', 'sillas_comedor', 'sofas', 'sofa_camas', 'sofas_modulares', 'camas', 'colchones', 'comedores', 'escritorios', 'mesas_noche', 'mesas_aux', 'mesas_centro', 'mesas_tv', 'cajoneros'],
                                'description' => 'Categoría de producto',
                            ],
                            'busqueda' => ['type' => 'string', 'description' => 'Buscar por nombre de producto (opcional)'],
                            'solo_activos' => ['type' => 'boolean', 'description' => 'Solo productos activos (default true)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'reporte_ventas',
                    'description' => 'Reporte completo de ventas: resumen total, ingresos cobrados, ticket promedio, desglose por día y por tienda. Equivale al módulo "Ventas" del supervisor.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'periodo'      => ['type' => 'string', 'description' => 'Período: hoy, semana, mes, mes_anterior, anio'],
                            'tienda_id'    => ['type' => 'integer', 'description' => 'Filtrar por tienda (opcional)'],
                            'nombre_tienda'=> ['type' => 'string', 'description' => 'Nombre parcial de tienda (opcional)'],
                        ],
                        'required' => ['periodo'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'reporte_vendedores',
                    'description' => 'Rendimiento de cada vendedor: órdenes realizadas, total cobrado y ticket promedio. Equivale al módulo "Vendedores" del supervisor.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'periodo' => ['type' => 'string', 'description' => 'Período: hoy, semana, mes, mes_anterior, anio'],
                        ],
                        'required' => ['periodo'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'reporte_pendientes',
                    'description' => 'Órdenes activas (no entregadas ni canceladas) con su saldo pendiente de cobro. Equivale al módulo "Pendientes/Cartera" del supervisor.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'tienda_id'    => ['type' => 'integer', 'description' => 'Filtrar por tienda (opcional)'],
                            'nombre_tienda'=> ['type' => 'string', 'description' => 'Nombre parcial de tienda (opcional)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'reporte_retrasos',
                    'description' => 'Items de producción que están retrasados o vencieron su fecha compromiso. Equivale al módulo "Retrasos" del supervisor.',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'listar_tiendas',
                    'description' => 'Devuelve la lista de todas las tiendas con su ID y nombre. Úsala cuando el usuario mencione una tienda y necesites saber su ID, o para confirmar el nombre exacto antes de filtrar.',
                    'parameters' => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
        ];
    }

    // ─── Parsear período a rango de fechas ────────────────────────────────────

    private function parsePeriodo(string $periodo): array
    {
        $hoy = Carbon::now();

        if (str_contains($periodo, ',')) {
            [$desde, $hasta] = explode(',', $periodo);
            return [trim($desde), trim($hasta)];
        }

        return match ($periodo) {
            'hoy'          => [$hoy->toDateString(), $hoy->toDateString()],
            'semana'       => [$hoy->copy()->startOfWeek()->toDateString(), $hoy->toDateString()],
            'mes'          => [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()],
            'mes_anterior' => [
                $hoy->copy()->subMonth()->startOfMonth()->toDateString(),
                $hoy->copy()->subMonth()->endOfMonth()->toDateString(),
            ],
            'anio'         => [$hoy->copy()->startOfYear()->toDateString(), $hoy->toDateString()],
            default        => [$hoy->copy()->startOfMonth()->toDateString(), $hoy->toDateString()],
        };
    }

    // ─── Handlers de cada tool ────────────────────────────────────────────────

    private function handleObtenerFichaTecnica(array $args): array
    {
        $nombre = $args['nombre_producto'];

        $ficha = DB::table('fichas_tecnicas')
            ->where('nombre', 'like', "%{$nombre}%")
            ->orderByRaw('CHAR_LENGTH(nombre) ASC')
            ->first();

        if (!$ficha) {
            // Buscar la más similar
            $fichas = DB::table('fichas_tecnicas')
                ->where('nombre', 'like', "%{$nombre}%")
                ->orWhere('categoria', 'like', "%{$nombre}%")
                ->limit(5)
                ->get(['id', 'nombre', 'categoria', 'costo_total']);

            if ($fichas->isEmpty()) {
                return ['encontrado' => false, 'mensaje' => "No se encontró ninguna ficha técnica para '{$nombre}'."];
            }

            return [
                'encontrado'    => false,
                'sugerencias'   => $fichas,
                'mensaje'       => "No se encontró exactamente '{$nombre}'. Productos similares encontrados:",
            ];
        }

        $items = DB::table('ficha_tecnica_items')
            ->where('ficha_tecnica_id', $ficha->id)
            ->orderBy('orden')
            ->get();

        $resultado = [
            'encontrado'       => true,
            'ficha'            => $ficha,
            'items'            => $items,
            'costo_materiales' => $ficha->costo_materiales,
            'costo_mano_obra'  => $ficha->costo_mano_obra,
            'costo_total'      => $ficha->costo_total,
        ];

        // Escalado por número de puestos
        if (isset($args['puestos_nuevo'])) {
            $resultado['escalado'] = $this->escalarFicha($ficha, $items, (int) $args['puestos_nuevo']);
        }

        return $resultado;
    }

    private function escalarFicha(object $ficha, $items, int $puestosNuevo): array
    {
        // Detectar puestos base desde el nombre (ej: "Comedor Roma 4P" → 4)
        preg_match('/(\d+)\s*[Pp]/', $ficha->nombre, $m);
        $puestosBase = isset($m[1]) ? (int) $m[1] : 4;

        if ($puestosBase === $puestosNuevo) {
            return ['mensaje' => 'El número de puestos es igual al producto base.', 'costo_total' => $ficha->costo_total];
        }

        $factorBase = $puestosNuevo / $puestosBase;

        $itemsEscalados = collect($items)->map(function ($item) use ($factorBase) {
            $seccion    = strtoupper($item->seccion ?? 'default');
            $escala     = self::ESCALA[$seccion] ?? self::ESCALA['default'];
            $factor     = $item->es_mano_obra ? ($factorBase * 0.70) : ($factorBase * $escala);
            $subtotalNuevo = round($item->subtotal * $factor, 2);

            return [
                'seccion'          => $item->seccion,
                'descripcion'      => $item->descripcion,
                'subtotal_base'    => $item->subtotal,
                'subtotal_escalado'=> $subtotalNuevo,
                'es_mano_obra'     => $item->es_mano_obra,
            ];
        });

        $costoMateriales = $itemsEscalados->where('es_mano_obra', false)->sum('subtotal_escalado');
        $costoManoObra   = $itemsEscalados->where('es_mano_obra', true)->sum('subtotal_escalado');

        return [
            'puestos_base'     => $puestosBase,
            'puestos_nuevo'    => $puestosNuevo,
            'costo_materiales' => round($costoMateriales, 2),
            'costo_mano_obra'  => round($costoManoObra, 2),
            'costo_total'      => round($costoMateriales + $costoManoObra, 2),
            'items'            => $itemsEscalados->values(),
        ];
    }

    private function handleBuscarFichasPorCategoria(array $args): array
    {
        $query = DB::table('fichas_tecnicas');

        if (!empty($args['categoria'])) {
            $query->where('categoria', 'like', "%{$args['categoria']}%");
        }
        if (!empty($args['busqueda'])) {
            $query->where(function ($q) use ($args) {
                $q->where('nombre', 'like', "%{$args['busqueda']}%")
                  ->orWhere('categoria', 'like', "%{$args['busqueda']}%");
            });
        }

        $fichas = $query->orderBy('categoria')->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria', 'costo_materiales', 'costo_mano_obra', 'costo_total']);

        $categorias = DB::table('fichas_tecnicas')->distinct()->orderBy('categoria')->pluck('categoria');

        return [
            'fichas'     => $fichas,
            'categorias' => $categorias,
            'total'      => $fichas->count(),
        ];
    }

    private function handleCalcularCostoPersonalizado(array $args): array
    {
        $categoria = $args['categoria'];
        $numPuestos = $args['num_puestos'] ?? null;

        // Buscar fichas similares como referencia
        $fichasRef = DB::table('fichas_tecnicas')
            ->where('categoria', 'like', "%{$categoria}%")
            ->orderBy('costo_total')
            ->limit(3)
            ->get(['id', 'nombre', 'costo_materiales', 'costo_mano_obra', 'costo_total']);

        // Precios de materiales del catálogo
        $materiales = DB::table('materiales')
            ->orderBy('nombre')
            ->get(['nombre', 'unidad', 'precio_unitario']);

        if ($fichasRef->isEmpty()) {
            return [
                'mensaje'    => "No hay fichas técnicas de referencia para la categoría '{$categoria}'.",
                'materiales' => $materiales,
            ];
        }

        $promedioBase = $fichasRef->avg('costo_total');
        $minBase      = $fichasRef->min('costo_total');
        $maxBase      = $fichasRef->max('costo_total');

        // Si se piden N puestos, escalar el promedio
        $factor = 1;
        if ($numPuestos) {
            preg_match('/(\d+)\s*[Pp]/', $fichasRef->first()->nombre ?? '', $m);
            $puestosRef = isset($m[1]) ? (int) $m[1] : 4;
            $factor     = max(0.5, $numPuestos / $puestosRef);
        }

        return [
            'descripcion'        => $args['descripcion_producto'],
            'categoria'          => $categoria,
            'puestos_solicitados'=> $numPuestos,
            'fichas_referencia'  => $fichasRef,
            'estimado_min'       => round($minBase * $factor * 0.90, 0),
            'estimado_promedio'  => round($promedioBase * $factor, 0),
            'estimado_max'       => round($maxBase * $factor * 1.15, 0),
            'materiales_catalogo'=> $materiales->take(20),
            'nota'               => 'Estimado basado en ' . $fichasRef->count() . ' ficha(s) de referencia de la misma categoría.',
        ];
    }

    private function handleConsultarInventario(array $args, Usuario $usuario): array
    {
        // Resolver tienda por nombre si se proporcionó
        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->where('nombre', 'like', "%{$args['nombre_tienda']}%")
                ->value('id');
        }

        $query = DB::table('productos as p')
            ->crossJoin('tiendas as t')
            ->leftJoin('inventario as i', function ($join) {
                $join->on('i.producto_id', '=', 'p.id')
                     ->on('i.tienda_id',   '=', 't.id');
            })
            ->where('p.activo', true)
            ->where('t.activa', true)
            ->selectRaw("
                p.id   AS producto_id,
                p.nombre AS producto,
                p.categoria,
                t.id   AS tienda_id,
                t.nombre AS tienda,
                COALESCE(i.cantidad_disponible, 0) AS total_fisico,
                COALESCE(i.cantidad_reservada,  0) AS reservado,
                COALESCE(i.cantidad_disponible, 0) - COALESCE(i.cantidad_reservada, 0) AS libre,
                COALESCE(i.stock_minimo, 0) AS stock_minimo
            ");

        if ($usuario->rol === 'vendedor') {
            $query->where('t.id', $usuario->tienda_default_id);
        } elseif ($tiendaId) {
            $query->where('t.id', $tiendaId);
        }

        if (!empty($args['producto'])) {
            $query->where('p.nombre', 'like', "%{$args['producto']}%");
        }

        if (!empty($args['solo_bajo_stock'])) {
            $query->whereRaw('COALESCE(i.cantidad_disponible, 0) <= COALESCE(i.stock_minimo, 0)')
                  ->whereRaw('COALESCE(i.stock_minimo, 0) > 0');
        } else {
            $query->whereRaw('COALESCE(i.cantidad_disponible, 0) > 0');
        }

        $items = $query->orderBy('p.nombre')->orderBy('t.nombre')->get();

        $resumen = $items->groupBy('producto')->map(function ($grupo) {
            return [
                'producto'        => $grupo->first()->producto,
                'categoria'       => $grupo->first()->categoria,
                'total_fisico'    => $grupo->sum('total_fisico'),
                'total_libre'     => $grupo->sum('libre'),
                'total_reservado' => $grupo->sum('reservado'),
                'por_tienda'      => $grupo->map(fn($r) => [
                    'tienda'    => $r->tienda,
                    'fisico'    => $r->total_fisico,
                    'libre'     => $r->libre,
                    'reservado' => $r->reservado,
                ])->values(),
            ];
        })->values();

        $resultado = [
            'nota'            => 'total_fisico = unidades físicas. reservado = apartado para órdenes activas. libre = fisico - reservado.',
            'items'           => $items,
            'resumen'         => $resumen,
            'total_productos' => $items->groupBy('producto_id')->count(),
        ];

        // Detalle de qué órdenes generan las reservas
        if (!empty($args['con_reservas']) && $items->isNotEmpty()) {
            $productosConReserva = $items->where('reservado', '>', 0);

            if ($productosConReserva->isNotEmpty()) {
                $reservas = [];
                foreach ($productosConReserva as $inv) {
                    $ordenes = DB::table('orden_items as oi')
                        ->join('ordenes as o',   'o.id',  '=', 'oi.orden_id')
                        ->join('clientes as c',  'c.id',  '=', 'o.cliente_id')
                        ->join('usuarios as u',  'u.id',  '=', 'o.vendedor_id')
                        ->join('tiendas as t',   't.id',  '=', 'o.tienda_id')
                        ->where('oi.producto_id', $inv->producto_id)
                        ->where('o.tienda_id',    $inv->tienda_id)
                        ->whereNotIn('o.estado',  ['entregado', 'cancelado'])
                        ->selectRaw('
                            o.id AS orden_id,
                            o.estado,
                            o.created_at AS fecha_orden,
                            oi.cantidad,
                            c.nombre AS cliente,
                            c.telefono,
                            u.nombre AS vendedor,
                            t.nombre AS tienda
                        ')
                        ->get();

                    if ($ordenes->isNotEmpty()) {
                        $reservas[] = [
                            'producto'         => $inv->producto,
                            'tienda'           => $inv->tienda,
                            'total_reservado'  => $inv->reservado,
                            'ordenes_activas'  => $ordenes,
                        ];
                    }
                }
                $resultado['detalle_reservas'] = $reservas;
            }
        }

        return $resultado;
    }

    private function handleProductosMasVendidos(array $args, Usuario $usuario): array
    {
        [$desde, $hasta] = $this->parsePeriodo($args['periodo']);
        $topN      = min((int) ($args['top_n'] ?? 10), 50);
        $criterio  = $args['criterio'] ?? 'cantidad';
        $rango     = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $query = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw('p.id, p.nombre, p.categoria, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total')
            ->groupBy('p.id', 'p.nombre', 'p.categoria')
            ->orderByDesc($criterio === 'cantidad' ? 'cantidad' : 'valor_total')
            ->limit($topN);

        if ($usuario->rol === 'vendedor') {
            $query->where('o.tienda_id', $usuario->tienda_default_id);
        } elseif (!empty($args['tienda_id'])) {
            $query->where('o.tienda_id', $args['tienda_id']);
        }

        if (!empty($args['categoria'])) {
            $cat = $this->detectarCategoria($args['categoria']) ?? $args['categoria'];
            $query->where('p.categoria', 'like', "%{$cat}%");
        }

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'top'     => $query->get(),
            'criterio'=> $criterio,
        ];
    }

    private function handleVentasPorCategoria(array $args, Usuario $usuario): array
    {
        [$desde, $hasta] = $this->parsePeriodo($args['periodo']);
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $query = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw('COALESCE(p.categoria, "Sin categoría") AS categoria, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total, COUNT(DISTINCT p.id) AS num_productos')
            ->groupBy('categoria')
            ->orderByDesc('valor_total');

        if ($usuario->rol === 'vendedor') {
            $query->where('o.tienda_id', $usuario->tienda_default_id);
        } elseif (!empty($args['tienda_id'])) {
            $query->where('o.tienda_id', $args['tienda_id']);
        }

        return [
            'periodo'    => ['desde' => $desde, 'hasta' => $hasta],
            'categorias' => $query->get(),
        ];
    }

    private function handleBuscarProductosCatalogo(array $args): array
    {
        $query = DB::table('productos');

        $soloActivos = $args['solo_activos'] ?? true;
        if ($soloActivos) $query->where('activo', true);

        if (!empty($args['categoria'])) {
            $cat = $this->detectarCategoria($args['categoria']) ?? $args['categoria'];
            $query->where('categoria', 'like', "%{$cat}%");
        }

        if (!empty($args['busqueda'])) {
            $palabras = array_filter(explode(' ', trim($args['busqueda'])));
            foreach ($palabras as $p) {
                if (strlen($p) >= 3) $query->where('nombre', 'like', "%{$p}%");
            }
        }

        $productos = $query->orderBy('categoria')->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria', 'precio_venta', 'activo']);

        $porCategoria = $productos->groupBy('categoria')->map(fn($g) => $g->count());

        return [
            'total'        => $productos->count(),
            'por_categoria'=> $porCategoria,
            'productos'    => $productos,
        ];
    }

    // Mapa de términos del usuario → valor de categoría en BD
    private const CATEGORIA_SINONIMOS = [
        'sillas_aux'       => ['silla aux','sillas aux','silla auxiliar','sillas auxiliares','auxiliar','auxiliares'],
        'sillas_barra'     => ['silla barra','sillas barra','silla de barra','sillas de barra','taburete','taburetes','silla bar'],
        'sillas_comedor'   => ['silla comedor','sillas comedor','silla de comedor','sillas de comedor'],
        'sofas'            => ['sofa','sofas','sofá','sofás','sillon','sillón','sillones'],
        'sofa_camas'       => ['sofacama','sofacamas','sofa cama','sofá cama','cama sofa'],
        'sofas_modulares'  => ['sofa modular','sofas modulares','sofá modular','modular'],
        'camas'            => ['cama','camas','cabecero'],
        'colchones'        => ['colchon','colchones','colchoncillo'],
        'comedores'        => ['comedor','comedores','juego de comedor','sala comedor'],
        'escritorios'      => ['escritorio','escritorios','mesa de oficina','mesa oficina'],
        'mesas_noche'      => ['mesa noche','mesas noche','mesa de noche','mesas de noche','mesita noche','nochero','velador'],
        'mesas_aux'        => ['mesa aux','mesas aux','mesa auxiliar','mesas auxiliares','mesita auxiliar'],
        'mesas_centro'     => ['mesa centro','mesas centro','mesa de centro','mesas de centro','mesa sala'],
        'mesas_tv'         => ['mesa tv','mesas tv','mesa de tv','mesa de television','mesa de televisor','mueble tv','rack tv'],
        'cajoneros'        => ['cajonero','cajoneros','gaveta','gavetas','comoda','cómoda'],
    ];

    private function detectarCategoria(string $texto): ?string
    {
        $texto = strtolower(trim($texto));
        foreach (self::CATEGORIA_SINONIMOS as $categoria => $sinonimos) {
            foreach ($sinonimos as $sinonimo) {
                if (str_contains($texto, $sinonimo)) {
                    return $categoria;
                }
            }
            // También comparar con el nombre de la categoría directamente
            if (str_contains($texto, str_replace('_', ' ', $categoria))) {
                return $categoria;
            }
        }
        return null;
    }

    private function handleVentasProductoEspecifico(array $args, Usuario $usuario): array
    {
        $nombre  = $args['nombre_producto'] ?? null;
        $agrupar = $args['agrupar_por'] ?? 'ambos';

        // Si el modelo puso una categoría en nombre_producto, detectarla automáticamente
        if ($nombre && empty($args['categoria'])) {
            $categoriaDetectada = $this->detectarCategoria($nombre);
            if ($categoriaDetectada) {
                $args['categoria'] = $categoriaDetectada;
                $nombre = null; // limpiar para no filtrar por nombre
            }
        }

        // Resolver tienda
        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->where('nombre', 'like', "%{$args['nombre_tienda']}%")
                ->value('id');
        }
        if ($usuario->rol === 'vendedor') {
            $tiendaId = $usuario->tienda_default_id;
        }

        $baseQuery = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->whereNotIn('o.estado', ['cancelado']);

        // Filtro por categoría
        if (!empty($args['categoria'])) {
            $baseQuery->where('p.categoria', 'like', "%{$args['categoria']}%");
        }

        // Búsqueda flexible por nombre: cada palabra >= 3 letras debe estar presente
        if ($nombre) {
            $palabras = array_filter(explode(' ', trim($nombre)));
            foreach ($palabras as $palabra) {
                if (strlen($palabra) >= 3) {
                    $baseQuery->where('p.nombre', 'like', "%{$palabra}%");
                }
            }
        }

        // Período opcional
        $desde = null;
        $hasta = null;
        if (!empty($args['periodo'])) {
            [$desde, $hasta] = $this->parsePeriodo($args['periodo']);
            $baseQuery->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
        }

        if ($tiendaId) {
            $baseQuery->where('o.tienda_id', $tiendaId);
        }

        // Verificar si hay resultados
        $productosEncontrados = (clone $baseQuery)
            ->selectRaw('p.id, p.nombre, p.categoria')
            ->groupBy('p.id', 'p.nombre', 'p.categoria')
            ->get();

        if ($productosEncontrados->isEmpty()) {
            $sugerencias = collect();
            if ($nombre) {
                $primPalabra = collect(array_filter(explode(' ', trim($nombre))))->first(fn($p) => strlen($p) >= 4) ?? $nombre;
                $sugerencias = DB::table('productos')
                    ->where('nombre', 'like', "%{$primPalabra}%")
                    ->where('activo', true)->limit(5)->pluck('nombre');
            }
            return [
                'encontrado'  => false,
                'busqueda'    => $nombre ?? $args['categoria'] ?? '',
                'mensaje'     => "No se encontraron ventas con esos filtros.",
                'sugerencias' => $sugerencias,
            ];
        }

        $resultado = [
            'encontrado'            => true,
            'busqueda'              => $nombre,
            'categoria_filtrada'    => $args['categoria'] ?? null,
            'productos_encontrados' => $productosEncontrados,
            'periodo'               => $desde ? ['desde' => $desde, 'hasta' => $hasta] : 'todo el historial',
        ];

        $totales = (clone $baseQuery)
            ->selectRaw('SUM(oi.cantidad) AS total_cantidad, SUM(oi.cantidad * oi.precio_unitario) AS total_valor')
            ->first();
        $resultado['totales'] = $totales;

        // Ranking de productos dentro de la búsqueda (especialmente útil para categoría)
        $topN = min((int) ($args['top_n'] ?? 10), 50);
        $resultado['ranking_productos'] = (clone $baseQuery)
            ->selectRaw('p.nombre, p.categoria, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total')
            ->groupBy('p.id', 'p.nombre', 'p.categoria')
            ->orderByDesc('cantidad')
            ->limit($topN)
            ->get();

        if (in_array($agrupar, ['tienda', 'ambos'])) {
            $resultado['por_tienda'] = (clone $baseQuery)
                ->selectRaw('t.nombre AS tienda, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total')
                ->groupBy('t.id', 't.nombre')
                ->orderByDesc('cantidad')
                ->get();
        }

        if (in_array($agrupar, ['mes', 'ambos'])) {
            $resultado['por_mes'] = (clone $baseQuery)
                ->selectRaw('DATE_FORMAT(o.created_at, "%Y-%m") AS mes, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total')
                ->groupBy('mes')
                ->orderBy('mes')
                ->get();
        }

        return $resultado;
    }

    private function handleReporteVentas(array $args, Usuario $usuario): array
    {
        [$desde, $hasta] = $this->parsePeriodo($args['periodo']);
        $rango = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->where('nombre', 'like', "%{$args['nombre_tienda']}%")
                ->value('id');
        }
        if ($usuario->rol === 'vendedor') {
            $tiendaId = $usuario->tienda_default_id;
        }

        $base = DB::table('pagos as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereBetween('p.created_at', $rango);
        if ($tiendaId) $base->where('o.tienda_id', $tiendaId);

        $resumen = (clone $base)->selectRaw('
            COUNT(DISTINCT o.id)  AS total_ordenes,
            SUM(p.monto)          AS total_cobrado,
            SUM(o.valor_total)    AS valor_bruto,
            AVG(o.valor_total)    AS ticket_promedio
        ')->first();

        $porDia = (clone $base)
            ->selectRaw('DATE(p.created_at) AS fecha, SUM(p.monto) AS monto')
            ->groupByRaw('DATE(p.created_at)')
            ->orderBy('fecha')
            ->get();

        $porTienda = (clone $base)
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->selectRaw('t.nombre AS tienda, COUNT(DISTINCT o.id) AS ordenes, SUM(p.monto) AS monto')
            ->groupBy('t.id', 't.nombre')
            ->orderByDesc('monto')
            ->get();

        return [
            'desde'     => $desde,
            'hasta'     => $hasta,
            'resumen'   => $resumen,
            'por_dia'   => $porDia,
            'por_tienda'=> $porTienda,
        ];
    }

    private function handleReporteVendedores(array $args): array
    {
        [$desde, $hasta] = $this->parsePeriodo($args['periodo']);

        $vendedores = DB::table('usuarios as u')
            ->leftJoin('ordenes as o', function ($j) use ($desde, $hasta) {
                $j->on('o.vendedor_id', '=', 'u.id')
                  ->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
            })
            ->leftJoin('pagos as p', 'p.orden_id', '=', 'o.id')
            ->leftJoin('tiendas as t', 't.id', '=', 'u.tienda_default_id')
            ->where('u.rol', 'vendedor')
            ->where('u.activo', true)
            ->selectRaw('
                u.nombre            AS vendedor,
                t.nombre            AS tienda,
                COUNT(DISTINCT o.id)        AS total_ordenes,
                COALESCE(SUM(p.monto), 0)   AS total_cobrado,
                COALESCE(AVG(o.valor_total),0) AS ticket_promedio
            ')
            ->groupBy('u.id', 'u.nombre', 't.nombre')
            ->orderByDesc('total_cobrado')
            ->get();

        return [
            'desde'      => $desde,
            'hasta'      => $hasta,
            'vendedores' => $vendedores,
        ];
    }

    private function handleReportePendientes(array $args, Usuario $usuario): array
    {
        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->where('nombre', 'like', "%{$args['nombre_tienda']}%")
                ->value('id');
        }
        if ($usuario->rol === 'vendedor') {
            $tiendaId = $usuario->tienda_default_id;
        }

        $ordenes = DB::table('ordenes as o')
            ->join('clientes as c',  'c.id',  '=', 'o.cliente_id')
            ->join('usuarios as u',  'u.id',  '=', 'o.vendedor_id')
            ->join('tiendas as t',   't.id',  '=', 'o.tienda_id')
            ->leftJoin('pagos as pg', 'pg.orden_id', '=', 'o.id')
            ->whereNotIn('o.estado', ['entregado', 'cancelado'])
            ->when($tiendaId, fn($q) => $q->where('o.tienda_id', $tiendaId))
            ->selectRaw('
                o.id AS orden_id, o.estado, o.valor_total, o.created_at AS fecha,
                c.nombre AS cliente, c.telefono,
                u.nombre AS vendedor, t.nombre AS tienda,
                COALESCE(SUM(pg.monto), 0) AS total_pagado,
                o.valor_total - COALESCE(SUM(pg.monto), 0) AS saldo_pendiente
            ')
            ->groupBy('o.id', 'o.estado', 'o.valor_total', 'o.created_at',
                      'c.nombre', 'c.telefono', 'u.nombre', 't.nombre')
            ->orderByDesc('o.created_at')
            ->get();

        $totalSaldo = $ordenes->sum('saldo_pendiente');

        return [
            'total_ordenes_activas' => $ordenes->count(),
            'total_saldo_pendiente' => round($totalSaldo, 2),
            'ordenes'               => $ordenes,
        ];
    }

    private function handleReporteRetrasos(): array
    {
        $items = DB::table('produccion as pr')
            ->join('orden_items as oi', 'oi.id', '=', 'pr.orden_item_id')
            ->join('ordenes as o',      'o.id',  '=', 'oi.orden_id')
            ->join('clientes as c',     'c.id',  '=', 'o.cliente_id')
            ->join('productos as pd',   'pd.id', '=', 'oi.producto_id')
            ->join('usuarios as u',     'u.id',  '=', 'o.vendedor_id')
            ->join('tiendas as t',      't.id',  '=', 'o.tienda_id')
            ->where(function ($q) {
                $q->where('pr.estado', 'retrasado')
                  ->orWhere(fn($q2) =>
                      $q2->where('pr.estado', 'en_proceso')
                         ->whereRaw('pr.fecha_compromiso < CURDATE()')
                  );
            })
            ->selectRaw('
                pr.id AS produccion_id, o.id AS orden_id,
                c.nombre AS cliente, c.telefono,
                pd.nombre AS producto, oi.cantidad,
                pr.fecha_compromiso,
                DATEDIFF(CURDATE(), pr.fecha_compromiso) AS dias_retraso,
                pr.estado, pr.motivo_retraso,
                u.nombre AS vendedor, t.nombre AS tienda
            ')
            ->orderBy('pr.fecha_compromiso')
            ->get();

        return [
            'total_retrasados' => $items->count(),
            'items'            => $items,
        ];
    }

    private function handleClientesTop(array $args, Usuario $usuario): array
    {
        [$desde, $hasta] = $this->parsePeriodo($args['periodo']);
        $topN    = min((int) ($args['top_n'] ?? 10), 50);
        $criterio= $args['criterio'] ?? 'valor';
        $rango   = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $query = DB::table('ordenes as o')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->join('pagos as p', 'p.orden_id', '=', 'o.id')
            ->whereBetween('p.created_at', $rango)
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw('c.id, c.nombre, c.telefono, SUM(p.monto) AS total_pagado, COUNT(DISTINCT o.id) AS num_ordenes')
            ->groupBy('c.id', 'c.nombre', 'c.telefono')
            ->orderByDesc($criterio === 'ordenes' ? 'num_ordenes' : 'total_pagado')
            ->limit($topN);

        if ($usuario->rol === 'vendedor') {
            $query->where('o.tienda_id', $usuario->tienda_default_id);
        } elseif (!empty($args['tienda_id'])) {
            $query->where('o.tienda_id', $args['tienda_id']);
        }

        return [
            'periodo'  => ['desde' => $desde, 'hasta' => $hasta],
            'clientes' => $query->get(),
            'criterio' => $criterio,
        ];
    }

    private function handleEstadoProduccion(array $args): array
    {
        $query = DB::table('produccion as pr')
            ->join('orden_items as oi', 'oi.id', '=', 'pr.orden_item_id')
            ->join('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->whereNotIn('pr.estado', ['entregado'])
            ->selectRaw('
                pr.id, pr.estado, pr.fecha_inicio,
                pr.fecha_compromiso                            AS fecha_entrega_prometida,
                p.nombre AS producto, oi.cantidad,
                c.nombre AS cliente, t.nombre AS tienda,
                DATEDIFF(pr.fecha_compromiso, CURDATE())       AS dias_restantes_entrega,
                CASE WHEN pr.fecha_compromiso < CURDATE()
                      AND pr.estado NOT IN (\'listo\',\'completado\',\'entregado\')
                     THEN 1 ELSE 0 END                         AS esta_retrasado
            ')
            ->orderBy('pr.fecha_compromiso');

        if (!empty($args['estado'])) {
            $query->where('pr.estado', $args['estado']);
        }

        if (!empty($args['solo_retrasados'])) {
            $query->where('pr.estado', 'retrasado')
                  ->orWhereRaw('pr.fecha_compromiso < CURDATE() AND pr.estado NOT IN (\'listo\', \'completado\', \'entregado\')');
        }

        $items = $query->limit(50)->get();

        // Pasos activos por item
        if ($items->isNotEmpty()) {
            $ids = $items->pluck('id')->toArray();

            $pasoQuery = DB::table('produccion_pasos')
                ->whereIn('produccion_id', $ids)
                ->whereIn('estado', ['en_proceso', 'pendiente'])
                ->orderBy('orden');

            if (!empty($args['paso'])) {
                $pasoQuery->where('paso', 'like', "%{$args['paso']}%");
                // Filtrar items a solo los que tienen ese paso
                $produccionIds = $pasoQuery->pluck('produccion_id')->unique();
                $items = $items->whereIn('id', $produccionIds->toArray())->values();
            }

            $pasos = $pasoQuery->get()->groupBy('produccion_id');

            $items = $items->map(function ($item) use ($pasos) {
                $item->pasos_activos = $pasos->get($item->id, collect())->values();
                return $item;
            });
        }

        // Resumen de conteos (excluye solo los ya entregados)
        $resumen = DB::table('produccion')
            ->selectRaw('estado, COUNT(*) AS cantidad')
            ->whereNotIn('estado', ['entregado'])
            ->groupBy('estado')
            ->get();

        return [
            'items'   => $items,
            'resumen' => $resumen,
            'total'   => $items->count(),
        ];
    }

    private function handleConsultarOrdenes(array $args, Usuario $usuario): array
    {
        $limit = min((int) ($args['limit'] ?? 20), 50);

        // ── Resumen de conteos por estado (siempre se incluye) ─────────────────
        $resumenQ = DB::table('ordenes as o');
        // Resolver tienda por nombre si se proporcionó nombre_tienda
        $tiendaIdResuelto = $args['tienda_id'] ?? null;
        if (!$tiendaIdResuelto && !empty($args['nombre_tienda'])) {
            $tiendaIdResuelto = DB::table('tiendas')
                ->where('nombre', 'like', "%{$args['nombre_tienda']}%")
                ->value('id');
        }

        if ($usuario->rol === 'vendedor') {
            $resumenQ->where('o.vendedor_id', $usuario->id);
        } elseif ($tiendaIdResuelto) {
            $resumenQ->where('o.tienda_id', $tiendaIdResuelto);
        }
        if (!empty($args['periodo'])) {
            [$desdeR, $hastaR] = $this->parsePeriodo($args['periodo']);
            $resumenQ->whereBetween('o.created_at', [$desdeR . ' 00:00:00', $hastaR . ' 23:59:59']);
        }
        $resumenPorEstado = $resumenQ->selectRaw('estado, COUNT(*) AS cantidad')->groupBy('estado')->get();

        // Conteo rápido de órdenes en_camino
        $enCaminoQ = DB::table('ordenes')->where('estado', 'en_camino');
        if ($usuario->rol === 'vendedor') {
            $enCaminoQ->where('vendedor_id', $usuario->id);
        } elseif ($tiendaIdResuelto) {
            $enCaminoQ->where('tienda_id', $tiendaIdResuelto);
        }
        $totalEnCamino = $enCaminoQ->count();

        // ── Query principal de órdenes ─────────────────────────────────────────
        $query = DB::table('ordenes as o')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->join('tiendas as t',  't.id', '=', 'o.tienda_id')
            ->join('usuarios as u', 'u.id', '=', 'o.vendedor_id')
            ->leftJoin(
                DB::raw('(SELECT orden_id, SUM(monto) AS total_pagado FROM pagos GROUP BY orden_id) AS pg'),
                'pg.orden_id', '=', 'o.id'
            )
            ->selectRaw('
                o.id,
                o.estado,
                o.valor_total,
                o.canal,
                o.created_at                                                   AS fecha_creacion_orden,
                o.listo_entrega_at                                             AS fecha_quedo_lista,
                c.nombre  AS cliente,
                c.telefono,
                t.nombre  AS tienda,
                u.nombre  AS vendedor,
                COALESCE(pg.total_pagado, 0)                                   AS total_pagado,
                o.valor_total - COALESCE(pg.total_pagado, 0)                   AS saldo_pendiente,
                (
                    SELECT COALESCE(MIN(pr2.fecha_compromiso), MIN(oi2.fecha_entrega_prom))
                    FROM orden_items oi2
                    LEFT JOIN produccion pr2 ON pr2.orden_item_id = oi2.id
                    WHERE oi2.orden_id = o.id
                )                                                              AS fecha_entrega_prometida,
                (
                    SELECT DATEDIFF(
                        COALESCE(MIN(pr2.fecha_compromiso), MIN(oi2.fecha_entrega_prom)),
                        CURDATE()
                    )
                    FROM orden_items oi2
                    LEFT JOIN produccion pr2 ON pr2.orden_item_id = oi2.id
                    WHERE oi2.orden_id = o.id
                )                                                              AS dias_restantes_entrega
            ')
            ->orderByDesc('o.created_at');

        if ($usuario->rol === 'vendedor') {
            $query->where('o.vendedor_id', $usuario->id);
        } elseif ($tiendaIdResuelto) {
            $query->where('o.tienda_id', $tiendaIdResuelto);
        }

        if (!empty($args['estado'])) {
            $query->where('o.estado', $args['estado']);
        }

        if (!empty($args['cliente_nombre'])) {
            $query->where('c.nombre', 'like', "%{$args['cliente_nombre']}%");
        }

        if (!empty($args['nombre_producto'])) {
            $query->whereExists(function ($sub) use ($args) {
                $sub->select(DB::raw(1))
                    ->from('orden_items as oi2')
                    ->join('productos as p2', 'p2.id', '=', 'oi2.producto_id')
                    ->whereColumn('oi2.orden_id', 'o.id')
                    ->where('p2.nombre', 'like', "%{$args['nombre_producto']}%");
            });
        }

        if (!empty($args['solo_con_saldo'])) {
            $query->whereRaw('o.valor_total - COALESCE(pg.total_pagado, 0) > 0')
                  ->whereNotIn('o.estado', ['entregado', 'cancelado']);
        }

        if (!empty($args['periodo'])) {
            [$desde, $hasta] = $this->parsePeriodo($args['periodo']);
            $query->whereBetween('o.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
        }

        $ordenes = $query->limit($limit)->get();

        // Cargar productos de cada orden
        if ($ordenes->isNotEmpty()) {
            $ordenIds = $ordenes->pluck('id')->toArray();
            $items = DB::table('orden_items as oi')
                ->join('productos as p', 'p.id', '=', 'oi.producto_id')
                ->leftJoin('producto_variantes as pv', 'pv.id', '=', 'oi.variante_id')
                ->whereIn('oi.orden_id', $ordenIds)
                ->selectRaw('
                    oi.orden_id,
                    p.nombre AS producto,
                    p.categoria,
                    CONCAT_WS(" - ", pv.marca_tela, pv.nombre_color) AS variante,
                    oi.cantidad,
                    oi.precio_unitario,
                    oi.cantidad * oi.precio_unitario AS subtotal
                ')
                ->get()
                ->groupBy('orden_id');

            $ordenes = $ordenes->map(function ($orden) use ($items) {
                $orden->productos = $items->get($orden->id, collect())->values();
                return $orden;
            });
        }

        return [
            'nota'               => 'Estados: pendiente_anticipo → en_produccion → listo_entrega → en_camino → entregado (o cancelado). Cada orden incluye su lista de productos en el campo "productos".',
            'resumen_por_estado' => $resumenPorEstado,
            'total_en_camino'    => $totalEnCamino,
            'ordenes'            => $ordenes,
            'total_mostradas'    => $ordenes->count(),
        ];
    }

    private function handleConsultarTrabajadores(array $args, Usuario $usuario): array
    {
        $soloActivos = $args['solo_activos'] ?? true;

        // Resolver tienda
        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->where('nombre', 'like', "%{$args['nombre_tienda']}%")
                ->value('id');
        }
        if ($usuario->rol === 'vendedor') {
            $tiendaId = $usuario->tienda_default_id;
        }

        $base = DB::table('usuarios as u')
            ->leftJoin('tiendas as t', 't.id', '=', 'u.tienda_default_id');

        if ($soloActivos) {
            $base->where('u.activo', true);
        }
        if (!empty($args['rol'])) {
            $base->where('u.rol', $args['rol']);
        }
        if ($tiendaId) {
            $base->where('u.tienda_default_id', $tiendaId);
        }

        // Solo conteo
        if (!empty($args['solo_conteo'])) {
            $total = (clone $base)->count();
            $porRol = (clone $base)
                ->selectRaw('u.rol, COUNT(*) AS cantidad')
                ->groupBy('u.rol')
                ->orderBy('u.rol')
                ->get();

            return [
                'total_trabajadores' => $total,
                'por_rol'            => $porRol,
            ];
        }

        $trabajadores = $base
            ->selectRaw('u.id, u.nombre, u.email, u.rol, u.activo, t.nombre AS tienda')
            ->orderBy('u.rol')
            ->orderBy('u.nombre')
            ->get();

        // Conteo por rol para contexto
        $resumen = $trabajadores->groupBy('rol')->map(fn($g) => $g->count());

        return [
            'total'        => $trabajadores->count(),
            'por_rol'      => $resumen,
            'trabajadores' => $trabajadores,
        ];
    }

    private function handleConsultarClientes(array $args, Usuario $usuario): array
    {
        // Resolver tienda por nombre si aplica
        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->where('nombre', 'like', "%{$args['nombre_tienda']}%")
                ->value('id');
        }
        if ($usuario->rol === 'vendedor') {
            $tiendaId = $usuario->tienda_default_id;
        }

        // Solo conteo total
        if (!empty($args['solo_conteo'])) {
            $q = DB::table('clientes');
            if ($tiendaId) {
                $q->whereExists(function ($sub) use ($tiendaId) {
                    $sub->select(DB::raw(1))
                        ->from('ordenes')
                        ->whereColumn('ordenes.cliente_id', 'clientes.id')
                        ->where('ordenes.tienda_id', $tiendaId);
                });
            }
            return [
                'total_clientes' => $q->count(),
                'nota' => $tiendaId ? 'Clientes con al menos una orden en la tienda.' : 'Total de clientes registrados en el sistema.',
            ];
        }

        // Detalle de un cliente específico
        if (!empty($args['cliente_id'])) {
            $cliente = DB::table('clientes')->find($args['cliente_id']);
            if (!$cliente) {
                return ['error' => 'Cliente no encontrado.'];
            }

            $ordenes = DB::table('ordenes as o')
                ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
                ->where('o.cliente_id', $args['cliente_id'])
                ->selectRaw('o.id, o.estado, o.valor_total, o.created_at AS fecha, t.nombre AS tienda')
                ->orderByDesc('o.created_at')
                ->get();

            $pagos = DB::table('pagos')
                ->whereIn('orden_id', $ordenes->pluck('id'))
                ->sum('monto');

            return [
                'cliente'         => $cliente,
                'total_ordenes'   => $ordenes->count(),
                'total_comprado'  => (float) $ordenes->sum('valor_total'),
                'total_pagado'    => (float) $pagos,
                'ordenes'         => $ordenes,
            ];
        }

        // Listado con búsqueda y/o filtros
        $limit = min((int) ($args['limit'] ?? 20), 100);

        $query = DB::table('clientes as c')
            ->selectRaw('
                c.id, c.nombre, c.telefono, c.email, c.direccion,
                COUNT(DISTINCT o.id)          AS total_ordenes,
                COALESCE(SUM(o.valor_total), 0) AS total_comprado,
                MAX(o.created_at)             AS ultima_compra
            ')
            ->leftJoin('ordenes as o', function ($join) use ($tiendaId) {
                $join->on('o.cliente_id', '=', 'c.id')
                     ->whereNotIn('o.estado', ['cancelado']);
                if ($tiendaId) {
                    $join->where('o.tienda_id', $tiendaId);
                }
            })
            ->groupBy('c.id', 'c.nombre', 'c.telefono', 'c.email', 'c.direccion')
            ->orderByDesc('total_comprado');

        if (!empty($args['busqueda'])) {
            $b = $args['busqueda'];
            $query->where(function ($q) use ($b) {
                $q->where('c.nombre', 'like', "%{$b}%")
                  ->orWhere('c.telefono', 'like', "%{$b}%")
                  ->orWhere('c.email', 'like', "%{$b}%");
            });
        }

        if (!empty($args['periodo'])) {
            [$desde, $hasta] = $this->parsePeriodo($args['periodo']);
            $query->having('ultima_compra', '>=', $desde . ' 00:00:00');
        }

        $clientes  = $query->limit($limit)->get();
        $total     = DB::table('clientes')->count();

        return [
            'total_clientes_sistema' => $total,
            'clientes'               => $clientes,
            'total_mostrados'        => $clientes->count(),
            'nota'                   => 'total_comprado = suma de valor_total de órdenes no canceladas.',
        ];
    }

    private function handleListarTiendas(): array
    {
        $tiendas = DB::table('tiendas')
            ->where('activa', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'ciudad']);

        return ['tiendas' => $tiendas];
    }

    // ─── Dispatcher de tool calls ─────────────────────────────────────────────

    private function ejecutarTool(string $toolName, array $args, Usuario $usuario): array
    {
        return match ($toolName) {
            'obtener_ficha_tecnica'        => $this->handleObtenerFichaTecnica($args),
            'buscar_fichas_por_categoria'  => $this->handleBuscarFichasPorCategoria($args),
            'calcular_costo_personalizado' => $this->handleCalcularCostoPersonalizado($args),
            'consultar_inventario'         => $this->handleConsultarInventario($args, $usuario),
            'productos_mas_vendidos'       => $this->handleProductosMasVendidos($args, $usuario),
            'ventas_por_categoria'         => $this->handleVentasPorCategoria($args, $usuario),
            'ventas_producto_especifico'   => $this->handleVentasProductoEspecifico($args, $usuario),
            'clientes_top'                 => $this->handleClientesTop($args, $usuario),
            'estado_produccion'            => $this->handleEstadoProduccion($args),
            'consultar_ordenes'            => $this->handleConsultarOrdenes($args, $usuario),
            'consultar_trabajadores'       => $this->handleConsultarTrabajadores($args, $usuario),
            'consultar_clientes'           => $this->handleConsultarClientes($args, $usuario),
            'buscar_productos_catalogo'    => $this->handleBuscarProductosCatalogo($args),
            'reporte_ventas'               => $this->handleReporteVentas($args, $usuario),
            'reporte_vendedores'           => $this->handleReporteVendedores($args),
            'reporte_pendientes'           => $this->handleReportePendientes($args, $usuario),
            'reporte_retrasos'             => $this->handleReporteRetrasos(),
            'listar_tiendas'               => $this->handleListarTiendas(),
            default                        => ['error' => "Tool '{$toolName}' no reconocida."],
        };
    }

    // ─── Loop principal del agente ────────────────────────────────────────────

    public function chat(array $messages, Usuario $usuario): string
    {
        $tiendaInfo = $usuario->tienda_default_id
            ? DB::table('tiendas')->where('id', $usuario->tienda_default_id)->value('nombre')
            : 'todas las tiendas';

        $systemPrompt = <<<EOT
Eres el asistente de negocios de Decasa, una empresa colombiana de muebles. Respondes siempre en español, de forma clara y concisa.

Usuario actual: {$usuario->nombre} (rol: {$usuario->rol}, tienda: {$tiendaInfo})
Fecha de hoy: {$this->hoy()}

## Estados de órdenes — mapeo de frases a valores exactos de BD:
| Frase del usuario                                          | Estado a usar         |
|------------------------------------------------------------|-----------------------|
| "listas para entregar", "lista entrega", "listo para despacho", "listos para despachar" | listo_entrega |
| "en camino", "con el conductor", "en ruta", "salió a entregar" | en_camino      |
| "en producción", "fabricando", "en fábrica", "en taller"  | en_produccion         |
| "pendiente de anticipo", "esperando pago", "sin anticipo"  | pendiente_anticipo    |
| "entregado", "completado", "finalizado"                    | entregado             |
| "cancelado", "anulado"                                     | cancelado             |

## Reglas de tiendas:
- La tienda del perfil del usuario es solo información de contexto. NO la uses como filtro automático.
- Solo filtra por tienda cuando el usuario lo pida explícitamente (ej: "en Bolívar", "de la tienda Jardines").
- Cuando el usuario mencione una tienda, usa nombre_tienda con el nombre parcial. NUNCA inventes un tienda_id.
- Si no mencionó tienda, consulta todas las tiendas sin filtro.
- Si necesitas saber los nombres exactos de tiendas, llama primero a listar_tiendas.

## Fechas — significado exacto de cada campo:
| Campo                   | Fuente                  | Significado                                              |
|-------------------------|-------------------------|----------------------------------------------------------|
| `fecha_creacion_orden`  | consultar_ordenes       | Cuándo SE HIZO la orden (`ordenes.created_at`). NO es entrega. |
| `fecha_inicio`          | estado_produccion       | Cuándo EMPEZÓ a fabricarse el item. NO es entrega.       |
| `fecha_entrega_prometida` | ambas herramientas    | Fecha comprometida para ENTREGAR al cliente. ESTA es la fecha de entrega. |
| `dias_restantes_entrega`| ambas herramientas      | Días que faltan (negativo = vencido, positivo = faltan N días). |
| `fecha_quedo_lista`     | consultar_ordenes       | Cuando la orden quedó lista para despachar. NO es entrega al cliente. |

REGLAS CRÍTICAS de fechas:
- "¿cuándo se entrega?" / "¿cuántos días le quedan?" → usa `fecha_entrega_prometida` y `dias_restantes_entrega`.
- "¿cuándo se hizo la orden?" / "¿cuándo fue creada?" → para ÓRDENES usa `fecha_creacion_orden`; para items de PRODUCCIÓN usa `fecha_inicio`.
- NUNCA uses `fecha_creacion_orden` ni `fecha_inicio` para responder sobre fechas de entrega.
- NUNCA inventes una fecha. Si no tienes el dato, llama la herramienta correspondiente.

## REGLA CRÍTICA sobre categorías:
Cuando el usuario diga el nombre de una categoría (ej: "sillas auxiliares", "sofás", "comedores"), usa SIEMPRE el parámetro `categoria` con el valor exacto de la tabla de abajo. NUNCA lo pongas en `nombre_producto`.
Ejemplo: "cuántas sillas auxiliares se han vendido" → `categoria: "sillas_aux"` (NO `nombre_producto: "sillas auxiliares"`).
Usa `nombre_producto` solo cuando el usuario nombre un modelo específico (ej: "SILLA AUX ALICIA", "BASE 2K").

## Categorías de productos — valores EXACTOS en BD (usar siempre estos al llamar herramientas):
| Lo que dice el usuario                                              | Valor en BD       |
|---------------------------------------------------------------------|-------------------|
| sillas auxiliares, sillas aux, silla aux, sillas auxiliar           | sillas_aux        |
| sillas de barra, silla barra, sillas bar, taburetes                 | sillas_barra      |
| sillas de comedor, sillas comedor, silla comedor                    | sillas_comedor    |
| sofás, sofas, sofa, sillón, sillon                                  | sofas             |
| sofacamas, sofa cama, sofa-cama, sofá cama, cama sofá               | sofa_camas        |
| sofás modulares, sofa modular, sofas modulares, modular             | sofas_modulares   |
| camas, cama, cabecero                                               | camas             |
| colchones, colchon, colchoncillo                                    | colchones         |
| comedores, comedor, juego de comedor, sala de comedor               | comedores         |
| escritorios, escritorio, escritorio de oficina, mesa de oficina     | escritorios       |
| mesas de noche, mesa noche, mesita de noche, nochero, velador       | mesas_noche       |
| mesas auxiliares, mesa aux, mesa auxiliar, mesita auxiliar          | mesas_aux         |
| mesas de centro, mesa centro, mesa de sala, mesa central            | mesas_centro      |
| mesas de tv, mesa tv, mesa de tv, mesa de television, mesa de televisor, mueble tv, rack tv, mesa para televisión | mesas_tv |
| mesas de noche, mesa de noche, mesa noche, mesita de noche, nochero, velador | mesas_noche |
| sillas de barra, silla de barra, silla barra, sillas bar, taburetes | sillas_barra      |
| cajoneros, cajonero, gavetas, cómoda, comoda                        | cajoneros         |

## Cuándo usar cada herramienta de producción:
- `estado_produccion` → cuando el usuario pregunte sobre el taller/fábrica: "hay productos en producción", "qué se está fabricando", "items retrasados", "qué está listo en producción". Consulta la tabla `produccion` con estados: pendiente, en_proceso, listo, retrasado, entregado.
- `consultar_ordenes` → cuando el usuario pregunte sobre ÓRDENES de clientes: "hay órdenes pendientes", "órdenes en camino", "órdenes listas para entregar". NO uses consultar_ordenes para saber qué se fabrica en el taller.
- IMPORTANTE: una orden puede estar en `listo_entrega` (estado de la orden = lista para despachar) mientras su registro de producción sigue activo con `estado='listo'`. Son tablas distintas.

## Reglas generales:
- Para costos, siempre muestra el desglose (materiales + mano de obra).
- Para cantidades de dinero, usa formato colombiano ($ 1.200.000).
- No inventes datos. Si no encuentras algo, dilo claramente y sugiere cómo reformular.
- Cuando una orden tiene productos, inclúyelos en tu respuesta.
EOT;

        $apiMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        );

        // Loop de tool calling (máx. 5 iteraciones para evitar loops infinitos)
        for ($i = 0; $i < 5; $i++) {
            $response = OpenAI::chat()->create([
                'model'       => config('openai.model', 'gpt-4o-mini'),
                'messages'    => $apiMessages,
                'tools'       => $this->toolsDefinition(),
                'tool_choice' => 'auto',
            ]);

            $choice  = $response->choices[0];
            $message = $choice->message;

            // Si no hay tool call, retornar la respuesta final
            if ($choice->finishReason === 'stop' || empty($message->toolCalls)) {
                return $message->content ?? '';
            }

            // Agregar mensaje del asistente con tool calls al historial
            $apiMessages[] = $message->toArray();

            // Ejecutar cada tool call y agregar resultados
            foreach ($message->toolCalls as $toolCall) {
                $toolName = $toolCall->function->name;
                $toolArgs = json_decode($toolCall->function->arguments, true) ?? [];
                $result   = $this->ejecutarTool($toolName, $toolArgs, $usuario);

                $apiMessages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content'      => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return 'Lo siento, no pude completar la consulta. Por favor intenta con una pregunta más específica.';
    }

    private function hoy(): string
    {
        return Carbon::now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    }
}
