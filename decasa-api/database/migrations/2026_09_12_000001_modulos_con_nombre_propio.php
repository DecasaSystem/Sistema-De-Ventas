<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se llama cada módulo en ESTA empresa.
 *
 * El programa hace siempre lo mismo —vender, surtir, trasladar, producir— pero
 * cada negocio le dice distinto: donde una mueblería tiene "Telas", una fábrica
 * de ropa tiene "Insumos" y una de tecnología no tiene nada. Hasta ahora los
 * nombres y los iconos estaban escritos dentro de las pantallas, así que
 * cambiarlos era tocar código.
 *
 * La `clave` es la que manda y no se toca nunca: es lo que el código busca. El
 * nombre y el icono son de la empresa. `visible` permite apagar un módulo que
 * ese negocio no usa sin tener que quitarle el permiso a nadie.
 *
 * Se siembra con exactamente lo que hoy dicen las pantallas, para que el día
 * del cambio nadie note nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modulos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('nombre', 60);
            $table->string('icono', 60);
            $table->boolean('visible')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        $ahora = now();
        $filas = [];
        $orden = 0;

        foreach (self::SEMILLA as [$clave, $nombre, $icono]) {
            $filas[] = [
                'clave'      => $clave,
                'nombre'     => $nombre,
                'icono'      => $icono,
                'visible'    => true,
                'orden'      => $orden += 10,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        DB::table('modulos')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('modulos');
    }

    /** Lo que hoy dicen las pantallas, tal cual. */
    private const SEMILLA = [
        ['dashboard',           'Inicio',           'HomeIcon'],
        ['nueva-orden',         'Nueva orden',      'PlusIcon'],
        ['ordenes',             'Órdenes',          'ClipboardDocumentListIcon'],
        ['clientes',            'Clientes',         'UserGroupIcon'],
        ['inventario',          'Inventario',       'ArchiveBoxIcon'],
        ['telas',               'Telas',            'SwatchIcon'],
        ['fabrica',             'Fábrica',          'BuildingOffice2Icon'],
        ['reserva',             'Reserva',          'CubeIcon'],
        ['surtir',              'Surtir',           'ArchiveBoxArrowDownIcon'],
        ['traslado',            'Traslado',         'ArrowPathIcon'],
        ['redes',               'Redes',            'ChatBubbleLeftRightIcon'],
        ['costos',              'Costos',           'CalculatorIcon'],
        ['mis-pasos',           'Mis pasos',        'ClipboardDocumentCheckIcon'],
        ['citas',               'Citas',            'CalendarDaysIcon'],
        ['caja',                'Caja',             'BanknotesIcon'],
        ['produccion',          'Producción',       'WrenchScrewdriverIcon'],
        ['cotizaciones',        'Cotizaciones',     'DocumentTextIcon'],
        ['consultas',           'Consultar costo',  'CurrencyDollarIcon'],
        ['mis-stats',           'Estadísticas',     'PresentationChartLineIcon'],
        ['proveedores',         'Proveedores',      'BuildingStorefrontIcon'],
        ['compras',             'Compras',          'ShoppingCartIcon'],
        ['encargos',            'Encargos',         'BriefcaseIcon'],
        ['mis-encargos',        'Mis encargos',     'BriefcaseIcon'],
        ['facturacion',         'Facturación',      'DocumentCurrencyDollarIcon'],
        ['herramientas',        'Herramientas',     'WrenchScrewdriverIcon'],
        ['mis-entregas',        'Mis entregas',     'TruckIcon'],
        ['usuarios',            'Trabajadores',     'UsersIcon'],
        ['reportes',            'Reportes',         'ChartBarIcon'],
        ['gestion',             'Gestión',          'Cog6ToothIcon'],
        ['despacho',            'Despacho',         'TruckIcon'],
        ['metricas-redes',      'Métricas',         'ChartPieIcon'],
        ['comisiones',          'Comisiones',       'ReceiptPercentIcon'],
        ['nomina',              'Nómina',           'BanknotesIcon'],
    ];
};
