<?php

namespace App\Services;

use App\Models\Usuario;
use App\Services\Costos\BomBuilder;
use App\Services\Costos\CostoCalculator;
use App\Services\Costos\FewShotProvider;
use App\Services\Costos\FichaRetriever;
use App\Services\Costos\SanityChecker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;

class AgentService
{
    public function __construct(
        private BomBuilder $bomBuilder,
        private CostoCalculator $calculator,
        private FichaRetriever $retriever,
        private SanityChecker $sanity,
        private FewShotProvider $fewShot,
    ) {}

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
                    'name' => 'cotizar_fabricacion',
                    'description' => 'Calcula el COSTO DE FABRICACIÓN de un mueble personalizado (que no existe tal cual en el catálogo) usando el motor de cotización oficial: arma la receta de materiales y mano de obra y calcula el costo con precios reales de la base de datos. Devuelve un desglose exacto que SIEMPRE cuadra, más un precio de venta sugerido. Si el estimado no es confiable, devuelve requiere_revision=true. Úsala para "¿cuánto vale fabricar X?", "¿cuánto costaría una cama con escritorio?", muebles con medidas o descripciones personalizadas. Llámala de inmediato con lo que el usuario describa — si faltan medidas, ella usa estimados típicos. NO recalcules ni inventes números: presenta EXACTAMENTE los que devuelve.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'descripcion_producto' => ['type' => 'string', 'description' => 'Descripción del mueble a fabricar, con todo lo que el usuario haya dicho (materiales, características, estilo)'],
                            'categoria'            => ['type' => 'string', 'description' => 'Categoría: comedor, sala, alcoba, cama, sofa, escritorio, etc.'],
                            'num_puestos'          => ['type' => 'integer', 'description' => 'Número de puestos o módulos (opcional)'],
                            'largo_cm'             => ['type' => 'number', 'description' => 'Largo en centímetros (opcional)'],
                            'ancho_cm'             => ['type' => 'number', 'description' => 'Ancho en centímetros (opcional)'],
                            'alto_cm'              => ['type' => 'number', 'description' => 'Alto en centímetros (opcional)'],
                            'materiales_cliente'   => ['type' => 'string', 'description' => 'Materiales o requisitos específicos que el cliente quiere (opcional)'],
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_variantes_producto',
                    'description' => 'Consulta las especificaciones (marca de tela, tipo de tela, color) disponibles de un producto y su stock actual por tienda. Úsala cuando el usuario pregunte por colores, telas, marcas o combinaciones disponibles de un producto específico (ej: "¿qué colores hay del sofá Alicia en Jardines?", "¿qué telas tiene el Sofá Roma disponibles?"). Si el producto no tiene variantes (comedores, mesas, camas sin tela), el tool lo indica claramente.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre_producto' => ['type' => 'string', 'description' => 'Nombre o parte del nombre del producto'],
                            'tienda_id'       => ['type' => 'integer', 'description' => 'ID de tienda específica (opcional)'],
                            'nombre_tienda'   => ['type' => 'string',  'description' => 'Nombre parcial de la tienda (opcional, alternativa a tienda_id)'],
                            'solo_con_stock'  => ['type' => 'boolean', 'description' => 'Solo mostrar variantes con stock disponible > 0 (default: true)'],
                            'marca'           => ['type' => 'string',  'description' => 'Filtrar por marca de tela (opcional)'],
                            'tela'            => ['type' => 'string',  'description' => 'Filtrar por tipo de tela (opcional)'],
                            'color'           => ['type' => 'string',  'description' => 'Filtrar por color (opcional)'],
                        ],
                        'required' => ['nombre_producto'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'analizar_rotacion_inventario',
                    'description' => 'Análisis predictivo que cruza velocidad de ventas con stock actual para recomendar qué productos fabricar, para qué tiendas, y cuáles descontinuar. Úsala ante preguntas como: ¿qué deberíamos fabricar?, ¿qué productos están sin salida?, ¿dónde hay falta de stock?, ¿qué deberíamos dejar de producir?',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'dias'          => ['type' => 'integer', 'description' => 'Período de análisis en días (default 90). Usa 30 para tendencia reciente, 180 para largo plazo.'],
                            'tienda_id'     => ['type' => 'integer', 'description' => 'Filtrar por tienda (opcional)'],
                            'nombre_tienda' => ['type' => 'string',  'description' => 'Nombre parcial de la tienda (opcional)'],
                            'categoria'     => ['type' => 'string',  'description' => 'Filtrar por categoría de producto (opcional)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_telas',
                    'description' => 'Consulta el inventario de telas disponibles en bodega: metros disponibles, metros reservados y metros libres. Úsala ante preguntas como "¿qué telas hay?", "¿cuántos metros de [tela/color] hay?", "¿hay [marca] en [color]?", "telas con stock disponible", "proveedores de telas". Devuelve lista con marca, tipo, color, metros_disponibles, metros_reservados y metros_libres.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'busqueda'       => ['type' => 'string', 'description' => 'Búsqueda libre por marca, tipo, color, referencia o textura'],
                            'marca'          => ['type' => 'string', 'description' => 'Filtrar por proveedor/marca específica (búsqueda parcial)'],
                            'tipo'           => ['type' => 'string', 'description' => 'Filtrar por tipo de tela (velvet, cuero, lino, etc.)'],
                            'color'          => ['type' => 'string', 'description' => 'Filtrar por color específico'],
                            'solo_con_metros'=> ['type' => 'boolean', 'description' => 'Solo mostrar telas con metros_libres > 0 (default: false)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_caja',
                    'description' => 'Consulta el balance de caja de una tienda o de todas las tiendas: ingresos por ventas, ingresos manuales, egresos y balance neto. También puede mostrar los movimientos recientes. Úsala ante preguntas como "¿cuánto hay en caja?", "¿cuánto dinero tiene la tienda X?", "¿qué movimientos hubo?", "resumen de caja de todas las tiendas", "egresos del mes".',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre_tienda'   => ['type' => 'string', 'description' => 'Nombre parcial de la tienda a consultar (búsqueda por LIKE)'],
                            'tienda_id'       => ['type' => 'integer', 'description' => 'ID numérico de la tienda (solo si se conoce con certeza)'],
                            'todas'           => ['type' => 'boolean', 'description' => 'Mostrar resumen de caja de TODAS las tiendas activas'],
                            'con_movimientos' => ['type' => 'boolean', 'description' => 'Incluir listado de movimientos recientes de la tienda'],
                            'periodo'         => ['type' => 'string', 'description' => 'Filtrar movimientos por período: hoy, semana, mes, mes_anterior, anio (solo aplica con con_movimientos=true)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_interesados',
                    'description' => 'Consulta estadísticas de clientes interesados (leads): cuántos hay, qué categorías preguntan más, distribución por tienda y por canal. Úsala ante preguntas como "¿qué es lo que más preguntan?", "¿cuántos interesados tenemos?", "¿qué se pregunta más en tienda X?", "¿qué deberíamos fabricar según la demanda?".',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'periodo'       => ['type' => 'string', 'description' => 'Período para contar nuevos interesados: hoy, semana, mes, mes_anterior, anio (opcional)'],
                            'tienda_id'     => ['type' => 'integer', 'description' => 'Filtrar por tienda específica (opcional)'],
                            'nombre_tienda' => ['type' => 'string',  'description' => 'Nombre parcial de tienda (alternativa a tienda_id)'],
                        ],
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

    // ─── Helper: patrón LIKE insensible a mayúsculas ─────────────────────────

    private function likeI(string $value): string
    {
        return '%' . mb_strtolower(trim($value)) . '%';
    }

    // ─── Handlers de cada tool ────────────────────────────────────────────────

    private function handleObtenerFichaTecnica(array $args): array
    {
        $nombre = mb_strtolower(trim($args['nombre_producto']));

        $ficha = DB::table('fichas_tecnicas')
            ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($nombre)])
            ->orderByRaw('CHAR_LENGTH(nombre) ASC')
            ->first();

        if (!$ficha) {
            // Búsqueda por palabras individuales con score por rareza:
            // palabras que coinciden en MENOS fichas son más distintivas (nombre de modelo)
            // y aportan mayor peso al ranking, desplazando palabras genéricas como "modular", "sofa"
            $palabras = collect(preg_split('/\s+/', $nombre))
                ->map(fn($p) => trim($p))
                ->filter(fn($p) => mb_strlen($p) >= 4)
                ->values();

            $acumulado = collect();
            foreach ($palabras as $palabra) {
                $count = DB::table('fichas_tecnicas')
                    ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($palabra)])
                    ->count();
                if ($count === 0) continue;
                // peso = 100/count × (longitud/4): palabras raras y largas valen más
                $peso       = round((100 / $count) * (mb_strlen($palabra) / 4), 4);
                $resultados = DB::table('fichas_tecnicas')
                    ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($palabra)])
                    ->get(['id', 'nombre', 'categoria', 'costo_total'])
                    ->each(fn($r) => $r->_peso = $peso);
                $acumulado  = $acumulado->concat($resultados);
            }

            // Deduplicar y rankear por suma de pesos (modelos únicos como "Telavid" superan genéricos)
            $fichas = $acumulado
                ->groupBy('id')
                ->map(fn($grupo) => (object) array_merge(
                    (array) $grupo->first(),
                    ['_score' => $grupo->sum('_peso')]
                ))
                ->sortByDesc('_score')
                ->take(5)
                ->values();

            if ($fichas->isEmpty()) {
                return [
                    'encontrado'  => false,
                    'sugerencias' => [],
                    'mensaje'     => "No se encontró ninguna ficha técnica para '{$nombre}'.",
                ];
            }

            return [
                'encontrado'  => false,
                'sugerencias' => $fichas,
                'mensaje'     => "No se encontró exactamente '{$nombre}'. Productos similares en catálogo:",
            ];
        }

        $items = DB::table('ficha_tecnica_items')
            ->where('ficha_tecnica_id', $ficha->id)
            ->orderBy('orden')
            ->get();

        // Calcular costos por sección (cada sección puede ser una variante del producto)
        $secciones = $items->groupBy('seccion');
        $variantes = $secciones->map(function ($secItems, $secNombre) {
            $mat = $secItems->where('es_mano_obra', false)->sum('subtotal');
            $mo  = $secItems->where('es_mano_obra', true)->sum('subtotal');
            return [
                'nombre'           => $secNombre ?: 'General',
                'costo_materiales' => round($mat, 0),
                'costo_mano_obra'  => round($mo, 0),
                'costo_total'      => round($mat + $mo, 0),
            ];
        })->values();

        $multiVariante = $variantes->count() > 1;

        // Si hay múltiples variantes, intentar identificar cuál coincide con la búsqueda
        $variantePrincipal = null;
        if ($multiVariante) {
            $variantePrincipal = $variantes->first(
                fn($v) => stripos($v['nombre'], $nombre) !== false || stripos($nombre, $v['nombre']) !== false
            );
        }

        $resultado = [
            'encontrado'        => true,
            'ficha'             => $ficha,
            'items'             => $items,
            'multi_variante'    => $multiVariante,
            'variantes'         => $multiVariante ? $variantes : null,
            'costo_materiales'  => $variantePrincipal ? $variantePrincipal['costo_materiales'] : $ficha->costo_materiales,
            'costo_mano_obra'   => $variantePrincipal ? $variantePrincipal['costo_mano_obra']  : $ficha->costo_mano_obra,
            'costo_total'       => $variantePrincipal ? $variantePrincipal['costo_total']       : $ficha->costo_total,
            'nota'              => $multiVariante
                ? 'Esta ficha contiene ' . $variantes->count() . ' variantes. Los costos mostrados corresponden a: ' . ($variantePrincipal ? $variantePrincipal['nombre'] : 'todas las variantes combinadas') . '.'
                : null,
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
            $query->whereRaw('LOWER(categoria) LIKE ?', [$this->likeI($args['categoria'])]);
        }
        if (!empty($args['busqueda'])) {
            $query->where(function ($q) use ($args) {
                $q->whereRaw('LOWER(nombre) LIKE ?',    [$this->likeI($args['busqueda'])])
                  ->orWhereRaw('LOWER(categoria) LIKE ?', [$this->likeI($args['busqueda'])]);
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
        $descripcion = $args['descripcion_producto'];
        $categoria   = $args['categoria'];
        $numPuestos  = $args['num_puestos'] ?? null;
        $largoCm     = $args['largo_cm'] ?? null;
        $anchoCm     = $args['ancho_cm'] ?? null;
        $altoCm      = $args['alto_cm']  ?? null;
        $matCliente  = $args['materiales_cliente'] ?? null;

        // Fichas de referencia por similitud (descompone híbridos en sus componentes)
        $fichasRef = $this->fichasReferenciaPorContexto($descripcion, $categoria, 5, [
            'largo' => $largoCm,
            'ancho' => $anchoCm,
            'alto'  => $altoCm,
        ]);

        // Tarifas de mano de obra con tarifa_hora del incentivo
        $tarifas = DB::table('tarifas_proceso as tp')
            ->leftJoin('salarios_cargo as sc', 'sc.cargo', '=', 'tp.cargo')
            ->orderBy('tp.proceso')
            ->get(['tp.id', 'tp.proceso', 'tp.descripcion', 'tp.unidad', 'tp.cargo',
                   'tp.dias_por_unidad', 'tp.tarifa', 'sc.tarifa_hora']);

        if ($fichasRef->isEmpty()) {
            $materiales = $this->materialesRelevantes($descripcion . ' ' . $categoria, collect());
            return [
                'encontrado'         => false,
                'descripcion'        => $descripcion,
                'categoria'          => $categoria,
                'mensaje'            => "No hay fichas de referencia para '{$categoria}'. Usa tarifas_proceso y materiales_catalogo para construir el estimado.",
                'tarifas_proceso'    => $tarifas,
                'materiales_catalogo'=> $materiales,
            ];
        }

        // Items completos de las fichas de referencia
        $itemsRef = DB::table('ficha_tecnica_items')
            ->whereIn('ficha_tecnica_id', $fichasRef->pluck('id'))
            ->orderBy('ficha_tecnica_id')
            ->orderBy('orden')
            ->get(['ficha_tecnica_id', 'seccion', 'descripcion', 'cantidad', 'unidad',
                   'precio_unitario', 'subtotal', 'es_mano_obra']);

        // Factor de escala por número de puestos
        $factor = 1;
        if ($numPuestos) {
            preg_match('/(\d+)\s*[Pp]/', $fichasRef->first()->nombre ?? '', $m);
            $puestosRef = isset($m[1]) ? (int) $m[1] : 4;
            $factor     = max(0.5, $numPuestos / $puestosRef);
        }

        $materiales = $this->materialesRelevantes($descripcion . ' ' . $categoria, $itemsRef);

        return [
            'encontrado'          => true,
            'descripcion'         => $descripcion,
            'categoria'           => $categoria,
            'puestos_solicitados' => $numPuestos,
            'medidas_cm'          => ['largo' => $largoCm, 'ancho' => $anchoCm, 'alto' => $altoCm],
            'materiales_cliente'  => $matCliente,
            'fichas_referencia'   => $fichasRef,
            'items_referencia'    => $itemsRef,
            'tarifas_proceso'     => $tarifas,
            'materiales_catalogo' => $materiales,
            'factor_escala'       => $factor,
            'nota'                => 'Usa items_referencia como base para listar materiales y mano de obra del producto similar. Ajusta cantidades según medidas si se dieron. Presenta el desglose completo.',
        ];
    }

    private function handleCalcularCostoPorMedidas(array $args): array
    {
        $tipo      = $args['tipo_producto'];
        $categoria = $args['categoria'] ?? $tipo;

        // Salarios por cargo para mostrar la fórmula al usuario
        $salarios = DB::table('salarios_cargo')
            ->get(['cargo', 'descripcion', 'salario_mensual', 'dias_laborales_mes'])
            ->map(fn($s) => array_merge((array) $s, [
                'tarifa_diaria' => round($s->salario_mensual / $s->dias_laborales_mes, 0),
            ]));

        // Tarifas de mano de obra: tarifa = salario_diario × dias_por_unidad
        $tarifas = DB::table('tarifas_proceso')
            ->orderBy('aplica_a')
            ->orderBy('proceso')
            ->get(['proceso', 'descripcion', 'unidad', 'tarifa', 'aplica_a', 'cargo', 'dias_por_unidad']);

        // Fichas de referencia por similitud (descompone híbridos en sus componentes)
        $fichasRef = $this->fichasReferenciaPorContexto(
            trim($tipo . ' ' . ($args['descripcion'] ?? '')),
            $categoria,
            5,
            [
                'largo' => $args['largo_cm'] ?? null,
                'ancho' => $args['ancho_cm'] ?? null,
                'alto'  => $args['alto_cm']  ?? null,
            ],
        );

        // Items detallados de las fichas de referencia
        $itemsRef = collect();
        if ($fichasRef->isNotEmpty()) {
            $itemsRef = DB::table('ficha_tecnica_items')
                ->whereIn('ficha_tecnica_id', $fichasRef->pluck('id'))
                ->orderBy('ficha_tecnica_id')
                ->orderBy('orden')
                ->get(['ficha_tecnica_id', 'seccion', 'descripcion', 'cantidad', 'unidad', 'precio_unitario', 'subtotal', 'es_mano_obra']);
        }

        // Materiales relevantes: primero los que aparecen en las fichas similares (precios exactos),
        // luego los que coincidan por keyword del tipo/categoría, completando hasta ~80 entradas
        $materiales = $this->materialesRelevantes($tipo . ' ' . $categoria, $itemsRef);

        return [
            'tipo_producto'       => $tipo,
            'descripcion_usuario' => $args['descripcion'] ?? '',
            'medidas_cm'          => [
                'largo' => $args['largo_cm'] ?? null,
                'ancho' => $args['ancho_cm'] ?? null,
                'alto'  => $args['alto_cm']  ?? null,
            ],
            'num_puestos'         => $args['num_puestos'] ?? null,
            'salarios_cargo'      => $salarios,   // tarifa_diaria = salario_mensual / dias_laborales_mes
            'tarifas_proceso'     => $tarifas,    // tarifa = tarifa_diaria × dias_por_unidad
            'materiales_catalogo' => $materiales,
            'fichas_referencia'   => $fichasRef,
            'items_referencia'    => $itemsRef,
            'nota_calculo'        => 'Mano de obra = dias_por_unidad × (salario_mensual / dias_laborales_mes). Muestra esta fórmula en tu respuesta para que el cálculo sea transparente.',
        ];
    }

    /**
     * Devuelve los materiales más relevantes del catálogo para un producto dado.
     * Prioriza materiales que aparecen en fichas similares (precios reales),
     * luego los que coincidan por keyword, y completa con una muestra general.
     */
    private function materialesRelevantes(string $contexto, $itemsRef): \Illuminate\Support\Collection
    {
        // 1. Nombres de materiales que ya usan las fichas de referencia (sin mano de obra)
        $nombresEnFichas = $itemsRef
            ->where('es_mano_obra', false)
            ->map(fn($i) => mb_strtolower(trim($i->descripcion)))
            ->unique()
            ->values();

        // 2. Keywords del tipo/categoría del mueble (palabras ≥4 chars)
        $keywords = collect(preg_split('/\s+/', mb_strtolower($contexto)))
            ->filter(fn($k) => mb_strlen($k) >= 4)
            ->unique()
            ->values();

        // Consulta combinada: coincide con nombres de fichas O keywords del producto
        $query = DB::table('materiales');
        if ($nombresEnFichas->isNotEmpty() || $keywords->isNotEmpty()) {
            $query->where(function ($q) use ($nombresEnFichas, $keywords) {
                foreach ($nombresEnFichas as $n) {
                    // Match por las primeras palabras del nombre del material en la ficha
                    $primeraPalabra = explode(' ', $n)[0];
                    if (mb_strlen($primeraPalabra) >= 4) {
                        $q->orWhereRaw('LOWER(nombre) LIKE ?', ['%' . $primeraPalabra . '%']);
                    }
                }
                foreach ($keywords as $kw) {
                    $q->orWhereRaw('LOWER(nombre) LIKE ?', ['%' . $kw . '%']);
                }
            });
        }

        $relevantes = $query->orderBy('nombre')->limit(60)->get(['nombre', 'unidad', 'precio_unitario']);

        // 3. Si hay poca variedad (< 20), completar con materiales generales de relleno
        if ($relevantes->count() < 20) {
            $yaIncluidos = $relevantes->pluck('nombre');
            $generales   = DB::table('materiales')
                ->whereNotIn('nombre', $yaIncluidos)
                ->orderBy('nombre')
                ->limit(40)
                ->get(['nombre', 'unidad', 'precio_unitario']);
            return $relevantes->concat($generales);
        }

        return $relevantes;
    }

    /**
     * Fichas técnicas de referencia para un mueble descrito en texto libre.
     *
     * Delega en FichaRetriever (similitud semántica). La versión anterior hacía LIKE palabra
     * por palabra y de cada match tomaba la ficha MÁS BARATA (`orderBy('costo_total')`), lo que
     * anclaba sistemáticamente los estimados por debajo del costo real. Ver AGENT.md, Fase 3.
     */
    private function fichasReferenciaPorContexto(
        string $texto,
        string $categoria,
        int $max = 5,
        array $medidas = [],
    ): \Illuminate\Support\Collection {
        return $this->retriever->buscarSimilares($texto, $categoria, $medidas, $max);
    }

    private function handleConsultarInventario(array $args, Usuario $usuario): array
    {
        // Resolver tienda por nombre si se proporcionó
        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
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

        if ($tiendaId) {
            $query->where('t.id', $tiendaId);
        }

        if (!empty($args['producto'])) {
            $query->whereRaw('LOWER(p.nombre) LIKE ?', [$this->likeI($args['producto'])]);
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
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw('p.id, COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS nombre, COALESCE(p.categoria, oi.categoria_custom, "personalizado") AS categoria, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total')
            ->groupBy('p.id', DB::raw('COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado")'), DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'))
            ->orderByDesc($criterio === 'cantidad' ? 'cantidad' : 'valor_total')
            ->limit($topN);

        if ($usuario->rol === 'vendedor') {
            $query->where('o.tienda_id', $usuario->tienda_default_id);
        } elseif (!empty($args['tienda_id'])) {
            $query->where('o.tienda_id', $args['tienda_id']);
        }

        if (!empty($args['categoria'])) {
            $cat = $this->detectarCategoria($args['categoria']) ?? $args['categoria'];
            $query->whereRaw('LOWER(p.categoria) LIKE ?', [$this->likeI($cat)]);
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
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->whereBetween('o.created_at', $rango)
            ->whereNotIn('o.estado', ['cancelado'])
            ->selectRaw('COALESCE(p.categoria, oi.categoria_custom, "personalizado") AS categoria, SUM(oi.cantidad) AS cantidad, SUM(oi.cantidad * oi.precio_unitario) AS valor_total, COUNT(DISTINCT p.id) AS num_productos')
            ->groupBy(DB::raw('COALESCE(p.categoria, oi.categoria_custom, "personalizado")'))
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
            $query->whereRaw('LOWER(categoria) LIKE ?', [$this->likeI($cat)]);
        }

        if (!empty($args['busqueda'])) {
            $palabras = array_filter(explode(' ', trim($args['busqueda'])));
            foreach ($palabras as $p) {
                if (strlen($p) >= 3) $query->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($p)]);
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
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
                ->value('id');
        }
        if ($usuario->rol === 'vendedor') {
            $tiendaId = $usuario->tienda_default_id;
        }

        $baseQuery = DB::table('orden_items as oi')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->whereNotIn('o.estado', ['cancelado']);

        // Filtro por categoría
        if (!empty($args['categoria'])) {
            $baseQuery->whereRaw('LOWER(p.categoria) LIKE ?', [$this->likeI($args['categoria'])]);
        }

        // Búsqueda flexible por nombre: cada palabra >= 3 letras debe estar presente
        if ($nombre) {
            $palabras = array_filter(explode(' ', trim($nombre)));
            foreach ($palabras as $palabra) {
                if (strlen($palabra) >= 3) {
                    $baseQuery->whereRaw('LOWER(p.nombre) LIKE ?', [$this->likeI($palabra)]);
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
                    ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($primPalabra)])
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
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
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

    private function handleReporteVendedores(array $args, Usuario $usuario): array
    {
        if ($usuario->rol === 'vendedor') {
            return ['error' => 'No tienes acceso al rendimiento comparativo de vendedores.'];
        }

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
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
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
            ->selectRaw(<<<'SQL'
                o.id AS orden_id, o.estado, o.valor_total, o.created_at AS fecha,
                c.nombre AS cliente,
                CONCAT('***', RIGHT(REPLACE(REPLACE(REPLACE(c.telefono,' ',''),'-',''),'+',''), 4)) AS telefono,
                u.nombre AS vendedor, t.nombre AS tienda,
                COALESCE(SUM(pg.monto), 0) AS total_pagado,
                o.valor_total - COALESCE(SUM(pg.monto), 0) AS saldo_pendiente
            SQL)
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

    private function handleReporteRetrasos(Usuario $usuario): array
    {
        $items = DB::table('produccion as pr')
            ->join('orden_items as oi', 'oi.id', '=', 'pr.orden_item_id')
            ->join('ordenes as o',      'o.id',  '=', 'oi.orden_id')
            ->join('clientes as c',     'c.id',  '=', 'o.cliente_id')
            ->leftJoin('productos as pd', 'pd.id', '=', 'oi.producto_id')
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
                COALESCE(pd.nombre, oi.nombre_custom, "Producto personalizado") AS producto, oi.cantidad,
                pr.fecha_compromiso,
                DATEDIFF(CURDATE(), pr.fecha_compromiso) AS dias_retraso,
                pr.estado, pr.motivo_retraso,
                u.nombre AS vendedor, t.nombre AS tienda
            ')
            ->when($usuario->rol === 'vendedor' && $usuario->tienda_default_id,
                   fn($q) => $q->where('o.tienda_id', $usuario->tienda_default_id))
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
            ->selectRaw("c.id, c.nombre, CONCAT('***', RIGHT(REPLACE(REPLACE(REPLACE(c.telefono,' ',''),'-',''),'+',''), 4)) AS telefono, SUM(p.monto) AS total_pagado, COUNT(DISTINCT o.id) AS num_ordenes")
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

    private function handleEstadoProduccion(array $args, Usuario $usuario): array
    {
        $query = DB::table('produccion as pr')
            ->join('orden_items as oi', 'oi.id', '=', 'pr.orden_item_id')
            ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
            ->join('ordenes as o', 'o.id', '=', 'oi.orden_id')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->join('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->whereNotIn('pr.estado', ['entregado'])
            ->selectRaw('
                pr.id, pr.estado, pr.fecha_inicio,
                pr.fecha_compromiso                            AS fecha_entrega_prometida,
                COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS producto, oi.cantidad,
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

        if ($usuario->rol === 'vendedor' && $usuario->tienda_default_id) {
            $query->where('o.tienda_id', $usuario->tienda_default_id);
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
                $pasoQuery->whereRaw('LOWER(paso) LIKE ?', [$this->likeI($args['paso'])]);
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
        if ($usuario->rol === 'vendedor' && $usuario->tienda_default_id) {
            $resumen = DB::table('produccion as pr')
                ->join('orden_items as oi2', 'oi2.id', '=', 'pr.orden_item_id')
                ->join('ordenes as o2',      'o2.id',  '=', 'oi2.orden_id')
                ->where('o2.tienda_id', $usuario->tienda_default_id)
                ->selectRaw('pr.estado AS estado, COUNT(*) AS cantidad')
                ->whereNotIn('pr.estado', ['entregado'])
                ->groupBy('pr.estado')
                ->get();
        } else {
            $resumen = DB::table('produccion')
                ->selectRaw('estado, COUNT(*) AS cantidad')
                ->whereNotIn('estado', ['entregado'])
                ->groupBy('estado')
                ->get();
        }

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
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
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
            ->selectRaw(<<<'SQL'
                o.id,
                o.estado,
                o.valor_total,
                o.canal,
                o.created_at                                                   AS fecha_creacion_orden,
                o.listo_entrega_at                                             AS fecha_quedo_lista,
                c.nombre  AS cliente,
                CONCAT('***', RIGHT(REPLACE(REPLACE(REPLACE(c.telefono,' ',''),'-',''),'+',''), 4)) AS telefono,
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
            SQL)
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
            $query->whereRaw('LOWER(c.nombre) LIKE ?', [$this->likeI($args['cliente_nombre'])]);
        }

        if (!empty($args['nombre_producto'])) {
            $termProd = $this->likeI($args['nombre_producto']);
            $query->whereExists(function ($sub) use ($termProd) {
                $sub->select(DB::raw(1))
                    ->from('orden_items as oi2')
                    ->leftJoin('productos as p2', 'p2.id', '=', 'oi2.producto_id')
                    ->whereColumn('oi2.orden_id', 'o.id')
                    ->where(function ($q2) use ($termProd) {
                        $q2->whereRaw('LOWER(p2.nombre) LIKE ?', [$termProd])
                           ->orWhereRaw('LOWER(oi2.nombre_custom) LIKE ?', [$termProd]);
                    });
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
                ->leftJoin('productos as p', 'p.id', '=', 'oi.producto_id')
                ->leftJoin('producto_variantes as pv', 'pv.id', '=', 'oi.variante_id')
                ->whereIn('oi.orden_id', $ordenIds)
                ->selectRaw('
                    oi.orden_id,
                    COALESCE(p.nombre, oi.nombre_custom, "Producto personalizado") AS producto,
                    COALESCE(p.categoria, oi.categoria_custom, "personalizado")    AS categoria,
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
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
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
            ->selectRaw('u.id, u.nombre, u.rol, u.activo, t.nombre AS tienda')
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
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
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
            $cliente = DB::table('clientes')
                ->where('id', $args['cliente_id'])
                ->select('id', 'nombre', 'telefono', 'canal_pref', 'tipo', 'created_at')
                ->first();

            if (!$cliente) {
                return ['error' => 'Cliente no encontrado.'];
            }

            // Enmascarar teléfono: solo últimos 4 dígitos
            if ($cliente->telefono) {
                $cliente->telefono = '***' . substr(preg_replace('/\D/', '', $cliente->telefono), -4);
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
                c.id, c.nombre,
                CONCAT("***", RIGHT(REGEXP_REPLACE(IFNULL(c.telefono,""), "[^0-9]", ""), 4)) AS telefono,
                COUNT(DISTINCT o.id)            AS total_ordenes,
                COALESCE(SUM(o.valor_total), 0) AS total_comprado,
                MAX(o.created_at)               AS ultima_compra
            ')
            ->leftJoin('ordenes as o', function ($join) use ($tiendaId) {
                $join->on('o.cliente_id', '=', 'c.id')
                     ->whereNotIn('o.estado', ['cancelado']);
                if ($tiendaId) {
                    $join->where('o.tienda_id', $tiendaId);
                }
            })
            ->groupBy('c.id', 'c.nombre', 'c.telefono')
            ->orderByDesc('total_comprado');

        if (!empty($args['busqueda'])) {
            $termB = $this->likeI($args['busqueda']);
            $query->where(function ($q) use ($termB) {
                $q->whereRaw('LOWER(c.nombre) LIKE ?',   [$termB])
                  ->orWhereRaw('LOWER(c.telefono) LIKE ?', [$termB])
                  ->orWhereRaw('LOWER(c.email) LIKE ?',    [$termB]);
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

    private function handleAnalizarRotacionInventario(array $args, Usuario $usuario): array
    {
        $dias  = max(7, (int) ($args['dias'] ?? 90));
        $desde = now()->subDays($dias)->format('Y-m-d');

        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
                ->value('id');
        }
        if ($usuario->rol === 'vendedor') {
            $tiendaId = $usuario->tienda_default_id;
        }

        $catFiltro = !empty($args['categoria'])
            ? ($this->detectarCategoria($args['categoria']) ?? $args['categoria'])
            : null;

        // 1. Velocidad de ventas por producto en el período
        $ventasQ = DB::table('orden_items as oi')
            ->join('ordenes as o',   'o.id',  '=', 'oi.orden_id')
            ->join('productos as p', 'p.id',  '=', 'oi.producto_id')
            ->where('o.created_at', '>=', $desde . ' 00:00:00')
            ->whereNotIn('o.estado', ['cancelado'])
            ->where('p.activo', true)
            ->selectRaw("p.id, p.nombre, p.categoria,
                SUM(oi.cantidad) AS unidades_vendidas,
                SUM(oi.cantidad * oi.precio_unitario) AS valor_total,
                ROUND(SUM(oi.cantidad) / ({$dias} / 7.0), 2) AS unidades_semana")
            ->groupBy('p.id', 'p.nombre', 'p.categoria');

        if ($tiendaId) $ventasQ->where('o.tienda_id', $tiendaId);
        if ($catFiltro) $ventasQ->whereRaw('LOWER(p.categoria) LIKE ?', [$this->likeI($catFiltro)]);

        $ventas = $ventasQ->get()->keyBy('id');

        // 2. Stock total por producto (suma de tiendas activas)
        $stockQ = DB::table('productos as p')
            ->join('tiendas as t', 't.activa', '=', DB::raw('1'))
            ->leftJoin('inventario as i', function ($j) {
                $j->on('i.producto_id', '=', 'p.id')->on('i.tienda_id', '=', 't.id');
            })
            ->where('p.activo', true)
            ->selectRaw('p.id, p.nombre, p.categoria,
                SUM(COALESCE(i.cantidad_disponible,0)) AS stock_total')
            ->groupBy('p.id', 'p.nombre', 'p.categoria');

        if ($tiendaId) $stockQ->where('t.id', $tiendaId);
        if ($catFiltro) $stockQ->whereRaw('LOWER(p.categoria) LIKE ?', [$this->likeI($catFiltro)]);

        $stocks = $stockQ->get()->keyBy('id');

        // 3. Stock desglosado por tienda (solo donde hay stock > 0)
        $stockTiendaQ = DB::table('inventario as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->join('tiendas as t',   't.id', '=', 'i.tienda_id')
            ->where('p.activo', true)
            ->where('t.activa', true)
            ->where('i.cantidad_disponible', '>', 0)
            ->selectRaw('p.id AS producto_id, p.nombre AS producto, p.categoria,
                t.id AS tienda_id, t.nombre AS tienda,
                i.cantidad_disponible AS stock, i.stock_minimo');

        if ($tiendaId) $stockTiendaQ->where('t.id', $tiendaId);
        if ($catFiltro) $stockTiendaQ->whereRaw('LOWER(p.categoria) LIKE ?', [$this->likeI($catFiltro)]);

        $stockPorTienda = $stockTiendaQ->orderBy('p.nombre')->orderBy('t.nombre')->get();

        // 4. Métricas combinadas
        $analisis = $stocks->map(function ($s) use ($ventas) {
            $v              = $ventas->get($s->id);
            $vendSemana     = $v ? (float) $v->unidades_semana    : 0.0;
            $totalVendido   = $v ? (int)   $v->unidades_vendidas  : 0;
            $valorVendido   = $v ? round($v->valor_total, 0)       : 0;
            $stockTotal     = (int) $s->stock_total;
            $semanasCubierto = $vendSemana > 0 ? round($stockTotal / $vendSemana, 1) : null;

            return [
                'id'              => $s->id,
                'nombre'          => $s->nombre,
                'categoria'       => $s->categoria,
                'stock_total'     => $stockTotal,
                'unidades_vendidas'=> $totalVendido,
                'valor_vendido'   => $valorVendido,
                'unidades_semana' => $vendSemana,
                'semanas_stock'   => $semanasCubierto,
            ];
        })->values();

        // 5. Clasificaciones
        $urgente = $analisis
            ->filter(fn($p) => $p['unidades_semana'] > 0 && ($p['semanas_stock'] === null || $p['semanas_stock'] < 2))
            ->sortByDesc('unidades_semana')->values();

        $proximo = $analisis
            ->filter(fn($p) => $p['unidades_semana'] > 0 && $p['semanas_stock'] !== null && $p['semanas_stock'] >= 2 && $p['semanas_stock'] < 4)
            ->sortByDesc('unidades_semana')->values();

        $sinVentas = $analisis
            ->filter(fn($p) => $p['unidades_vendidas'] === 0)
            ->sortBy('stock_total')->values();

        $exceso = $analisis
            ->filter(fn($p) => $p['semanas_stock'] !== null && $p['semanas_stock'] > 12 && $p['unidades_semana'] > 0)
            ->sortByDesc('semanas_stock')->values();

        return [
            'periodo_dias'     => $dias,
            'desde'            => $desde,
            'resumen' => [
                'total_productos'   => $analisis->count(),
                'con_ventas'        => $analisis->filter(fn($p) => $p['unidades_vendidas'] > 0)->count(),
                'sin_ventas'        => $sinVentas->count(),
                'urgente_producir'  => $urgente->count(),
                'stock_exceso'      => $exceso->count(),
            ],
            'producir_urgente' => $urgente,
            'producir_proximo' => $proximo,
            'sin_ventas'       => $sinVentas,
            'stock_exceso'     => $exceso,
            'stock_por_tienda' => $stockPorTienda,
            'nota' => 'producir_urgente = stock < 2 semanas de ventas. producir_proximo = 2-4 semanas. sin_ventas = 0 unidades vendidas en el período. stock_exceso = >12 semanas de stock. Usa stock_por_tienda para recomendar a qué tiendas enviar el stock producido.',
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

    private function handleConsultarVariantesProducto(array $args, Usuario $usuario): array
    {
        // ── 1. Resolver tienda ────────────────────────────────────────────────
        $tiendaId = $args['tienda_id'] ?? null;
        if (! $tiendaId && ! empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
                ->value('id');
        }
        $soloConStock = $args['solo_con_stock'] ?? true;

        // ── 2. Buscar el producto ─────────────────────────────────────────────
        $nombreBusqueda = trim($args['nombre_producto']);
        $nombreLower    = mb_strtolower($nombreBusqueda);

        $producto = DB::table('productos')
            ->where('activo', true)
            ->whereRaw('LOWER(nombre) LIKE ?', ["%{$nombreLower}%"])
            ->orderByRaw('CHAR_LENGTH(nombre)')   // preferir el match más corto (más exacto)
            ->first(['id', 'nombre', 'categoria']);

        if (! $producto) {
            // Intentar sugerencias por cada palabra con longitud >= 3
            $palabras = collect(preg_split('/\s+/', $nombreLower))
                ->filter(fn($p) => mb_strlen($p) >= 3)
                ->values();

            $sugerencias = collect();
            foreach ($palabras as $palabra) {
                $matches = DB::table('productos')
                    ->where('activo', true)
                    ->whereRaw('LOWER(nombre) LIKE ?', ["%{$palabra}%"])
                    ->pluck('nombre');
                $sugerencias = $sugerencias->concat($matches);
            }
            $sugerencias = $sugerencias->unique()->take(6)->values();

            return [
                'encontrado'   => false,
                'mensaje'      => "No se encontró ningún producto con nombre \"{$nombreBusqueda}\".",
                'sugerencias'  => $sugerencias,
            ];
        }

        // ── 3. Verificar si el producto tiene variantes definidas ─────────────
        $totalVariantes = DB::table('producto_variantes')
            ->where('producto_id', $producto->id)
            ->where('activo', true)
            ->count();

        if ($totalVariantes === 0) {
            return [
                'encontrado'    => true,
                'producto'      => $producto->nombre,
                'categoria'     => $producto->categoria,
                'tiene_variantes' => false,
                'mensaje'       => "El producto \"{$producto->nombre}\" ({$producto->categoria}) no tiene especificaciones de tela, marca ni color registradas. Es un producto que se vende sin variantes (por ejemplo, comedores de madera, mesas, etc.).",
            ];
        }

        // ── 4. Consultar variantes con stock ──────────────────────────────────
        $query = DB::table('producto_variantes as pv')
            ->where('pv.producto_id', $producto->id)
            ->where('pv.activo', true)
            ->leftJoin('inventario_variantes as iv', 'iv.variante_id', '=', 'pv.id')
            ->leftJoin('tiendas as t', 't.id', '=', 'iv.tienda_id')
            ->selectRaw("
                pv.id            AS variante_id,
                pv.marca,
                pv.marca_tela    AS tela,
                pv.nombre_color  AS color,
                t.id             AS tienda_id,
                t.nombre         AS tienda,
                COALESCE(iv.cantidad_disponible, 0) AS disponible,
                COALESCE(iv.cantidad_reservada,  0) AS reservado,
                COALESCE(iv.cantidad_disponible, 0) - COALESCE(iv.cantidad_reservada, 0) AS libre
            ");

        if ($tiendaId) {
            $query->where('iv.tienda_id', $tiendaId);
        } else {
            $query->where(function ($q) {
                $q->where('t.activa', true)->orWhereNull('t.id');
            });
        }

        if (! empty($args['marca'])) {
            $v = mb_strtolower($args['marca']);
            $query->whereRaw('LOWER(pv.marca) LIKE ?', ["%{$v}%"]);
        }
        if (! empty($args['tela'])) {
            $v = mb_strtolower($args['tela']);
            $query->whereRaw('LOWER(pv.marca_tela) LIKE ?', ["%{$v}%"]);
        }
        if (! empty($args['color'])) {
            $v = mb_strtolower($args['color']);
            $query->whereRaw('LOWER(pv.nombre_color) LIKE ?', ["%{$v}%"]);
        }

        if ($soloConStock) {
            $query->whereRaw('COALESCE(iv.cantidad_disponible, 0) - COALESCE(iv.cantidad_reservada, 0) > 0');
        }

        $rows = $query->orderBy('pv.marca')->orderBy('pv.marca_tela')->orderBy('pv.nombre_color')->orderBy('t.nombre')->get();

        // ── 5. Agrupar por combinación de especificaciones ────────────────────
        $porVariante = $rows->groupBy(fn($r) => "{$r->marca}|{$r->tela}|{$r->color}")
            ->map(function ($grupo) {
                $primera = $grupo->first();
                $tiendas = $grupo
                    ->filter(fn($r) => $r->tienda !== null)
                    ->map(fn($r) => [
                        'tienda'     => $r->tienda,
                        'disponible' => $r->disponible,
                        'reservado'  => $r->reservado,
                        'libre'      => $r->libre,
                    ])->values();

                return [
                    'marca'        => $primera->marca,
                    'tela'         => $primera->tela,
                    'color'        => $primera->color,
                    'total_libre'  => $tiendas->sum('libre'),
                    'por_tienda'   => $tiendas,
                ];
            })->values();

        // ── 6. Resumen de colores y telas únicos ──────────────────────────────
        $coloresUnicos = $rows->pluck('color')->filter()->unique()->sort()->values();
        $telasUnicas   = $rows->pluck('tela')->filter()->unique()->sort()->values();
        $marcasUnicas  = $rows->pluck('marca')->filter()->unique()->sort()->values();

        $tiendaNombre = $tiendaId ? DB::table('tiendas')->where('id', $tiendaId)->value('nombre') : null;

        return [
            'encontrado'      => true,
            'tiene_variantes' => true,
            'producto'        => $producto->nombre,
            'categoria'       => $producto->categoria,
            'tienda_filtrada' => $tiendaNombre,
            'total_variantes_con_stock' => $porVariante->count(),
            'colores_disponibles' => $coloresUnicos,
            'telas_disponibles'   => $telasUnicas,
            'marcas_disponibles'  => $marcasUnicas,
            'variantes'           => $porVariante,
            'nota'                => 'libre = disponible - reservado (unidades que se pueden vender ahora)',
        ];
    }

    private function handleConsultarTelas(array $args): array
    {
        $query = DB::table('catalogo_telas')->where('activo', true);

        // Búsqueda general (marca, tipo, color, referencia, textura)
        if (! empty($args['busqueda'])) {
            $term = $this->likeI($args['busqueda']);
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(marca) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(tipo) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(color) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(referencia,"")) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(COALESCE(textura,"")) LIKE ?', [$term]);
            });
        }

        if (! empty($args['marca'])) {
            $query->whereRaw('LOWER(marca) LIKE ?', [$this->likeI($args['marca'])]);
        }
        if (! empty($args['tipo'])) {
            $query->whereRaw('LOWER(tipo) LIKE ?', [$this->likeI($args['tipo'])]);
        }
        if (! empty($args['color'])) {
            $query->whereRaw('LOWER(color) LIKE ?', [$this->likeI($args['color'])]);
        }

        $telas = $query
            ->orderBy('marca')->orderBy('tipo')->orderBy('color')
            ->get(['id', 'marca', 'tipo', 'color', 'referencia', 'textura', 'metros_disponibles', 'metros_reservados'])
            ->map(fn($t) => [
                'id'                 => $t->id,
                'marca'              => $t->marca,
                'tipo'               => $t->tipo,
                'color'              => $t->color,
                'referencia'         => $t->referencia,
                'textura'            => $t->textura,
                'metros_disponibles' => (float) $t->metros_disponibles,
                'metros_reservados'  => (float) $t->metros_reservados,
                'metros_libres'      => round((float) $t->metros_disponibles - (float) $t->metros_reservados, 2),
            ]);

        if (! empty($args['solo_con_metros'])) {
            $telas = $telas->filter(fn($t) => $t['metros_libres'] > 0)->values();
        }

        // Resumen por marca
        $porMarca = $telas->groupBy('marca')->map(fn($g) => [
            'marca'          => $g->first()['marca'],
            'num_referencias'=> $g->count(),
            'metros_libres_total' => round($g->sum('metros_libres'), 2),
        ])->values();

        // Lista de marcas únicas disponibles en sistema
        $marcasDisponibles = DB::table('catalogo_telas')
            ->where('activo', true)->distinct()->orderBy('marca')->pluck('marca');

        return [
            'nota'               => 'metros_libres = metros_disponibles - metros_reservados (disponibles para usar ahora)',
            'total_referencias'  => $telas->count(),
            'proveedores'        => $marcasDisponibles,
            'resumen_por_marca'  => $porMarca,
            'telas'              => $telas->values(),
        ];
    }

    private function handleConsultarCaja(array $args, Usuario $usuario): array
    {
        $mostrarTodas = ! empty($args['todas']);

        // Resolver tienda
        $tiendaId = $args['tienda_id'] ?? null;
        if (! $tiendaId && ! empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
                ->value('id');
        }

        // Vendedor solo puede ver su tienda
        if ($usuario->rol === 'vendedor') {
            $tiendaId     = $usuario->tienda_default_id;
            $mostrarTodas = false;
        }

        // Función de balance para una tienda
        $balanceTienda = function (int $id, string $nombre) {
            $ingresoVentas = DB::table('pagos as p')
                ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
                ->where('o.tienda_id', $id)
                ->sum('p.monto');

            $ingresoManual = DB::table('caja_movimientos')
                ->where('tienda_id', $id)->where('tipo', 'ingreso_manual')->sum('monto');

            $egresos = DB::table('caja_movimientos')
                ->where('tienda_id', $id)->where('tipo', 'egreso')->sum('monto');

            return [
                'tienda_id'      => $id,
                'tienda'         => $nombre,
                'balance'        => round((float) ($ingresoVentas + $ingresoManual - $egresos), 2),
                'ingreso_ventas' => round((float) $ingresoVentas, 2),
                'ingreso_manual' => round((float) $ingresoManual, 2),
                'egresos'        => round((float) $egresos, 2),
            ];
        };

        if ($mostrarTodas) {
            $tiendas = DB::table('tiendas')->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']);
            $resumen = $tiendas->map(fn($t) => $balanceTienda($t->id, $t->nombre))->values();

            return [
                'tipo'            => 'todas_tiendas',
                'total_balance'   => round($resumen->sum('balance'), 2),
                'total_ingresos'  => round($resumen->sum('ingreso_ventas') + $resumen->sum('ingreso_manual'), 2),
                'total_egresos'   => round($resumen->sum('egresos'), 2),
                'por_tienda'      => $resumen,
                'nota'            => 'balance = ingreso_ventas + ingreso_manual - egresos. Ingreso_ventas acumula TODOS los pagos recibidos desde el inicio del sistema.',
            ];
        }

        if (! $tiendaId) {
            return ['error' => 'Especifica una tienda con nombre_tienda o usa todas=true para ver todas.'];
        }

        $tiendaNombre = DB::table('tiendas')->where('id', $tiendaId)->value('nombre') ?? "Tienda #{$tiendaId}";
        $balance      = $balanceTienda($tiendaId, $tiendaNombre);

        // Movimientos recientes opcionales
        if (! empty($args['con_movimientos'])) {
            [$desde, $hasta] = isset($args['periodo'])
                ? $this->parsePeriodo($args['periodo'])
                : [null, null];

            $pagos = DB::table('pagos as p')
                ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
                ->leftJoin('usuarios as u', 'u.id', '=', 'p.vendedor_id')
                ->where('o.tienda_id', $tiendaId)
                ->when($desde, fn($q) => $q->whereBetween('p.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59']))
                ->selectRaw("'ingreso_venta' AS tipo, p.monto, CONCAT('Venta #', p.orden_id) AS concepto, u.nombre AS usuario, p.metodo, p.created_at AS fecha")
                ->orderByDesc('p.created_at')
                ->limit(30)
                ->get();

            $manuales = DB::table('caja_movimientos as cm')
                ->leftJoin('usuarios as u', 'u.id', '=', 'cm.usuario_id')
                ->where('cm.tienda_id', $tiendaId)
                ->when($desde, fn($q) => $q->whereBetween('cm.created_at', [$desde . ' 00:00:00', $hasta . ' 23:59:59']))
                ->selectRaw("cm.tipo, cm.monto, cm.concepto, u.nombre AS usuario, NULL AS metodo, cm.created_at AS fecha")
                ->orderByDesc('cm.created_at')
                ->limit(30)
                ->get();

            $movimientos = $pagos->concat($manuales)
                ->sortByDesc('fecha')
                ->take(40)
                ->values();

            $balance['movimientos'] = $movimientos;
            $balance['periodo']     = $desde ? compact('desde', 'hasta') : 'todo el historial';
        }

        return array_merge(['tipo' => 'tienda_especifica'], $balance, [
            'nota' => 'balance = ingreso_ventas + ingreso_manual - egresos. Todos los montos en COP.',
        ]);
    }

    /**
     * Tool del chat: cotiza un mueble personalizado con el MISMO motor determinístico que Nueva
     * Orden (BomBuilder → CostoCalculator → SanityChecker → few-shot). Devuelve números ya
     * calculados; el modelo solo los presenta (ver AGENT.md, Fase 7).
     */
    private function handleCotizarFabricacion(array $args, Usuario $usuario): array
    {
        $descripcion = trim($args['descripcion_producto'] ?? '');
        $categoria   = trim($args['categoria'] ?? '');

        // El nombre ya lleva la descripción completa; no se pasa 'descripcion' aparte para
        // que calcularPrecioItem no la concatene dos veces en el contexto y el embedding.
        $r = $this->calcularPrecioItem([
            'nombre'            => $descripcion ?: $categoria,
            'categoria'         => $categoria,
            'notas_adicionales' => $args['materiales_cliente'] ?? null,
            'largo_cm'          => $args['largo_cm'] ?? null,
            'ancho_cm'          => $args['ancho_cm'] ?? null,
            'alto_cm'           => $args['alto_cm']  ?? null,
            'num_puestos'       => $args['num_puestos'] ?? null,
        ], $usuario);

        // Devolver solo lo que el modelo debe presentar (sin el BOM crudo ni ids internos)
        return [
            'costo_fabricacion'    => $r['precio_fabricacion'] ?? 0,
            'precio_venta_sugerido'=> $r['precio_sugerido_venta'] ?? 0,
            'costo_materiales'     => $r['costo_materiales'] ?? 0,
            'costo_mano_obra'      => $r['costo_mano_obra'] ?? 0,
            'desglose_materiales'  => $r['desglose_materiales'] ?? [],
            'desglose_mano_obra'   => $r['desglose_mano_obra'] ?? [],
            'requiere_revision'    => $r['requiere_revision'] ?? false,
            'revision_motivos'     => $r['revision_motivos'] ?? [],
            'notas'                => $r['notas'] ?? '',
            'instruccion'          => 'Presenta EXACTAMENTE estos números, sin recalcular. Si requiere_revision es true, dilo claramente y sugiere enviar una consulta de costo al ebanista en vez de dar el precio como definitivo.',
        ];
    }

    // ─── Dispatcher de tool calls ─────────────────────────────────────────────

    private function ejecutarTool(string $toolName, array $args, Usuario $usuario): array
    {
        return match ($toolName) {
            'obtener_ficha_tecnica'        => $this->handleObtenerFichaTecnica($args),
            'buscar_fichas_por_categoria'  => $this->handleBuscarFichasPorCategoria($args),
            'cotizar_fabricacion'          => $this->handleCotizarFabricacion($args, $usuario),
            'consultar_inventario'         => $this->handleConsultarInventario($args, $usuario),
            'productos_mas_vendidos'       => $this->handleProductosMasVendidos($args, $usuario),
            'ventas_por_categoria'         => $this->handleVentasPorCategoria($args, $usuario),
            'ventas_producto_especifico'   => $this->handleVentasProductoEspecifico($args, $usuario),
            'clientes_top'                 => $this->handleClientesTop($args, $usuario),
            'estado_produccion'            => $this->handleEstadoProduccion($args, $usuario),
            'consultar_ordenes'            => $this->handleConsultarOrdenes($args, $usuario),
            'consultar_trabajadores'       => $this->handleConsultarTrabajadores($args, $usuario),
            'consultar_clientes'           => $this->handleConsultarClientes($args, $usuario),
            'buscar_productos_catalogo'    => $this->handleBuscarProductosCatalogo($args),
            'reporte_ventas'               => $this->handleReporteVentas($args, $usuario),
            'reporte_vendedores'           => $this->handleReporteVendedores($args, $usuario),
            'reporte_pendientes'           => $this->handleReportePendientes($args, $usuario),
            'reporte_retrasos'             => $this->handleReporteRetrasos($usuario),
            'listar_tiendas'               => $this->handleListarTiendas(),
            'analizar_rotacion_inventario' => $this->handleAnalizarRotacionInventario($args, $usuario),
            'consultar_variantes_producto' => $this->handleConsultarVariantesProducto($args, $usuario),
            'consultar_interesados'        => $this->handleConsultarInteresados($args, $usuario),
            'consultar_telas'              => $this->handleConsultarTelas($args),
            'consultar_caja'               => $this->handleConsultarCaja($args, $usuario),
            default                        => ['error' => "Tool '{$toolName}' no reconocida."],
        };
    }

    private function handleConsultarInteresados(array $args, Usuario $usuario): array
    {
        // Resolver tienda
        $tiendaId = $args['tienda_id'] ?? null;
        if (!$tiendaId && !empty($args['nombre_tienda'])) {
            $tiendaId = DB::table('tiendas')
                ->whereRaw('LOWER(nombre) LIKE ?', [$this->likeI($args['nombre_tienda'])])
                ->value('id');
        }

        // ── Leads activos (tipo=interesado) ───────────────────────────────────
        $baseLeads = DB::table('clientes')->where('tipo', 'interesado');
        if ($tiendaId) $baseLeads->where('tienda_id', $tiendaId);

        $total = (clone $baseLeads)->count();

        $nuevos = null;
        $desdeP = null;
        $hastaP = null;
        if (!empty($args['periodo'])) {
            [$desdeP, $hastaP] = $this->parsePeriodo($args['periodo']);
            $nuevos = (clone $baseLeads)
                ->whereBetween('created_at', [$desdeP . ' 00:00:00', $hastaP . ' 23:59:59'])
                ->count();
        }

        // ── Análisis de demanda: todos los que tienen categorias_interes ──────
        // Incluye interesados actuales + los ya convertidos a oficial
        // para no perder el historial de qué preguntan.
        $baseDemanda = DB::table('clientes')
            ->whereNotNull('categorias_interes')
            ->whereRaw("JSON_LENGTH(categorias_interes) > 0");
        if ($tiendaId) $baseDemanda->where('tienda_id', $tiendaId);

        $registros = (clone $baseDemanda)->pluck('categorias_interes');
        $conteo = [];
        foreach ($registros as $json) {
            foreach (json_decode($json ?? '[]', true) ?? [] as $cat) {
                $cat = trim($cat);
                if ($cat) $conteo[$cat] = ($conteo[$cat] ?? 0) + 1;
            }
        }
        arsort($conteo);
        $topCategorias = collect($conteo)
            ->map(fn($v, $k) => ['categoria' => $k, 'consultas' => $v])
            ->values();

        // Por tienda con sus top categorías
        $porTienda = DB::table('clientes as c')
            ->join('tiendas as t', 't.id', '=', 'c.tienda_id')
            ->whereNotNull('c.categorias_interes')
            ->whereRaw("JSON_LENGTH(c.categorias_interes) > 0")
            ->when($tiendaId, fn($q) => $q->where('c.tienda_id', $tiendaId))
            ->selectRaw('t.id as tienda_id, t.nombre as tienda, COUNT(*) as total')
            ->groupBy('t.id', 't.nombre')
            ->orderByDesc('total')
            ->get()
            ->map(function ($t) {
                $cats = DB::table('clientes')
                    ->whereNotNull('categorias_interes')
                    ->whereRaw("JSON_LENGTH(categorias_interes) > 0")
                    ->where('tienda_id', $t->tienda_id)
                    ->pluck('categorias_interes');
                $c = [];
                foreach ($cats as $j) {
                    foreach (json_decode($j ?? '[]', true) ?? [] as $cat) {
                        $cat = trim($cat);
                        if ($cat) $c[$cat] = ($c[$cat] ?? 0) + 1;
                    }
                }
                arsort($c);
                $t->top_categorias = array_slice(
                    array_map(fn($v, $k) => "{$k}: {$v}", $c, array_keys($c)),
                    0, 5
                );
                return $t;
            });

        return [
            'total_interesados_activos' => $total,
            'nuevos_en_periodo'         => $nuevos,
            'periodo'                   => $desdeP ? ['desde' => $desdeP, 'hasta' => $hastaP] : null,
            'top_categorias_interes'    => $topCategorias,
            'distribucion_por_tienda'   => $porTienda,
            'nota'                      => 'top_categorias_interes incluye tanto interesados actuales como los que ya se convirtieron a clientes oficiales, para no perder el historial de demanda.',
        ];
    }

    // ─── Cálculo de precio para ítem personalizado (cotizador en nueva orden) ──

    public function calcularPrecioItem(array $params, Usuario $usuario): array
    {
        if (!empty($params['es_restauracion'])) {
            return $this->calcularPrecioRestauracion($params);
        }

        $productoId       = $params['producto_id'] ?? null;
        $nombre           = trim($params['nombre'] ?? '');
        $categoria        = trim($params['categoria'] ?? '');
        $descripcion      = trim($params['descripcion'] ?? '');
        $notasAdicionales = trim($params['notas_adicionales'] ?? '');
        $precioReferencia = isset($params['precio_referencia']) ? (int) $params['precio_referencia'] : null;
        $precioBase       = isset($params['precio_base']) ? (int) $params['precio_base'] : null;
        $largoCm          = isset($params['largo_cm'])    ? (float) $params['largo_cm']  : null;
        $anchoCm          = isset($params['ancho_cm'])    ? (float) $params['ancho_cm']  : null;
        $altoCm           = isset($params['alto_cm'])     ? (float) $params['alto_cm']   : null;
        $numPuestos       = isset($params['num_puestos']) ? (int)   $params['num_puestos'] : null;
        $bocetoUrl        = $params['boceto_url'] ?? null;

        $tieneMedidas = $largoCm || $anchoCm || $altoCm;

        // ── Obtener datos de referencia ──────────────────────────────────────
        $toolDataMedidas = null;

        if ($productoId) {
            // Producto del catálogo: siempre traer su ficha técnica base
            $toolData = $this->handleObtenerFichaTecnica(array_filter([
                'nombre_producto' => $nombre,
                'puestos_nuevo'   => $numPuestos,
            ]));
            $toolUsado = 'ficha_tecnica';

            // Si además cambiaron las medidas, traer estimado por medidas para que
            // la IA pueda escalar la ficha con mayor precisión
            if ($tieneMedidas) {
                $toolDataMedidas = $this->handleCalcularCostoPorMedidas(array_filter([
                    'tipo_producto' => $nombre ?: $categoria,
                    'descripcion'   => $descripcion ?: $nombre,
                    'categoria'     => $categoria,
                    'largo_cm'      => $largoCm,
                    'ancho_cm'      => $anchoCm,
                    'alto_cm'       => $altoCm,
                ]));
            }
        } elseif ($tieneMedidas) {
            $toolData = $this->handleCalcularCostoPorMedidas(array_filter([
                'tipo_producto' => $nombre ?: $categoria,
                'descripcion'   => $descripcion ?: $nombre,
                'categoria'     => $categoria,
                'largo_cm'      => $largoCm,
                'ancho_cm'      => $anchoCm,
                'alto_cm'       => $altoCm,
                'num_puestos'   => $numPuestos,
            ]));
            $toolUsado = 'costo_medidas';
        } else {
            $toolData = $this->handleCalcularCostoPersonalizado(array_filter([
                'descripcion_producto' => $descripcion ?: $nombre,
                'categoria'            => $categoria ?: $nombre,
                'num_puestos'          => $numPuestos,
            ]));
            $toolUsado = 'costo_personalizado';
        }

        // ── Detectar qué cambió (solo para productos del catálogo) ───────────
        $cambioTela    = false;
        $cambioMedidas = false;

        if ($productoId) {
            $descLower = strtolower($descripcion);
            $cambioTela = $descripcion && (
                str_contains($descLower, 'tela')   || str_contains($descLower, 'material') ||
                str_contains($descLower, 'tapiz')  || str_contains($descLower, 'cuero')    ||
                str_contains($descLower, 'cabecero') || str_contains($descLower, ' · ')
            );
            $cambioMedidas = $tieneMedidas;
        }

        // ── Construir el contexto para la IA ─────────────────────────────────
        if ($productoId) {
            $soloRetapizado = $cambioTela && !$cambioMedidas;
            $soloAltura     = $cambioMedidas && !$cambioTela;

            $cambiosTexto = [];
            if ($cambioTela)    $cambiosTexto[] = $soloRetapizado ? 'SOLO retapizado (mueble ya fabricado en stock)' : 'cambio de tela/material';
            if ($cambioMedidas) $cambiosTexto[] = $soloAltura ? 'ajuste de altura/medidas sobre mueble existente' : 'nuevas medidas ' . implode('×', array_filter([$largoCm, $anchoCm, $altoCm])) . ' cm';
            if ($descripcion && !$cambioTela && !$cambioMedidas) $cambiosTexto[] = 'modificaciones adicionales';

            $precioBaseTexto = $precioBase
                ? ' Precio de venta actual en catálogo: $' . number_format($precioBase, 0, ',', '.') . ' COP.'
                : '';

            $contexto = "Producto del catálogo: \"{$nombre}\"."
                . ($categoria ? " Categoría: {$categoria}." : '')
                . $precioBaseTexto
                . ($cambiosTexto
                    ? ' Trabajo a realizar: ' . implode(' y ', $cambiosTexto) . '.'
                    : ' Sin cambios — usar costo base de la ficha.')
                . ($descripcion ? " Especificación: {$descripcion}." : '')
                . ($notasAdicionales ? " Notas adicionales del cliente: {$notasAdicionales}." : '');
        } else {
            $refTexto = $precioReferencia
                ? ' REFERENCIA DE PRECIO: un producto similar o idéntico costó $' . number_format($precioReferencia, 0, ',', '.') . ' COP — usa este valor como ancla fuerte para tu estimado. precio_sugerido_venta debe estar cerca de este valor salvo que las especificaciones difieran significativamente.'
                : '';

            $contexto = "Producto nuevo a fabricar: \"{$nombre}\"."
                . ($categoria         ? " Categoría: {$categoria}."              : '')
                . ($descripcion       ? " Especificaciones: {$descripcion}."      : '')
                . ($notasAdicionales  ? " Notas adicionales del cliente: {$notasAdicionales}." : '')
                . ($largoCm           ? " Medidas: {$largoCm}×{$anchoCm}×{$altoCm} cm." : '')
                . ($numPuestos        ? " Puestos/módulos: {$numPuestos}."        : '')
                . $refTexto;
        }

        // Factor para SUGERIR un precio de venta a partir del costo de fabricación.
        // El supervisor decide el precio final; esto es solo una referencia. El factor vive
        // en `configuracion` para poder ajustarlo sin tocar código (ver AGENT.md, Fase 6).
        $multiplicador = $this->factorVentaSugerido();

        // Regla de venta: retapizado y ajustes de altura son servicios sobre un mueble
        // que YA existe — el cliente paga el producto de catálogo + el costo del servicio,
        // sin el multiplicador de fabricación. ($cambioTela y $cambioMedidas se inicializan
        // en false más arriba, así que siempre están definidos.)
        $esServicioSobreExistente = $productoId && $precioBase && ($cambioTela || $cambioMedidas);

        // ── La IA arma la receta; el código calcula el precio ────────────────
        $referencia = ['ficha_base' => $toolData];
        if ($toolDataMedidas) {
            $referencia['estimado_por_medidas'] = $toolDataMedidas;
        }

        $candidatos = $this->materialesCandidatos(
            trim("{$nombre} {$categoria} {$descripcion}"),
            $toolData['items_referencia'] ?? $toolData['items'] ?? collect(),
        );

        // Ejemplos de correcciones reales de ebanistas en muebles parecidos (Fase 5)
        $ejemplos = $this->fewShot->ejemplos(trim("{$nombre} {$descripcion}"), $categoria ?: null);

        $bom = $this->bomBuilder->construir($contexto, $referencia, $candidatos, $bocetoUrl, $ejemplos);

        $resultado = $this->calculator->calcular($bom, [
            'multiplicador'   => $multiplicador,
            'regla_venta'     => $esServicioSobreExistente ? 'catalogo_mas_costo' : 'multiplicador',
            'precio_catalogo' => $precioBase,
            'cantidad'        => 1,
        ]);

        // ── Validación de cordura: ¿el número es plausible? ──────────────────
        // Un estimado fuera de la realidad del catálogo no se entrega como precio en firme;
        // se marca para que lo revise un ebanista. Ver AGENT.md, Fase 4.
        $fichasRef = collect($toolData['fichas_referencia'] ?? []);
        if ($fichasRef->isEmpty() && ! empty($toolData['ficha'])) {
            $fichasRef = collect([$toolData['ficha']]);
        }

        $chequeo = $this->sanity->revisar(
            $resultado['precio_fabricacion'],
            $bom,
            $fichasRef,
            $categoria ?: null,
        );

        if ($chequeo['requiere_revision']) {
            $resultado['requiere_revision'] = true;
            $resultado['revision_motivos']  = $chequeo['motivos'];
            // Los motivos NO se meten en `notas`: el front los pinta en su propio bloque de alerta.
            // `notas` sigue siendo para supuestos y elementos a consultar del BOM.
        }

        // Registrar el estimado para el bucle de aprendizaje (Fase 5). Best-effort: si falla,
        // la cotización se entrega igual. Solo se guardan cotizaciones de fabricación, no
        // restauraciones (esas van por otra ruta).
        $estimadoId = $this->fewShot->registrar(
            trim("{$nombre} {$descripcion}") ?: $nombre,
            $categoria ?: null,
            $bom,
            (int) $resultado['precio_fabricacion'],
            (bool) ($resultado['requiere_revision'] ?? false),
            array_filter(['largo' => $largoCm, 'ancho' => $anchoCm, 'alto' => $altoCm]),
        );

        return array_merge([
            'ok'          => true,
            'tool_usado'  => $toolUsado,
            'bom'         => $bom,
            'estimado_id' => $estimadoId,
        ], $resultado);
    }

    /**
     * Materiales elegibles para la receta, CON su id (el LLM elige por id, nunca por precio).
     *
     * Prioriza los materiales que realmente usan las fichas de referencia — ahí están los
     * precios y las unidades correctas para este tipo de mueble — y completa con coincidencias
     * por palabra clave. A diferencia de materialesRelevantes(), no rellena con materiales
     * alfabéticos sin relación (ver AGENT.md, Fase 3).
     */
    private function materialesCandidatos(string $contexto, $itemsRef, int $max = 80): array
    {
        $nombresEnFichas = collect($itemsRef)
            ->filter(fn($i) => empty($i->es_mano_obra))
            ->map(fn($i) => mb_strtolower(trim($i->descripcion)))
            ->unique()
            ->values();

        $keywords = collect(preg_split('/\s+/', mb_strtolower($contexto)))
            ->map(fn($k) => trim($k, '.,;:()'))
            ->filter(fn($k) => mb_strlen($k) >= 4)
            ->unique()
            ->values();

        $cols = ['id', 'nombre', 'unidad', 'unidad_norm', 'activo', 'equivalente_a_id'];

        // 1. Match exacto con lo que usan las fichas de referencia (99% de los items de
        //    ficha resuelven a un material real, así que esto casi siempre acierta).
        //    No se filtra por `activo` aquí: si la ficha usa una grafía duplicada (COLBON),
        //    hay que traerla igual y luego colapsarla a su canónico (CARPINCOL).
        $exactos = collect();
        if ($nombresEnFichas->isNotEmpty()) {
            $exactos = DB::table('materiales')
                ->whereIn(DB::raw('LOWER(nombre)'), $nombresEnFichas->all())
                ->get($cols);
        }

        // 2. Coincidencias por palabra clave del mueble, para lo que la ficha no cubra.
        //    Aquí sí se excluyen los duplicados: no tiene sentido ofrecerle al modelo
        //    cuatro pegantes idénticos entre los que elegir.
        $porKeyword = collect();
        if ($keywords->isNotEmpty()) {
            $porKeyword = DB::table('materiales')
                ->where('activo', true)
                ->where(function ($q) use ($keywords) {
                    foreach ($keywords as $kw) {
                        $q->orWhereRaw('LOWER(nombre) LIKE ?', ['%' . $kw . '%']);
                    }
                })
                ->whereNotIn('id', $exactos->pluck('id')->all() ?: [0])
                ->limit(max(0, $max - $exactos->count()))
                ->get($cols);
        }

        return $this->colapsarEquivalentes($exactos->concat($porKeyword))
            ->take($max)
            ->map(fn($m) => [
                'id'     => $m->id,
                'nombre' => $m->nombre,
                'unidad' => $m->unidad_norm ?: ($m->unidad ?: 'unidad'),
            ])
            ->values()
            ->all();
    }

    /**
     * Sustituye cada material duplicado por su canónico y deduplica la lista, para que el
     * modelo no vea varias grafías del mismo producto (CARPINCOL / COLBON / COBON…).
     */
    private function colapsarEquivalentes(\Illuminate\Support\Collection $materiales): \Illuminate\Support\Collection
    {
        $canonicoIds = $materiales
            ->filter(fn($m) => ! empty($m->equivalente_a_id))
            ->pluck('equivalente_a_id')
            ->unique();

        $canonicos = $canonicoIds->isEmpty()
            ? collect()
            : DB::table('materiales')->whereIn('id', $canonicoIds)
                ->get(['id', 'nombre', 'unidad', 'unidad_norm', 'activo', 'equivalente_a_id'])
                ->keyBy('id');

        return $materiales
            ->map(fn($m) => ! empty($m->equivalente_a_id) && $canonicos->has($m->equivalente_a_id)
                ? $canonicos->get($m->equivalente_a_id)
                : $m)
            ->unique('id')
            ->values();
    }

    // ─── Loop principal del agente ────────────────────────────────────────────

    public function chat(array $messages, Usuario $usuario): string
    {
        $tiendaInfo = $usuario->tienda_default_id
            ? DB::table('tiendas')->where('id', $usuario->tienda_default_id)->value('nombre')
            : 'todas las tiendas';

        // Sanitizar valores interpolados en el system prompt para evitar inyección de instrucciones
        $nombreSeguro = preg_replace('/[\r\n\t\x00-\x1F\x7F]+/', ' ', strip_tags((string) $usuario->nombre));
        $rolSeguro    = preg_replace('/[^a-zA-Z_áéíóú]/', '', (string) $usuario->rol);
        $tiendaSegura = preg_replace('/[\r\n\t\x00-\x1F\x7F]+/', ' ', strip_tags((string) $tiendaInfo));

        $systemPrompt = <<<EOT
Eres el asistente de negocios de Decasa (muebles, Colombia). Responde siempre en español, claro y conciso.
Usuario: {$nombreSeguro} | Rol: {$rolSeguro} | Tienda: {$tiendaSegura} | Hoy: {$this->hoy()}

ESTADOS DE ÓRDENES: pendiente_anticipo | en_produccion | listo_entrega | en_camino | entregado | cancelado
- "listas para entregar/despachar" → listo_entrega | "en camino/en ruta/con conductor" → en_camino | "en producción/en taller" → en_produccion | "esperando pago/sin anticipo" → pendiente_anticipo

TIENDAS: Filtra por tienda SOLO si el usuario lo menciona explícitamente. Si el rol es supervisor, tiene visibilidad de TODAS las tiendas aunque tenga una tienda_default asignada — no filtres por su tienda a menos que la pida. Usa nombre_tienda (nombre parcial), NUNCA inventes tienda_id numérico. Si necesitas el ID exacto, llama listar_tiendas primero.

FECHAS: entrega → fecha_entrega_prometida. creación orden → fecha_creacion_orden. inicio fabricación → fecha_inicio. NUNCA uses fecha_creacion_orden ni fecha_inicio para responder sobre entregas. NUNCA inventes fechas.

CATEGORÍAS: Cuando el usuario diga el tipo de mueble usa siempre el valor BD del enum del tool (sillas_aux, sofas, comedores, camas, etc.). Si es modelo específico (ej: "Silla Alicia", "Base 2K") usa nombre_producto no categoria.

PRODUCCIÓN vs ÓRDENES: estado_produccion → qué se fabrica en el taller (tabla produccion). consultar_ordenes → órdenes de clientes. Son tablas distintas — una orden listo_entrega puede tener producción en estado listo simultáneamente.

COSTOS DE FABRICACIÓN — sigue este orden SOLO cuando el usuario pida precio, costo, valor o cotización de fabricación. Si la pregunta es sobre colores/telas/marcas/variantes disponibles, usa en su lugar consultar_variantes_producto (ver sección VARIANTES).
1. El usuario menciona un nombre de producto (ej: "Cama Estocolmo", "Silla Alicia", "Sofá Roma", "Sofá Modular Telavid") → llama SIEMPRE obtener_ficha_tecnica primero, aunque el nombre parezca descriptivo. Si la ficha existe (encontrado: true), presenta los costos reales exactos así (sin LaTeX):
"**[Nombre del producto]**
Mano de obra:
· [cargo] – [descripcion]: [cantidad] h × $[precio_unitario]/h = $[subtotal]
· ...
Materiales:
· [descripcion]: [cantidad] [unidad] × $[precio_unitario] = $[subtotal]
· ...
**Costo materiales: [valor]** | **Mano de obra: [valor]** | **Total: [valor]**"
Si obtener_ficha_tecnica devuelve encontrado: false con sugerencias NO VACÍAS → muestra las sugerencias y PREGUNTA: "¿Te refieres a alguno de estos productos? [lista]. Si no es ninguno, puedo calcular el costo de uno nuevo." NO estimes hasta que el usuario confirme que no es ninguna sugerencia.
Solo si sugerencias está vacía pasa al paso 2.
2. El mueble es PERSONALIZADO (no está en el catálogo): el usuario da medidas, describe algo a la medida, un híbrido (cama+escritorio), o sube foto/boceto → llama cotizar_fabricacion con la descripción y lo que sepas (categoría, medidas, materiales). Llámala de inmediato; si faltan medidas ella usa estimados típicos. Esta herramienta hace el cálculo oficial con precios reales de la base de datos.

REGLA CRÍTICA DE COTIZACIÓN: cuando uses cotizar_fabricacion, NUNCA recalcules ni cambies los números. Presenta EXACTAMENTE el costo_fabricacion, los desgloses y el precio_venta_sugerido que devuelve. Formato (sin LaTeX, texto plano):
"**Mano de obra**
· [cada línea de desglose_mano_obra tal cual]
**Materiales**
· [cada línea de desglose_materiales tal cual]
**Costo de fabricación: $[costo_fabricacion]**
**Precio de venta sugerido: $[precio_venta_sugerido]** (referencia — el supervisor define el precio final)"
Si requiere_revision es true → NO des el costo como definitivo. Di: "⚠️ Este estimado necesita revisión de un ebanista antes de usarlo como precio" y lista revision_motivos. Sugiere enviar una consulta de costo.
Muestra también las líneas de `notas` que empiecen con ⚠️ (elementos a consultar).
NUNCA uses LaTeX (\frac, \times, \text, paréntesis \( \)).

INTERESADOS / DEMANDA — usa consultar_interesados ante preguntas como: "¿qué es lo que más preguntan?", "¿qué categorías demanda la gente?", "¿cuántos interesados tenemos?", "¿qué se pregunta en tienda X?", "¿qué deberíamos fabricar según la gente que visita?".
- Si el usuario menciona una tienda → pasa nombre_tienda con el nombre parcial.
- Si no menciona tienda → llama sin filtro para ver el panorama general + todas las tiendas.
Formato de respuesta:
"**Interesados — [nombre tienda o 'todas las tiendas']**
Leads activos: N | Nuevos en el período: N

**Lo que más preguntan:**
1. [Categoría] — N consulta(s)
2. ...

**Por tienda:** (omitir si hay filtro de tienda activo)
· [Tienda A] (N personas): [Cat1] (N), [Cat2] (N)
· [Tienda B] (N personas): ..."
Al final agrega una recomendación de producción basada en las categorías más demandadas: "👷 Sugerencia: considerar fabricar más [categoría top] para [tienda con más demanda]."

ANÁLISIS PREDICTIVO — usa analizar_rotacion_inventario ante preguntas como: ¿qué fabricar?, ¿qué dejar de producir?, ¿dónde hay falta de stock?, ¿qué productos tienen poca salida? Parámetro dias: 30 = tendencia reciente, 90 = estándar, 180 = largo plazo. Presenta el resultado así:
"**Fabricar urgente** (stock < 2 semanas):
· [Producto] — [X] u/semana, stock para [N] semanas — enviar a [Tienda A] ([stock]) y [Tienda B] ([stock])
**Fabricar pronto** (2-4 semanas de stock):
· ...
**Sin ventas en [N] días** (evaluar descontinuar):
· [Producto] — [stock] unidades sin movimiento
**Exceso de stock** (>12 semanas):
· ..."
Si pregunta por una tienda específica, filtra por ella y recomienda qué debe pedir a producción.

VARIANTES (tela/color/marca) — PRIORIDAD ALTA: cuando el usuario pregunte por colores, telas, marcas, especificaciones o combinaciones disponibles de un producto, usa consultar_variantes_producto DIRECTAMENTE, sin pasar por obtener_ficha_tecnica. Señales clave: "¿qué colores hay?", "¿qué telas tiene?", "¿de qué marca?", "¿qué opciones hay?", "¿qué referencias/especificaciones hay disponibles?", "¿hay en [color/tela]?". Pasa el nombre COMPLETO del producto tal como lo dice el usuario (ej: "silla aux alicia", no solo "alicia"). Presenta el resultado así:
Si tiene_variantes = false → "El [producto] no maneja especificaciones de tela o color (es un producto sin variantes, como comedores de madera o mesas)."
Si tiene_variantes = true y hay variantes con stock → lista así:
"**[Producto]** — variantes disponibles[en (Tienda) si hay filtro de tienda]:
· Marca: [marca] | Tela: [tela] | Color: [color] — [N] libre(s)[por tienda si hay varias]
· ..."
Si no hay ninguna variante con stock → "Actualmente no hay stock disponible en ninguna variante de ese producto[en esa tienda]."
NUNCA inventes variantes ni colores. NUNCA respondas que no tiene variantes si no has llamado el tool primero.

TELAS — usa consultar_telas ante cualquier pregunta sobre inventario de telas: "¿qué telas hay?", "¿cuántos metros de [tela/color] quedan?", "¿hay [marca]?", "telas disponibles", "proveedores de telas", "¿alcanza la tela para X pedidos?". Parámetros clave: busqueda (texto libre), marca, tipo, color, solo_con_metros=true (solo las que tienen metros libres). Formato de respuesta:
"**Telas disponibles**
· [Marca] — [Tipo] [Color]: [N] m libres ([N] disponibles, [N] reservados)
· ...
Proveedores: [lista de marcas]"
Si filtra por color o tipo que no existe, responde: "No hay referencias de [X] con metros disponibles actualmente."

CAJA — usa consultar_caja ante preguntas sobre dinero en tiendas: "¿cuánto hay en caja?", "¿cuánto tiene la tienda X?", "balance de la caja", "ingresos de la semana", "egresos", "resumen de todas las cajas". Parámetros clave: nombre_tienda (nombre parcial), todas=true (todas las tiendas), con_movimientos=true + periodo (para ver movimientos). Formato:
"**Caja — [Tienda]**
Balance: $ X.XXX.XXX
Ingresos ventas: $ X.XXX.XXX | Ingresos manuales: $ X.XXX.XXX | Egresos: $ X.XXX.XXX"
Para todas las tiendas lista cada una con su balance. Nota: los ingresos de caja acumulan TODOS los pagos desde el inicio, no solo el período actual; si el usuario quiere del período usa con_movimientos=true + periodo.

RESTRICCIONES POR ROL — aplican automáticamente en el backend:
- Vendedor: puede consultar inventario y variantes de CUALQUIER tienda (para verificar si otra tienda tiene el producto que busca el cliente). Solo ve sus propias órdenes, producción de su tienda y retrasos de su tienda. NO tiene acceso a reporte de vendedores ni ventas globales de toda la empresa.
- Si un vendedor pregunta por rankings de vendedores o ventas totales de la empresa, responde: "Esa información no está disponible para tu perfil. Puedo mostrarte tus estadísticas o las de tu tienda."

REGLAS: Dinero en formato COP ($ 1.200.000). No inventes datos. Muestra productos cuando una orden los tiene.
EOT;

        // Convertir mensajes: si alguno trae imagen, usar formato vision de OpenAI
        $mensajesFormateados = array_map(function (array $msg) {
            if (!empty($msg['image'])) {
                return [
                    'role'    => $msg['role'],
                    'content' => [
                        ['type' => 'text',      'text'      => $msg['content']],
                        ['type' => 'image_url', 'image_url' => ['url' => $msg['image'], 'detail' => 'high']],
                    ],
                ];
            }
            return ['role' => $msg['role'], 'content' => $msg['content']];
        }, $messages);

        $apiMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $mensajesFormateados
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

    /**
     * Factor con el que se sugiere un precio de venta a partir del costo de fabricación.
     * Ajustable desde `configuracion` (clave `factor_venta_sugerido`); por defecto ×2.0.
     */
    public const FACTOR_VENTA_DEFAULT = 2.0;

    private function factorVentaSugerido(): float
    {
        $valor  = DB::table('configuracion')->where('clave', 'factor_venta_sugerido')->value('valor');
        $factor = is_numeric($valor) ? (float) $valor : self::FACTOR_VENTA_DEFAULT;

        return $factor > 0 ? $factor : self::FACTOR_VENTA_DEFAULT;
    }

    // ─── Cotizador de precio para restauración de muebles ───────────────────────

    private function calcularPrecioRestauracion(array $params): array
    {
        $nombre   = trim($params['nombre']  ?? 'Mueble');
        $trabajo  = trim($params['trabajo'] ?? ($params['descripcion'] ?? ''));
        $cantidad = max(1, (int) ($params['cantidad'] ?? 1));
        $boceto   = $params['boceto_url'] ?? null;

        $tarifasTexto = DB::table('tarifas_proceso')
            ->orderBy('proceso')
            ->get(['proceso', 'descripcion', 'unidad', 'tarifa', 'cargo'])
            ->map(fn($t) =>
                "- {$t->proceso} ({$t->descripcion}): \${$t->tarifa} por {$t->unidad}" .
                ($t->cargo ? " [operario: {$t->cargo}]" : '')
            )->implode("\n");

        $salariosTexto = DB::table('salarios_cargo')
            ->orderBy('cargo')
            ->get(['cargo', 'descripcion', 'tarifa_hora'])
            ->map(fn($s) => "- {$s->cargo} ({$s->descripcion}): \${$s->tarifa_hora}/hora")
            ->implode("\n");

        // Fallback si las tablas aún no tienen datos
        if (empty($tarifasTexto)) {
            $tarifasTexto = "- tapizado: $18.000 por hora [tapicero]\n- carpinteria: $15.000 por hora [carpintero]\n- lacado: $12.000 por hora [pintor]\n- lijado: $10.000 por hora [lijador]";
        }
        if (empty($salariosTexto)) {
            $salariosTexto = "- tapicero: $18.000/hora\n- carpintero: $15.000/hora\n- pintor: $12.000/hora\n- lijador: $10.000/hora";
        }

        $system = <<<SYSTEM
Eres el cotizador de DECASA Muebles para servicios de RESTAURACIÓN de muebles del cliente.
Calcula el costo del servicio y el precio de venta.
DEVUELVE SOLO JSON VÁLIDO sin markdown ni texto adicional.

Estructura del JSON (exactamente estos campos):
{
  "precio_fabricacion": number,
  "precio_sugerido_venta": number,
  "desglose_materiales": [{"descripcion": string, "subtotal": number}],
  "desglose_mano_obra": [{"descripcion": string, "subtotal": number}],
  "notas": string
}

TARIFAS DE PROCESO DISPONIBLES:
{$tarifasTexto}

TARIFAS DE PERSONAL POR HORA:
{$salariosTexto}

REGLAS:
- precio_sugerido_venta = precio_fabricacion × 1.8 (margen estándar de servicio)
- precio_fabricacion = total materiales + total mano de obra
- Cantidad de piezas a restaurar: {$cantidad} (multiplica costos proporcionales si aplica)
- Mano de obra: usa las tarifas disponibles. Si el trabajo combina varias operaciones (ej: tapizado + laca), suma cada proceso por separado
- Materiales: solo los que el trabajo realmente requiere (tela, espuma, laca, barniz, puntillas, etc.). Si no se especifica el material, usa un estimado razonable para el mueble
- Si hay imagen del mueble: analiza su estado, tamaño estimado y componentes para refinar el estimado
- Si algo es incierto (material exacto, dimensiones, estado), indícalo en notas con ⚠️
- desglose_materiales puede estar vacío [] si el trabajo es solo mano de obra (ej: solo laca)
SYSTEM;

        $userText = "Mueble: {$nombre}\n";
        if ($trabajo) $userText .= "Trabajo solicitado: {$trabajo}\n";
        $userText .= "Cantidad: {$cantidad} pieza(s)";

        $messages = [['role' => 'system', 'content' => $system]];
        $messages[] = $boceto
            ? [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $userText],
                    ['type' => 'image_url', 'image_url' => ['url' => $boceto, 'detail' => 'high']],
                ],
            ]
            : ['role' => 'user', 'content' => $userText];

        try {
            $response = OpenAI::chat()->create([
                'model'           => config('openai.model', 'gpt-4o-mini'),
                'messages'        => $messages,
                'max_tokens'      => 1200,
                'response_format' => ['type' => 'json_object'],
            ]);

            $raw     = trim($response->choices[0]->message->content ?? '');
            $decoded = json_decode($raw, true);

            if (!$decoded || !isset($decoded['precio_fabricacion'])) {
                return ['ok' => false, 'error' => 'No se pudo calcular. Agrega más detalles del trabajo a realizar.'];
            }

            return array_merge(['ok' => true, 'tool_usado' => 'restauracion'], $decoded);
        } catch (\Throwable $e) {
            \Log::error('calcularPrecioRestauracion', ['err' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Error al consultar la IA. Intenta de nuevo.'];
        }
    }
}
