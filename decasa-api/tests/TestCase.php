<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Lo que el módulo de comisiones guarda en memoria para no preguntarlo
     * varias veces por petición: los equipos, los reemplazos y qué tiendas
     * reparten.
     *
     * Son estáticos y el proceso de las pruebas es uno solo, así que sin esto
     * una prueba se queda con la foto de la anterior. Va aquí y no en cada
     * `setUp()` porque es la clase de fallo que aparece cuando a alguien se le
     * olvida, y entonces falla una prueba que no tiene nada que ver.
     *
     * Quien cambie el escenario a mitad de prueba sí tiene que volver a
     * limpiarlo a mano.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\TiendaAsesor::olvidarCache();
        \App\Models\TiendaReemplazo::olvidarCache();
        \App\Http\Controllers\ComisionController::olvidarQuienComparte();
        \App\Services\ConsumoTelas::olvidarCache();
    }

    /**
     * Le completa al esquema armado a mano lo que trajo "entregas por producto".
     *
     * Las pruebas montan sus tablas a mano y la mayoría lo hizo antes de que
     * existieran `entrega_lineas`, `cantidad_entregada`, `llevar_ahora` y las
     * listas de fotos de comprobante. Como crear una orden o pagarla ya
     * escribe esas columnas, sin ellas cualquier prueba vieja revienta. Esto
     * las agrega si faltan y no toca nada que ya esté.
     *
     * Se llama desde el `setUp()` de la prueba, después de crear sus tablas.
     */
    protected function completarEsquemaDeEntregas(): void
    {
        $agregar = function (string $tabla, string $columna, callable $def) {
            if (\Illuminate\Support\Facades\Schema::hasTable($tabla)
                && ! \Illuminate\Support\Facades\Schema::hasColumn($tabla, $columna)) {
                \Illuminate\Support\Facades\Schema::table($tabla, fn ($t) => $def($t));
            }
        };

        $agregar('ordenes',        'factura_fotos',      fn ($t) => $t->json('factura_fotos')->nullable());
        $agregar('orden_items',    'cantidad_entregada', fn ($t) => $t->unsignedInteger('cantidad_entregada')->default(0));
        $agregar('orden_items',    'llevar_ahora',       fn ($t) => $t->boolean('llevar_ahora')->default(false));
        $agregar('orden_items',    'devuelto_en',        fn ($t) => $t->date('devuelto_en')->nullable());
        $agregar('orden_items',    'motivo_devolucion',  fn ($t) => $t->text('motivo_devolucion')->nullable());
        $agregar('ordenes',        'descuento_total',    fn ($t) => $t->decimal('descuento_total', 15, 2)->default(0));
        $agregar('ordenes',        'descuento_condicionado', fn ($t) => $t->decimal('descuento_condicionado', 15, 2)->default(0));
        $agregar('ordenes',        'tienda_abonada_id',  fn ($t) => $t->unsignedBigInteger('tienda_abonada_id')->nullable());
        $agregar('ordenes',        'covendedor_id',      fn ($t) => $t->unsignedBigInteger('covendedor_id')->nullable());
        $agregar('orden_items',    'producto_unico',     fn ($t) => $t->boolean('producto_unico')->default(false));
        $agregar('pagos',          'comprobante_url',    fn ($t) => $t->string('comprobante_url')->nullable());
        $agregar('pagos',          'comprobante_fotos',  fn ($t) => $t->json('comprobante_fotos')->nullable());
        $agregar('despacho_items', 'fotos_pago',         fn ($t) => $t->json('fotos_pago')->nullable());
        $agregar('devoluciones',   'preferencia_cliente', fn ($t) => $t->string('preferencia_cliente')->nullable());
        $agregar('despacho_items', 'firma_omitida_motivo', fn ($t) => $t->string('firma_omitida_motivo')->nullable());

        // Vender con "se lo lleva ahora" abre una entrega de mostrador, así
        // que hasta una prueba que solo crea órdenes necesita dónde escribirla.
        if (! \Illuminate\Support\Facades\Schema::hasTable('despachos')) {
            \Illuminate\Support\Facades\Schema::create('despachos', function ($t) {
                $t->id(); $t->unsignedBigInteger('camion_id')->nullable(); $t->unsignedBigInteger('conductor_id')->nullable();
                $t->unsignedBigInteger('entregado_por_id')->nullable(); $t->unsignedBigInteger('supervisor_id')->nullable();
                $t->date('fecha_despacho')->nullable(); $t->string('estado')->default('en_ruta');
                $t->string('tipo')->default('ruta'); $t->text('notas')->nullable();
                $t->string('nombre_ruta')->nullable(); $t->text('instrucciones')->nullable(); $t->timestamps();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('despacho_items')) {
            \Illuminate\Support\Facades\Schema::create('despacho_items', function ($t) {
                $t->id(); $t->unsignedBigInteger('despacho_id'); $t->unsignedBigInteger('orden_id');
                $t->unsignedInteger('posicion')->default(1); $t->string('estado')->default('pendiente');
                $t->string('foto_producto')->nullable(); $t->string('foto_pago')->nullable(); $t->json('fotos_pago')->nullable();
                $t->string('firma_recibido_url')->nullable(); $t->string('recibido_por_nombre')->nullable();
                $t->string('recibido_por_cedula')->nullable(); $t->boolean('conforme')->nullable();
                $t->string('observaciones_entrega')->nullable(); $t->string('foto_novedad_url')->nullable();
                $t->string('firma_omitida_motivo')->nullable(); $t->timestamp('entregado_at')->nullable();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('inventario_movimientos')) {
            \Illuminate\Support\Facades\Schema::create('inventario_movimientos', function ($t) {
                $t->id(); $t->unsignedBigInteger('producto_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
                $t->string('tipo'); $t->integer('cantidad')->default(0); $t->string('motivo')->nullable();
                $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
            });
        }
        // Al bajar stock se cuadran las variantes (StockVariantes): mira estas
        // tablas aunque el producto no tenga ninguna.
        $tablasVariantes = [
            'producto_variantes' => function ($t) {
                $t->id(); $t->unsignedBigInteger('producto_id'); $t->string('marca')->nullable();
                $t->string('marca_tela')->nullable(); $t->string('nombre_color')->nullable(); $t->string('medida')->nullable();
            },
            'inventario_variantes' => function ($t) {
                $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('tienda_id');
                $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            },
            'producto_variante_configs' => function ($t) {
                $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tipo_id')->nullable();
                $t->unsignedBigInteger('opcion_id')->nullable();
            },
            'inventario_variante_configs' => function ($t) {
                $t->id(); $t->unsignedBigInteger('config_id'); $t->unsignedBigInteger('tienda_id');
                $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            },
            'inventario_variante_combinaciones' => function ($t) {
                $t->id(); $t->unsignedBigInteger('variante_id'); $t->unsignedBigInteger('config_id');
                $t->unsignedBigInteger('tienda_id');
                $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            },
        ];
        foreach ($tablasVariantes as $tabla => $def) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($tabla)) {
                \Illuminate\Support\Facades\Schema::create($tabla, $def);
            }
        }

        // Cambiar un producto recalcula la comisión de la orden: mira estas dos.
        if (! \Illuminate\Support\Facades\Schema::hasTable('comisiones')) {
            \Illuminate\Support\Facades\Schema::create('comisiones', function ($t) {
                $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
                $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta'); $t->char('mes_venta', 7);
                $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
                $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
                $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamp('fecha_pago')->nullable();
                $t->unsignedBigInteger('pagada_por')->nullable(); $t->boolean('notificado_lista')->default(false);
                $t->timestamps();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('pagos')) {
            \Illuminate\Support\Facades\Schema::create('pagos', function ($t) {
                $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
                $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('tipo')->nullable();
                $t->decimal('monto', 15, 2)->default(0); $t->string('metodo')->nullable();
                $t->string('referencia')->nullable(); $t->text('notas')->nullable();
                $t->string('comprobante_url')->nullable(); $t->json('comprobante_fotos')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('entrega_lineas')) {
            \Illuminate\Support\Facades\Schema::create('entrega_lineas', function ($t) {
                $t->id(); $t->unsignedBigInteger('despacho_item_id'); $t->unsignedBigInteger('orden_item_id');
                $t->unsignedInteger('cantidad'); $t->string('resultado')->default('entregado');
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('produccion')) {
            \Illuminate\Support\Facades\Schema::create('produccion', function ($t) {
                $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->string('estado')->default('pendiente');
                $t->date('fecha_inicio')->nullable(); $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
                $t->text('motivo_retraso')->nullable(); $t->unsignedBigInteger('despachado_por')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('devoluciones')) {
            \Illuminate\Support\Facades\Schema::create('devoluciones', function ($t) {
                $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('orden_item_id');
                $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->unsignedInteger('cantidad')->default(1);
                $t->text('motivo'); $t->string('foto_url')->nullable(); $t->date('fecha');
                $t->unsignedBigInteger('reportado_por_id')->nullable(); $t->string('estado')->default('pendiente');
                $t->unsignedBigInteger('decidido_por_id')->nullable(); $t->timestamp('decidido_at')->nullable();
                $t->text('notas_decision')->nullable(); $t->decimal('monto_devuelto', 12, 2)->nullable();
                $t->unsignedBigInteger('caja_movimiento_id')->nullable(); $t->timestamps();
            });
        }
    }

    /**
     * Le presta a SQLite las funciones de fecha de MySQL.
     *
     * Varias consultas del módulo de comisiones agrupan por mes con
     * CONVERT_TZ y DATE_FORMAT —lo que un independiente le abona a un almacén,
     * por ejemplo—, y SQLite no las tiene. Sin esto no se puede probar nada
     * que toque la meta de una tienda.
     *
     * Se registran en la conexión en vez de esquivar la consulta: lo que se
     * quiere comprobar es la cuenta de verdad, no una parecida.
     *
     * Se llama desde el `setUp()` de la prueba que lo necesite, después de
     * montar el esquema.
     */
    protected function prestarleASqliteLoQueEsDeMysql(): void
    {
        $pdo = DB::connection()->getPdo();

        $pdo->sqliteCreateFunction('CONVERT_TZ', function ($fecha, $desde, $hasta) {
            if ($fecha === null) return null;
            $horas = (int) substr($hasta, 0, 3) - (int) substr($desde, 0, 3);

            return \Carbon\Carbon::parse($fecha)->addHours($horas)->format('Y-m-d H:i:s');
        }, 3);

        $pdo->sqliteCreateFunction('DATE_FORMAT', function ($fecha, $formato) {
            if ($fecha === null) return null;

            return \Carbon\Carbon::parse($fecha)->format(
                str_replace(['%Y', '%m', '%d'], ['Y', 'm', 'd'], $formato)
            );
        }, 2);
    }
}
