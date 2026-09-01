<?php

namespace Tests\Feature;

use App\Models\Comision;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Nadie cobra su parte del pool dos veces.
 *
 * El renglón de "no vendió este mes" se abre en cuanto alguien mira la
 * pantalla de comisiones, cuando el mes va empezando y todavía no hay ventas.
 * Si esa persona vende después, ese renglón se quedaba ahí: cobraba la parte
 * que le toca por sus órdenes MÁS la de no haber vendido.
 *
 * Pasó de verdad: en agosto de 2026 Marta salió con $1.144.628 cuando le
 * correspondían $583.564 —$561.064 de más—, y por eso aparecía cobrando el
 * doble que Paola, que había vendido tres veces más que ella.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class ParteDePoolDuplicadaTest extends TestCase
{
    private const MES = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('acceso_comisiones')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('activa')->default(true);
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1);
            $t->timestamps();
        });
        Schema::create('tienda_asesores_comision', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->unsignedBigInteger('vendedor_id'); $t->timestamps();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->unsignedBigInteger('usuario_id');
            $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable();
            $t->string('nota')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            // Con su valor por defecto de verdad: una comisión normal nace
            // como 'venta', no en null, y de eso depende quién queda fuera de
            // los filtros por origen.
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamp('fecha_pago')->nullable(); $t->unsignedBigInteger('pagada_por')->nullable();
            $t->boolean('notificado_lista')->default(false); $t->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable(); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->boolean('es_compartida')->default(false);
            $t->string('estado')->default('entregado'); $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->timestamp('confirmada_en')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->boolean('es_restauracion')->default(false);
            $t->decimal('precio_unitario', 15, 2)->default(0); $t->integer('cantidad')->default(1);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->decimal('monto', 15, 2)->default(0);
            $t->string('metodo')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte', 'activa' => true]);
        DB::table('metas_tienda')->insert([
            'tienda_id' => 1, 'mes' => self::MES, 'meta' => 40000000, 'divisor_asesores' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[1, 'Paola'], [2, 'Marta'], [3, 'NN']] as [$id, $nombre]) {
            DB::table('usuarios')->insert([
                'id' => $id, 'nombre' => $nombre, 'rol' => 'vendedor',
                'tienda_default_id' => 1, 'created_at' => now(),
            ]);
            DB::table('tienda_asesores_comision')->insert([
                'tienda_id' => 1, 'mes' => self::MES, 'vendedor_id' => $id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Una venta suya, de las que pasan por el pool. */
    private function venta(int $vendedorId, float $valor): void
    {
        $ordenId = DB::table('ordenes')->insertGetId([
            'tienda_id' => 1, 'vendedor_id' => $vendedorId, 'estado' => 'entregado',
            'valor_total' => $valor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orden_items')->insert(['orden_id' => $ordenId, 'es_restauracion' => false, 'precio_unitario' => $valor]);
        DB::table('pagos')->insert(['orden_id' => $ordenId, 'monto' => $valor, 'created_at' => now()]);

        Comision::create([
            'orden_id' => $ordenId, 'vendedor_id' => $vendedorId, 'tienda_id' => 1,
            'mes_venta' => self::MES, 'valor_orden' => $valor,
            'fecha_venta' => self::MES . '-15', 'fecha_disponible' => '2026-09-20',
            'estado' => 'pendiente',
        ]);
    }

    /** El renglón que se abre a quien no ha vendido nada todavía. */
    private function renglonSinVentas(int $vendedorId): Comision
    {
        return Comision::create([
            'orden_id' => null, 'vendedor_id' => $vendedorId, 'tienda_id' => 1,
            'origen' => 'parte_pool', 'mes_venta' => self::MES, 'valor_orden' => 0,
            'fecha_venta' => self::MES . '-31', 'fecha_disponible' => '2026-09-20',
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Lo que corre al abrir la pantalla de comisiones.
     *
     * Se llama al método directamente y no por HTTP porque el endpoint pasa
     * por una consulta con CONVERT_TZ, que es de MySQL y SQLite no tiene. Lo
     * que se quiere probar —quién conserva su renglón y quién no— vive aquí.
     */
    private function abrirPantalla(): void
    {
        $controlador = app(\App\Http\Controllers\ComisionController::class);
        $metodo = new \ReflectionMethod($controlador, 'asegurarPartesDePool');
        $metodo->setAccessible(true);
        $metodo->invoke($controlador, self::MES);
    }

    public function test_quien_vendio_despues_pierde_el_renglon_de_no_vendio(): void
    {
        // Principio de mes: nadie ha vendido, se le abre el renglón.
        $renglon = $this->renglonSinVentas(2);

        // Y después vende.
        $this->venta(2, 18510000);

        $this->abrirPantalla();

        $this->assertNull(
            Comision::find($renglon->id),
            'con ventas propias, la parte de "no vendió" es un cobro doble'
        );
        $this->assertSame(1, Comision::where('vendedor_id', 2)->count());
    }

    public function test_quien_de_verdad_no_vendio_conserva_su_parte(): void
    {
        $renglon = $this->renglonSinVentas(3);

        $this->abrirPantalla();

        $this->assertNotNull(Comision::find($renglon->id), 'es del equipo: le toca su parte igual');
    }

    public function test_un_abono_de_independiente_no_cuenta_como_haber_vendido(): void
    {
        $renglon = $this->renglonSinVentas(3);

        // Lo que le dejó un independiente no pasa por el pool: sigue siendo
        // alguien que no vendió.
        $ordenId = DB::table('ordenes')->insertGetId([
            'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => 'entregado',
            'valor_total' => 450000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orden_items')->insert(['orden_id' => $ordenId, 'es_restauracion' => false, 'precio_unitario' => 450000]);
        Comision::create([
            'orden_id' => $ordenId, 'vendedor_id' => 3, 'tienda_id' => 1, 'origen' => 'abono_almacen',
            'mes_venta' => self::MES, 'valor_orden' => 225000,
            'fecha_venta' => self::MES . '-10', 'fecha_disponible' => '2026-09-20', 'estado' => 'pendiente',
        ]);

        $this->abrirPantalla();

        $this->assertNotNull(Comision::find($renglon->id));
    }

    public function test_pagar_limpia_antes_de_pagar(): void
    {
        // El caso feo: el renglón duplicado sigue ahí y alguien paga desde una
        // pantalla que no es el resumen. Antes se pagaba el duplicado, y una
        // comisión pagada ya no se corrige sola.
        $renglon = $this->renglonSinVentas(2);
        $this->venta(2, 18510000);

        $jefe = DB::table('usuarios')->insertGetId([
            'nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
            'acceso_comisiones' => true, 'created_at' => now(),
        ]);

        $this->actingAs(\App\Models\Usuario::find($jefe))
            ->postJson('/api/comisiones/pagar-listas', [
                'vendedor_id' => 2, 'mes' => self::MES, 'tienda_id' => 1,
            ]);

        $this->assertNull(
            Comision::find($renglon->id),
            'pagar tiene que limpiar los renglones que sobran antes de mover plata'
        );
    }

    public function test_lo_ya_pagado_no_se_toca(): void
    {
        $renglon = $this->renglonSinVentas(2);
        $renglon->update(['estado' => 'pagada']);
        $this->venta(2, 18510000);

        $this->abrirPantalla();

        // Corregir algo ya pagado es decisión de quien paga, no de una
        // pantalla al abrirse.
        $this->assertNotNull(Comision::find($renglon->id));
    }
}
