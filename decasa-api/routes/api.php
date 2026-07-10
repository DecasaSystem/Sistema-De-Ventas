

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\RedesController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\OrdenController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\ProduccionController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\TiendaController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\DespachoController;
use App\Http\Controllers\SurtidoController;
use App\Http\Controllers\TrasladoController;
use App\Http\Controllers\VarianteController;
use App\Http\Controllers\FichaTecnicaController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\CamionController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ConsultaCostoController;
use App\Http\Controllers\PrecioItemController;
use App\Http\Controllers\ConfiguracionCostosController;
use App\Http\Controllers\FacturacionController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\RestauracionController;
use App\Http\Controllers\CatalogoTelaController;
use App\Http\Controllers\InventarioTelaController;
use App\Http\Controllers\TipoVarianteController;
use App\Http\Controllers\ProductoVarianteConfigController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\ComisionController;
use Illuminate\Support\Facades\Route;

// ── Auth (público) ────────────────────────────────────────────────────────────
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ── Webhook del agente WA (público con token secreto) ────────────────────────
Route::post('/redes/webhook', [RedesController::class, 'webhook'])->middleware('throttle:60,1');

// ── VAPID public key (público — necesario antes de login para suscribir) ─────
Route::get('/push/vapid-key', [PushSubscriptionController::class, 'vapidKey']);

// ── Rutas protegidas ─────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout',    [AuthController::class, 'logout']);

    // Push subscriptions
    Route::post('/push/subscribe',   [PushSubscriptionController::class, 'subscribe']);
    Route::delete('/push/subscribe', [PushSubscriptionController::class, 'unsubscribe']);
    Route::get('/auth/me',         [AuthController::class, 'me']);
    Route::patch('/auth/mi-firma',   [AuthController::class, 'guardarFirma']);
    Route::patch('/auth/mi-cuenta',  [AuthController::class, 'actualizarCuenta']);

    // Tiendas (solo lectura — usada por el selector de tienda en la orden)
    Route::get('/tiendas', [TiendaController::class, 'index']);

    // Reserva / Fábrica
    Route::get('/reserva/info',                          [ReservaController::class, 'info']);
    Route::get('/reserva/stock-lote',                    [ReservaController::class, 'stockLote']);
    // Lectura de inventario: cualquier usuario autenticado (vendedor consulta, supervisor gestiona)
    Route::get('/reserva/inventario',                    [ReservaController::class, 'inventario']);
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/reserva/entrada',                  [ReservaController::class, 'entrada']);
        Route::post('/reserva/variante-entrada',         [ReservaController::class, 'entradaVariante']);
        Route::post('/reserva/salida',                   [ReservaController::class, 'salida']);
        Route::get('/reserva/movimientos/{productoId}',  [ReservaController::class, 'movimientos'])->whereNumber('productoId');
    });

    // Productos
    Route::get('/productos',             [ProductoController::class, 'index']);
    Route::get('/productos/categorias',  [ProductoController::class, 'categorias']);
    Route::post('/productos',            [ProductoController::class, 'store']);
    Route::get('/productos/{id}',        [ProductoController::class, 'show']);
    Route::patch('/productos/{id}',      [ProductoController::class, 'update']);
    Route::delete('/productos/{id}',     [ProductoController::class, 'destroy'])->middleware('role:supervisor');

    // Clientes
    Route::get('/clientes',               [ClienteController::class, 'index']);
    Route::post('/clientes',              [ClienteController::class, 'store']);
    Route::get('/clientes/exportar',      [ClienteController::class, 'exportar']);
    Route::get('/clientes/{id}',          [ClienteController::class, 'show']);
    Route::put('/clientes/{id}',          [ClienteController::class, 'update']);
    Route::get('/clientes/{id}/ordenes',  [ClienteController::class, 'ordenes']);

    // Órdenes
    Route::get('/ordenes',              [OrdenController::class, 'index']);
    Route::post('/ordenes',             [OrdenController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/ordenes/{id}',                         [OrdenController::class, 'show']);
    Route::patch('/ordenes/{id}',                       [OrdenController::class, 'update']);
    Route::patch('/ordenes/{id}/estado',                [OrdenController::class, 'updateEstado']);
    Route::post('/ordenes/{id}/confirmar-cotizacion',   [OrdenController::class, 'confirmarCotizacion']);
    Route::post('/ordenes/{id}/completar-borrador',     [OrdenController::class, 'completarBorrador']);
    Route::get('/ordenes/{id}/pdf',                     [OrdenController::class, 'pdf']);
    Route::post('/ordenes/{id}/reenviar-cotizacion',    [OrdenController::class, 'reenviarCotizacion']);
    Route::patch('/ordenes/{id}/fechas-entrega',        [OrdenController::class, 'asignarFechas']);

    // Restauraciones
    Route::get('/restauraciones',  [RestauracionController::class, 'index']);
    Route::post('/restauraciones', [RestauracionController::class, 'store']);

    // Pagos
    Route::get('/ordenes/{id}/pagos',  [PagoController::class, 'index']);
    Route::post('/ordenes/{id}/pagos', [PagoController::class, 'store']);
    Route::post('/pagos/{id}/tomar-facturacion', [PagoController::class, 'tomarFacturacion']);
    Route::post('/pagos/{id}/marcar-facturada',  [PagoController::class, 'marcarFacturada']);

    // Subida de archivos
    Route::post('/upload/foto', [UploadController::class, 'foto']);

    // Inventario
    Route::get('/inventario',                              [InventarioController::class, 'index']);
    Route::get('/inventario/{productoId}/movimientos',     [InventarioController::class, 'movimientos'])->whereNumber('productoId');
    // Entrada y salida de stock: vendedor puede operar en su propia tienda (controllers validan tienda_id)
    Route::middleware('role:supervisor,vendedor')->group(function () {
        Route::post('/inventario/entrada',                             [InventarioController::class, 'entrada']);
        Route::post('/inventario/salida',                              [InventarioController::class, 'salida']);
        Route::post('/inventario/variantes/entrada',                   [VarianteController::class, 'entrada']);
        Route::post('/inventario/variantes/salida',                    [VarianteController::class, 'salida']);
        Route::post('/inventario/variante-configs/salida',             [ProductoVarianteConfigController::class, 'salidaConfig']);
        Route::post('/inventario/variante-combinaciones/salida',       [ProductoVarianteConfigController::class, 'salidaCombinacion']);
    });

    // Vendedores-tienda — usado para seleccionar validadores en surtidos y traslados
    Route::get('/inventario/vendedores-tienda/{tiendaId}', [SurtidoController::class, 'vendedoresTienda'])->whereNumber('tiendaId');

    // Surtir — accesible para vendedor (pendientes, aceptar, rechazar) y supervisor (todo)
    Route::get('/inventario/surtidos/pendientes',          [SurtidoController::class, 'pendientes']);
    Route::patch('/inventario/surtido-tiendas/{id}/aceptar', [SurtidoController::class, 'aceptar']);
    Route::patch('/inventario/surtido-tiendas/{id}/rechazar', [SurtidoController::class, 'rechazar']);

    // Traslados entre tiendas (supervisor y vendedor, con guardas en el controlador)
    Route::get('/inventario/traslados/pendientes',                   [TrasladoController::class, 'pendientes']);
    Route::get('/inventario/traslados/stock-tienda/{tiendaId}',      [TrasladoController::class, 'stockTienda'])->whereNumber('tiendaId');
    Route::post('/inventario/traslados',                             [TrasladoController::class, 'crear']);
    Route::get('/inventario/traslados',                              [TrasladoController::class, 'index']);
    Route::patch('/inventario/traslados/{id}/aceptar',               [TrasladoController::class, 'aceptar'])->whereNumber('id');
    Route::patch('/inventario/traslados/{id}/rechazar',              [TrasladoController::class, 'rechazar'])->whereNumber('id');

    Route::middleware('role:supervisor')->group(function () {

        Route::post('/inventario/surtir',                          [SurtidoController::class, 'crear']);
        Route::get('/inventario/surtidos',                         [SurtidoController::class, 'index']);
        Route::get('/inventario/surtidos/{id}',                    [SurtidoController::class, 'show'])->whereNumber('id');
        Route::get('/inventario/recomendaciones',                  [SurtidoController::class, 'recomendaciones']);
    });

    // Variantes de producto (tela/color)
    Route::get('/variantes/telas',           [VarianteController::class, 'telas']);
    Route::get('/productos/{id}/variantes',  [VarianteController::class, 'index']);
    Route::middleware('role:supervisor,vendedor')->group(function () {
        Route::post('/productos/{id}/variantes', [VarianteController::class, 'store']);
    });

    // Inventario de telas físicas (metros)
    Route::get('/inventario-telas',                [InventarioTelaController::class, 'index']);
    Route::get('/inventario-telas/proveedores',    [InventarioTelaController::class, 'proveedores']);
    Route::get('/inventario-telas/validar',        [InventarioTelaController::class, 'validar']);
    Route::post('/inventario-telas/recargar',      [InventarioTelaController::class, 'recargar']);
    Route::middleware('role:costurero,supervisor')->group(function () {
        Route::post('/inventario-telas/descontar', [InventarioTelaController::class, 'descontar']);
    });

    // Catálogo de telas (marca → tipo → color)
    Route::get('/catalogo-telas', [CatalogoTelaController::class, 'index']);
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/catalogo-telas',              [CatalogoTelaController::class, 'store']);
        Route::post('/catalogo-telas/batch',        [CatalogoTelaController::class, 'storeBatch']);
        Route::delete('/catalogo-telas/{id}',       [CatalogoTelaController::class, 'destroy'])->whereNumber('id');
    });

    // Variante configs por producto (asignación de tipos a productos con precio)
    Route::get('/productos/{id}/variante-configs',       [ProductoVarianteConfigController::class, 'index'])->whereNumber('id');
    Route::get('/productos/{id}/variante-combinaciones', [ProductoVarianteConfigController::class, 'indexCombinaciones'])->whereNumber('id');
    Route::post('/inventario/variante-configs/entrada',        [ProductoVarianteConfigController::class, 'entrada']);
    Route::post('/inventario/variante-combinaciones/entrada',  [ProductoVarianteConfigController::class, 'entradaCombinacion']);
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/productos/{id}/variante-configs', [ProductoVarianteConfigController::class, 'upsert'])->whereNumber('id');
        Route::delete('/productos/{id}/variante-configs/tipo/{tipoId}', [ProductoVarianteConfigController::class, 'destroyTipo'])->whereNumber(['id', 'tipoId']);
    });

    // Tipos de variante configurables (Alerones, Color, Tipo de madera…)
    Route::get('/tipos-variante', [TipoVarianteController::class, 'index']);
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/tipos-variante',                          [TipoVarianteController::class, 'store']);
        Route::delete('/tipos-variante/{id}',                   [TipoVarianteController::class, 'destroy'])->whereNumber('id');
        Route::post('/tipos-variante/{id}/opciones',            [TipoVarianteController::class, 'storeOpciones'])->whereNumber('id');
        Route::delete('/tipos-variante/opciones/{id}',          [TipoVarianteController::class, 'destroyOpcion'])->whereNumber('id');
    });

    // Notificaciones (todos los roles, filtrado por rol en el controlador)
    Route::get('/notificaciones',              [NotificacionController::class, 'index']);
    Route::patch('/notificaciones/leer-todas', [NotificacionController::class, 'marcarTodas']);
    Route::patch('/notificaciones/{id}/leida', [NotificacionController::class, 'marcarLeida']);
    Route::delete('/notificaciones/todas',     [NotificacionController::class, 'eliminarTodas']);
    Route::delete('/notificaciones/{id}',      [NotificacionController::class, 'eliminar']);

    // Lista de asesores activos (para co-vendedor en ventas compartidas)
    Route::get('/asesores', [UsuarioController::class, 'asesores']);

    // Usuarios (solo supervisor)
    Route::middleware('role:supervisor')->group(function () {
        Route::get('/usuarios',                      [UsuarioController::class, 'index']);
        Route::get('/usuarios/{id}',                 [UsuarioController::class, 'show']);
        Route::post('/usuarios',                     [UsuarioController::class, 'store']);
        Route::put('/usuarios/{id}',                 [UsuarioController::class, 'update']);
        Route::patch('/usuarios/{id}/toggle-activo', [UsuarioController::class, 'toggleActivo']);
        Route::post('/usuarios/{id}/reset-password', [UsuarioController::class, 'resetPassword']);
    });

    // Producción — listado y gestión (supervisor y vendedor)
    Route::get('/produccion',        [ProduccionController::class, 'index']);
    Route::patch('/produccion/{id}', [ProduccionController::class, 'update'])->whereNumber('id');

    // Producción — flujo de pasos (ebanista y tapicero-supervisor)
    Route::get('/produccion/mis-pasos',                        [ProduccionController::class, 'misPasos']);
    Route::get('/produccion/historial-pasos',                  [ProduccionController::class, 'historialPasos']);
    Route::patch('/produccion/pasos/{id}/completar',           [ProduccionController::class, 'completarPaso'])->whereNumber('id');
    Route::patch('/produccion/pasos/{id}/devolver',            [ProduccionController::class, 'devolverPaso'])->whereNumber('id');

    // Producción — despacho de producción (despachador)
    Route::get('/produccion/pendientes-despacho',              [ProduccionController::class, 'pendientesDespacho']);
    Route::get('/produccion/historial-despacho',               [ProduccionController::class, 'historialDespacho']);
    Route::patch('/produccion/{id}/completar-despacho',        [ProduccionController::class, 'completarDespacho'])->whereNumber('id')->middleware('role:despachador,supervisor');
    Route::patch('/produccion/{id}/devolver-despacho',         [ProduccionController::class, 'devolverDesdeDespacho'])->whereNumber('id')->middleware('role:despachador,supervisor');

    // Stats — ambos roles (vendedor ve solo lo suyo, supervisor ve todo)
    Route::prefix('stats')->group(function () {
        Route::get('/panel',            [StatsController::class, 'panel']);
        Route::get('/tendencia',        [StatsController::class, 'tendencia']);
        Route::get('/productos',        [StatsController::class, 'productos']);
        Route::get('/categorias',       [StatsController::class, 'categorias']);
        Route::get('/cartera',          [StatsController::class, 'cartera']);
        Route::get('/vendedores/me',    [StatsController::class, 'statsMe']);
        Route::get('/conductor',        [StatsController::class, 'statsConductor']);

        // Solo supervisor
        Route::middleware('role:supervisor')->group(function () {
            Route::get('/tiendas',          [StatsController::class, 'tiendas']);
            Route::get('/vendedores',       [StatsController::class, 'vendedores']);
            Route::get('/vendedor/{id}',    [StatsController::class, 'statsVendedor']);
            Route::get('/conductores',      [StatsController::class, 'conductores']);
        });
    });

    // Reportes
    Route::prefix('reportes')->group(function () {
        Route::get('/retrasos', [ReporteController::class, 'retrasos']);
        Route::get('/exportar', [ReporteController::class, 'exportar']);

        Route::middleware('role:supervisor')->group(function () {
            Route::get('/ventas',                    [ReporteController::class, 'ventas']);
            Route::get('/vendedores',                [ReporteController::class, 'vendedores']);
            Route::get('/productos-top',             [ReporteController::class, 'productosTop']);
            Route::get('/pendientes',                [ReporteController::class, 'pendientes']);
            Route::get('/interesados',               [ReporteController::class, 'interesados']);
            Route::get('/canales',                   [ReporteController::class, 'canalVentas']);
            Route::get('/resumen-mensual',           [ReporteController::class, 'resumenMensual']);
            Route::get('/resumen-mensual/exportar',  [ReporteController::class, 'exportarResumenMensual']);
        });
    });

    // ── Camiones ─────────────────────────────────────────────────────────────
    Route::get('/camiones', [CamionController::class, 'index']);
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/camiones',             [CamionController::class, 'store']);
        Route::patch('/camiones/{camion}',   [CamionController::class, 'update']);
    });

    // ── Despacho ─────────────────────────────────────────────────────────────
    Route::prefix('despacho')->group(function () {
        // Público autenticado (supervisor, vendedor, conductor)
        Route::get('/por-orden/{ordenId}', [DespachoController::class, 'porOrden']);

        // Supervisor
        Route::middleware('role:supervisor')->group(function () {
            Route::get('/cola',          [DespachoController::class, 'cola']);
            Route::get('/asignados',     [DespachoController::class, 'asignados']);
            Route::post('/asignar',      [DespachoController::class, 'asignar']);
            Route::get('/conductores',   [DespachoController::class, 'conductores']);
            Route::get('/historial',     [DespachoController::class, 'historial']);
            // Rutas (borradores)
            Route::patch('/{id}/reprogramar',                   [DespachoController::class, 'reprogramarRuta'])->whereNumber('id');
            Route::get('/rutas',                                [DespachoController::class, 'rutas']);
            Route::post('/rutas',                               [DespachoController::class, 'crearRuta']);
            Route::patch('/rutas/{id}',                         [DespachoController::class, 'actualizarRuta'])->whereNumber('id');
            Route::delete('/rutas/{id}',                        [DespachoController::class, 'eliminarRuta'])->whereNumber('id');
            Route::post('/rutas/{id}/ordenes',                  [DespachoController::class, 'agregarOrdenARuta'])->whereNumber('id');
            Route::delete('/rutas/{id}/ordenes/{itemId}',       [DespachoController::class, 'quitarOrdenDeRuta'])->whereNumber('id')->whereNumber('itemId');
            Route::patch('/rutas/{id}/reordenar',               [DespachoController::class, 'reordenarRuta'])->whereNumber('id');
            Route::patch('/rutas/{id}/enviar',                  [DespachoController::class, 'enviarRuta'])->whereNumber('id');
            Route::get('/{id}',          [DespachoController::class, 'show'])->whereNumber('id');
        });

        // Conductor (autenticado)
        Route::patch('/mis-entregas/rutas/{despachoId}/iniciar', [DespachoController::class, 'iniciarRuta'])->whereNumber('despachoId');
        Route::get('/mis-entregas',                          [DespachoController::class, 'misEntregas']);
        Route::get('/mis-entregas/historial',                [DespachoController::class, 'misHistorial']);
        Route::get('/mis-entregas/{despachoItemId}',         [DespachoController::class, 'showEntrega']);
        Route::post('/mis-entregas/{despachoItemId}/pago',   [DespachoController::class, 'registrarPago']);
        Route::patch('/mis-entregas/{despachoItemId}/entregar', [DespachoController::class, 'entregar']);
    });

    // Materiales (catálogo maestro)
    Route::get('/materiales',               [MaterialController::class, 'index']);
    Route::get('/materiales/{material}/usos', [MaterialController::class, 'usos']);
    Route::middleware('role:supervisor,ebanista')->group(function () {
        Route::post('/materiales',             [MaterialController::class, 'store']);
        Route::patch('/materiales/{material}', [MaterialController::class, 'update']);
    });
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/materiales/importar',      [MaterialController::class, 'importar']);
        Route::delete('/materiales/{material}',  [MaterialController::class, 'destroy']);
    });

    // Facturación (vendedores con facturacion=true)
    Route::get('/facturacion/ordenes', [FacturacionController::class, 'ordenes']);

    // Agente de IA
    Route::post('/agent/chat',           [AgentController::class,   'chat'])->middleware('throttle:30,1');
    Route::post('/calcular-precio-item', [PrecioItemController::class, 'calcular'])->middleware('throttle:20,1');

    // Consultas de costo (cotizaciones para productos personalizados)
    Route::get('/consultas-costo/receptores',                    [ConsultaCostoController::class, 'receptores']);
    Route::get('/consultas-costo',                               [ConsultaCostoController::class, 'index']);
    Route::post('/consultas-costo',                              [ConsultaCostoController::class, 'store']);
    Route::get('/consultas-costo/{id}',                          [ConsultaCostoController::class, 'show'])->whereNumber('id');
    Route::put('/consultas-costo/{id}/items/{itemId}',           [ConsultaCostoController::class, 'guardarItem'])->whereNumber('id')->whereNumber('itemId');
    Route::post('/consultas-costo/{id}/enviar',                  [ConsultaCostoController::class, 'enviar'])->whereNumber('id');
    Route::patch('/consultas-costo/{id}/ajustar-precio',         [ConsultaCostoController::class, 'ajustarPrecio'])->whereNumber('id');
    Route::get('/consultas-costo/{id}/mensajes',                 [ConsultaCostoController::class, 'mensajes'])->whereNumber('id');
    Route::post('/consultas-costo/{id}/mensajes',                [ConsultaCostoController::class, 'enviarMensaje'])->whereNumber('id');

    // Configuración de costos — supervisor y ebanista
    Route::middleware('role:supervisor,ebanista')->group(function () {
        Route::get('/configuracion/costos',                      [ConfiguracionCostosController::class, 'index']);
        Route::put('/configuracion/costos',                      [ConfiguracionCostosController::class, 'guardar']);
        Route::post('/configuracion/costos/cargos',              [ConfiguracionCostosController::class, 'crearCargo']);
        Route::delete('/configuracion/costos/cargos/{cargo}',    [ConfiguracionCostosController::class, 'eliminarCargo']);
        Route::post('/configuracion/costos/procesos',            [ConfiguracionCostosController::class, 'crearProceso']);
        Route::delete('/configuracion/costos/procesos/{id}',     [ConfiguracionCostosController::class, 'eliminarProceso']);
    });

    // Comisiones
    Route::prefix('comisiones')->group(function () {
        Route::get('/',                    [ComisionController::class, 'index']);
        Route::get('/vendedores',          [ComisionController::class, 'vendedores']);
        Route::get('/metas',               [ComisionController::class, 'getMetas']);
        Route::post('/metas',              [ComisionController::class, 'setMeta'])->middleware('role:supervisor');
        Route::post('/recalcular',         [ComisionController::class, 'recalcular'])->middleware('role:supervisor');
        Route::post('/{id}/pagar',         [ComisionController::class, 'marcarPagada'])->middleware('role:supervisor');
    });

    // Redes (módulo WhatsApp centralizado)
    Route::get('/redes/conversaciones',                       [RedesController::class, 'index']);
    Route::post('/redes/conversaciones/{id}/tomar',           [RedesController::class, 'tomar']);
    Route::post('/redes/conversaciones/{id}/terminar',        [RedesController::class, 'terminar']);
    Route::delete('/redes/conversaciones/terminadas',         [RedesController::class, 'limpiarTerminadas'])->middleware('role:supervisor');

    // Citas
    Route::get('/citas',          [CitaController::class, 'index']);
    Route::post('/citas',         [CitaController::class, 'store']);
    Route::patch('/citas/{id}',   [CitaController::class, 'update']);

    // Caja de tienda
    Route::prefix('caja')->group(function () {
        Route::get('/balance',     [CajaController::class, 'balance']);
        Route::get('/movimientos', [CajaController::class, 'movimientos']);
        Route::post('/movimiento', [CajaController::class, 'registrarMovimiento']);
        Route::middleware('role:supervisor')->group(function () {
            Route::get('/resumen-tiendas',        [CajaController::class, 'resumenTiendas']);
            Route::delete('/movimiento/{id}',     [CajaController::class, 'eliminarMovimiento'])->whereNumber('id');
        });
    });

    // Fichas Técnicas (costos de producción)
    Route::get('/fichas-tecnicas',                        [FichaTecnicaController::class, 'index']);
    Route::get('/fichas-tecnicas/materiales-sugeridos',   [FichaTecnicaController::class, 'materialesSugeridos']);
    Route::get('/fichas-tecnicas/{fichaTecnica}',         [FichaTecnicaController::class, 'show']);
    Route::middleware('role:supervisor,ebanista')->group(function () {
        Route::post('/fichas-tecnicas',                          [FichaTecnicaController::class, 'store']);
        Route::patch('/fichas-tecnicas/{fichaTecnica}/items',    [FichaTecnicaController::class, 'updateItems']);
    });
    Route::middleware('role:supervisor')->group(function () {
        Route::post('/fichas-tecnicas/reimportar',               [FichaTecnicaController::class, 'reimportar']);
    });
});
