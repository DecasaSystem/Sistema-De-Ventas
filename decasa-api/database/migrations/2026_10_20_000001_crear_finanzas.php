<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El módulo de Finanzas (docs/plan-gestion-financiera.md): lo que entra y lo
 * que sale de la empresa cada mes.
 *
 * Ventas, nómina y comisiones ya existen y se leen de sus módulos; aquí solo
 * nace lo que no existía en ningún lado: los gastos.
 *
 * - `categorias_gasto`: de qué es cada gasto (arriendo, energía, software…).
 *   Editables; se desactivan, no se borran.
 * - `gastos_recurrentes`: las plantillas de lo que se repite (el internet del
 *   día 5, el arriendo, la licencia anual). Sus obligaciones de cada periodo NO
 *   se guardan: se calculan del calendario, como los ciclos de nómina. Solo
 *   cuando se paga queda una fila en `gastos`.
 * - `gastos`: lo que se pagó (o se omitió ese periodo). Un error se anula con
 *   motivo, no se borra.
 * - `gastos_bitacora`: quién cambió qué.
 * - `presupuestos_gasto`: cuánto se piensa gastar por categoría y mes.
 *
 * Y el permiso `acceso_finanzas` (además de ser supervisor): la utilidad de la
 * empresa es lo más sensible del sistema. Arranca prendido a los supervisores
 * de hoy; el dueño se lo quita a quien no deba verlo.
 *
 * Solo crea tablas y agrega columnas: nada de lo que ya existe cambia.
 */
return new class extends Migration
{
    /** nombre, grupo, naturaleza, varia_con_ventas, área del P&G, icono */
    private const CATEGORIAS = [
        ['Arriendo',                  'locales',        'fijo',     false, 'administracion', 'HomeModernIcon'],
        ['Administración y vigilancia', 'locales',      'fijo',     false, 'administracion', 'ShieldCheckIcon'],
        ['Seguros',                   'locales',        'fijo',     false, 'administracion', 'ShieldCheckIcon'],
        ['Energía',                   'servicios',      'variable', false, 'administracion', 'BoltIcon'],
        ['Agua',                      'servicios',      'variable', false, 'administracion', 'BeakerIcon'],
        ['Gas',                       'servicios',      'variable', false, 'administracion', 'FireIcon'],
        ['Internet y telefonía',      'servicios',      'fijo',     false, 'administracion', 'WifiIcon'],
        ['Software y licencias',      'tecnologia',     'fijo',     false, 'administracion', 'ComputerDesktopIcon'],
        ['Servidores y nube',         'tecnologia',     'fijo',     false, 'administracion', 'CloudIcon'],
        ['Publicidad y redes',        'ventas',         'variable', true,  'ventas',         'MegaphoneIcon'],
        ['Fletes y domicilios',       'ventas',         'variable', true,  'ventas',         'TruckIcon'],
        ['Insumos de taller',         'produccion',     'variable', true,  'produccion',     'WrenchScrewdriverIcon'],
        ['Mantenimiento',             'produccion',     'variable', false, 'produccion',     'Cog6ToothIcon'],
        ['Herramientas',              'produccion',     'variable', false, 'produccion',     'WrenchIcon'],
        ['Honorarios (contador, abogado)', 'administracion', 'fijo', false, 'administracion', 'BriefcaseIcon'],
        ['Papelería y aseo',          'administracion', 'variable', false, 'administracion', 'ClipboardDocumentListIcon'],
        ['Bancarios',                 'financiero',     'variable', false, 'financiero',     'BuildingLibraryIcon'],
        ['Impuestos',                 'impuestos',      'fijo',     false, 'administracion', 'ScaleIcon'],
        ['Otros',                     'administracion', 'variable', false, 'administracion', 'EllipsisHorizontalCircleIcon'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('categorias_gasto')) {
            Schema::create('categorias_gasto', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 80)->unique();
                $t->string('grupo', 30)->default('administracion');
                // Lo que el negocio entiende: fijo = mismo monto cada vez.
                $t->string('naturaleza', 10)->default('variable');
                // Lo que pide el punto de equilibrio: ¿sube cuando se vende más?
                $t->boolean('varia_con_ventas')->default(false);
                // En qué línea del estado de resultados cae.
                $t->string('area', 20)->default('administracion');
                $t->string('codigo_puc', 12)->nullable();
                $t->string('icono', 60)->nullable();
                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('gastos_recurrentes')) {
            Schema::create('gastos_recurrentes', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 120);
                $t->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
                $t->foreignId('tienda_id')->nullable()->constrained('tiendas');      // null = general
                $t->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
                $t->decimal('monto', 15, 2);
                // Luz, agua: el monto cambia; se sugiere el promedio de los últimos pagos.
                $t->boolean('monto_estimado')->default(false);
                $t->string('frecuencia', 12)->default('mensual');
                $t->unsignedTinyInteger('dia_pago')->nullable();   // 31 = último día
                $t->date('desde');
                $t->date('hasta')->nullable();
                // La licencia anual se reparte en los meses que cubre (P&G).
                $t->boolean('prorratear')->default(false);
                $t->string('metodo_pago', 20)->nullable();
                $t->unsignedTinyInteger('avisar_dias_antes')->default(3);
                $t->text('notas')->nullable();
                $t->boolean('activo')->default(true);
                $t->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamps();
            });
        }

        // Facturas de proveedores a crédito (30/60 días): el gasto es del mes de
        // la factura; la plata sale cuando se paga (uno o varios abonos, que
        // son `gastos` con `cuenta_por_pagar_id`).
        if (! Schema::hasTable('cuentas_por_pagar')) {
            Schema::create('cuentas_por_pagar', function (Blueprint $t) {
                $t->id();
                $t->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
                $t->string('proveedor_nombre', 120)->nullable();
                $t->string('concepto', 160);
                $t->string('numero_factura', 60)->nullable();
                $t->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
                $t->foreignId('tienda_id')->nullable()->constrained('tiendas');
                $t->decimal('monto', 15, 2);
                $t->date('fecha_factura');
                $t->date('fecha_vencimiento');
                $t->string('estado', 10)->default('pendiente');   // pendiente | pagada | anulada
                $t->json('comprobante_fotos')->nullable();
                $t->text('notas')->nullable();
                $t->string('motivo_anulacion', 200)->nullable();
                $t->foreignId('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamps();
                $t->index(['estado', 'fecha_vencimiento']);
            });
        }

        // El cierre de un mes ya revisado: congela su estado de resultados. Lo
        // de después (un gasto que llegó tarde) no lo mueve; para cambiarlo se
        // reabre, con motivo.
        if (! Schema::hasTable('cierres_financieros')) {
            Schema::create('cierres_financieros', function (Blueprint $t) {
                $t->id();
                $t->char('mes', 7)->unique();
                $t->json('snapshot');
                $t->string('estado', 10)->default('cerrado');      // cerrado | reabierto
                $t->foreignId('cerrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamp('cerrado_at')->nullable();
                $t->foreignId('reabierto_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamp('reabierto_at')->nullable();
                $t->string('motivo_reapertura', 200)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('gastos')) {
            Schema::create('gastos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
                $t->foreignId('gasto_recurrente_id')->nullable()->constrained('gastos_recurrentes')->nullOnDelete();
                // El periodo de la plantilla que paga ('2026-10-05'). NUNCA va
                // NULL en un gasto de plantilla: en MySQL dos NULL no chocan en
                // el índice único (la trampa de wa_seguimientos, MEMORY.md §5b).
                // Al anular se le agrega "#id" para soltar el periodo.
                $t->string('periodo', 30)->nullable();
                $t->foreignId('tienda_id')->nullable()->constrained('tiendas');
                $t->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
                // El abono a una factura de proveedor: es caja, no otro gasto
                // (el gasto ya contó el mes de la factura).
                $t->foreignId('cuenta_por_pagar_id')->nullable()->constrained('cuentas_por_pagar')->nullOnDelete();
                // De qué canal es (la publicidad de Instagram, de WhatsApp…):
                // para la rentabilidad por canal.
                $t->string('canal', 20)->nullable();
                $t->string('concepto', 160);
                $t->decimal('monto', 15, 2);
                $t->string('estado', 10)->default('pagado');     // pagado | omitido | anulado
                $t->date('fecha_pago');                          // caja
                $t->date('cubre_desde');                         // P&G: a qué meses pertenece
                $t->date('cubre_hasta');
                $t->string('metodo_pago', 20)->nullable();
                $t->string('comprobante_url', 500)->nullable();
                $t->json('comprobante_fotos')->nullable();
                $t->text('notas')->nullable();
                $t->string('motivo_anulacion', 200)->nullable();
                $t->foreignId('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->foreignId('anulado_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamp('anulado_at')->nullable();
                $t->timestamps();

                $t->index('fecha_pago');
                $t->index(['cubre_desde', 'cubre_hasta']);
                $t->unique(['gasto_recurrente_id', 'periodo']);
            });
        }

        if (! Schema::hasTable('gastos_bitacora')) {
            Schema::create('gastos_bitacora', function (Blueprint $t) {
                $t->id();
                $t->string('entidad', 20);
                $t->unsignedBigInteger('entidad_id');
                $t->string('accion', 20);
                $t->json('antes')->nullable();
                $t->json('despues')->nullable();
                $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['entidad', 'entidad_id']);
            });
        }

        if (! Schema::hasTable('presupuestos_gasto')) {
            Schema::create('presupuestos_gasto', function (Blueprint $t) {
                $t->id();
                $t->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
                $t->char('mes', 7);                 // 'YYYY-MM'
                $t->decimal('monto', 15, 2);
                $t->timestamps();
                $t->unique(['categoria_gasto_id', 'mes']);
            });
        }

        if (! Schema::hasColumn('usuarios', 'acceso_finanzas')) {
            Schema::table('usuarios', function (Blueprint $t) {
                $t->boolean('acceso_finanzas')->default(false);
            });
            DB::table('usuarios')->where('rol', 'supervisor')->update(['acceso_finanzas' => true]);
        }

        $ahora = now();
        if (! DB::table('categorias_gasto')->exists()) {
            foreach (self::CATEGORIAS as $i => [$nombre, $grupo, $naturaleza, $varia, $area, $icono]) {
                DB::table('categorias_gasto')->insert([
                    'nombre' => $nombre, 'grupo' => $grupo, 'naturaleza' => $naturaleza,
                    'varia_con_ventas' => $varia, 'area' => $area, 'icono' => $icono,
                    'orden' => $i + 1, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }

        if (Schema::hasTable('modulos') && ! DB::table('modulos')->where('clave', 'finanzas')->exists()) {
            DB::table('modulos')->insert([
                'clave' => 'finanzas', 'nombre' => 'Finanzas', 'icono' => 'PresentationChartBarIcon',
                'visible' => true, 'orden' => (int) DB::table('modulos')->max('orden') + 1,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('modulos')) {
            DB::table('modulos')->where('clave', 'finanzas')->delete();
        }
        if (Schema::hasColumn('usuarios', 'acceso_finanzas')) {
            Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('acceso_finanzas'));
        }
        Schema::dropIfExists('presupuestos_gasto');
        Schema::dropIfExists('gastos_bitacora');
        Schema::dropIfExists('gastos');
        Schema::dropIfExists('cierres_financieros');
        Schema::dropIfExists('cuentas_por_pagar');
        Schema::dropIfExists('gastos_recurrentes');
        Schema::dropIfExists('categorias_gasto');
    }
};
