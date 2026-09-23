<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migración puntual que pasa la #4313 y la R-1102 a agosto: son ventas
 * de agosto que se subieron en septiembre. Se mueven y se rehace su comisión.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class Ordenes4313YR1102PasanAAgostoTest extends TestCase
{
    private string $migracion = 'database/migrations/2026_10_03_000003_ordenes_4313_y_r1102_pasan_a_agosto.php';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('independiente')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id')->nullable(); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->unsignedBigInteger('covendedor_id')->nullable(); $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->boolean('es_compartida')->default(false); $t->string('estado')->default('en_produccion');
            $t->unsignedInteger('numero_orden')->nullable(); $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0);
            $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
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
        Schema::create('comisiones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id')->nullable(); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->string('origen')->default('venta');
            $t->char('mes_venta', 7); $t->decimal('valor_orden', 15, 2)->default(0);
            $t->date('fecha_venta')->nullable(); $t->date('fecha_disponible')->nullable();
            $t->string('estado')->default('pendiente'); $t->decimal('monto_comision', 15, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('metas_tienda', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->char('mes', 7);
            $t->decimal('meta', 15, 2)->default(0); $t->unsignedInteger('divisor_asesores')->default(1);
            $t->timestamps();
        });
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->text('cambios')->nullable(); $t->timestamp('created_at')->nullable();
        });

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('usuarios')->insert(['id' => 1, 'nombre' => 'Juan', 'rol' => 'vendedor', 'tienda_default_id' => 1]);
        DB::table('usuarios')->insert(['id' => 2, 'nombre' => 'Jefa', 'rol' => 'supervisor']);
    }

    /** Una orden con su comisión, creada el día dado a las 15:00 de Colombia. */
    private function orden(array $campos, string $dia, bool $restauracion = false): int
    {
        $creada = Carbon::parse("{$dia} 15:00:00", 'America/Bogota')->setTimezone('UTC');

        $id = DB::table('ordenes')->insertGetId(array_merge([
            'tienda_id' => 1, 'vendedor_id' => 1, 'estado' => 'entregado',
            'valor_total' => 1_000_000, 'created_at' => $creada, 'updated_at' => $creada,
        ], $campos));
        DB::table('orden_items')->insert(['orden_id' => $id, 'es_restauracion' => $restauracion, 'precio_unitario' => 1_000_000]);
        DB::table('comisiones')->insert([
            'orden_id' => $id, 'vendedor_id' => 1, 'tienda_id' => 1, 'origen' => 'venta',
            'mes_venta' => substr($dia, 0, 7), 'valor_orden' => 1_000_000,
            'fecha_venta' => $dia, 'fecha_disponible' => '2026-10-20', 'estado' => 'pendiente',
        ]);

        return $id;
    }

    private function correrMigracion(): void
    {
        $migration = require base_path($this->migracion);
        ob_start();
        $migration->up();
        ob_end_clean();
    }

    private function diaDe(int $id): string
    {
        return Carbon::parse(DB::table('ordenes')->find($id)->created_at, 'UTC')
            ->setTimezone('America/Bogota')->format('Y-m-d H:i');
    }

    public function test_la_4313_pasa_al_30_de_agosto_con_su_comision(): void
    {
        $id = $this->orden(['numero_orden' => 4313], '2026-09-02');

        $this->correrMigracion();

        $this->assertSame('2026-08-30 15:00', $this->diaDe($id), 'se conserva la hora');

        $com = DB::table('comisiones')->where('orden_id', $id)->first();
        $this->assertSame('2026-08', $com->mes_venta);
        $this->assertSame('2026-08-30', substr((string) $com->fecha_venta, 0, 10));
        $this->assertSame('2026-09-20', substr((string) $com->fecha_disponible, 0, 10), 'se cobra con agosto');
        $this->assertSame(1, DB::table('orden_ediciones')->where('orden_id', $id)->count());
    }

    public function test_la_r_1102_pasa_al_31_de_agosto(): void
    {
        $id = $this->orden(['serie' => 'R', 'serie_numero' => 1102], '2026-09-05', restauracion: true);

        $this->correrMigracion();

        $this->assertSame('2026-08-31 15:00', $this->diaDe($id));
        $this->assertSame('2026-08', DB::table('comisiones')->where('orden_id', $id)->value('mes_venta'));
    }

    public function test_de_dos_4313_solo_mueve_la_del_2_de_septiembre(): void
    {
        // El número normal se repite entre Armenia y Pereira.
        $otra = $this->orden(['numero_orden' => 4313], '2026-07-10');
        $esta = $this->orden(['numero_orden' => 4313], '2026-09-02');

        $this->correrMigracion();

        $this->assertSame('2026-08-30 15:00', $this->diaDe($esta));
        $this->assertSame('2026-07-10 15:00', $this->diaDe($otra), 'la otra no se toca');
    }

    public function test_no_la_mueve_si_la_comision_ya_se_pago(): void
    {
        $id = $this->orden(['numero_orden' => 4313], '2026-09-02');
        DB::table('comisiones')->where('orden_id', $id)->update(['estado' => 'pagada']);

        $this->correrMigracion();

        $this->assertSame('2026-09-02 15:00', $this->diaDe($id));
    }

    public function test_correrla_dos_veces_no_hace_nada_la_segunda(): void
    {
        $id = $this->orden(['numero_orden' => 4313], '2026-09-02');

        $this->correrMigracion();
        $this->correrMigracion();

        $this->assertSame('2026-08-30 15:00', $this->diaDe($id));
        $this->assertSame(1, DB::table('orden_ediciones')->where('orden_id', $id)->count());
    }
}
