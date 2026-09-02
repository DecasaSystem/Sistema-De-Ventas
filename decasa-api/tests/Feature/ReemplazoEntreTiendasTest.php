<?php

namespace Tests\Feature;

use App\Models\TiendaReemplazo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El pool se reparte por los días que estuvo cada quien, no "entre tantos".
 *
 * Se hacen reemplazos de tres días, de quince o de un mes: alguien va a cubrir
 * a otra tienda mientras el titular sale de vacaciones. Sus ventas allá
 * empujan la meta de esa tienda y arman su pool, pero el reparto no lo sabía
 * —el equipo se registraba por mes completo—, así que quien llegaba a
 * reemplazar quedaba fuera de lo que ayudaba a generar.
 *
 * Lo que se comprueba es la cuenta, que es lo que se traduce en plata:
 * sin reemplazos nada cambia, y con ellos cada quien pesa lo que estuvo.
 */
class ReemplazoEntreTiendasTest extends TestCase
{
    /** Septiembre de 2026: 30 días. */
    private const MES  = '2026-09';
    private const DIAS = 30;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); });
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tienda_reemplazos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tienda_id'); $t->string('tipo')->default('reemplazo');
            $t->unsignedBigInteger('usuario_id');
            $t->unsignedBigInteger('reemplaza_a_id')->nullable();
            $t->date('desde'); $t->date('hasta')->nullable();
            $t->string('nota')->nullable(); $t->timestamps();
        });

        DB::table('tiendas')->insert([
            ['id' => 1, 'nombre' => 'Decasa Norte'],
            ['id' => 2, 'nombre' => 'Tienda Virtual'],
        ]);
        DB::table('usuarios')->insert([
            ['id' => 1, 'nombre' => 'Paola'],
            ['id' => 2, 'nombre' => 'Marta'],
            ['id' => 3, 'nombre' => 'NN'],
            ['id' => 4, 'nombre' => 'Manuela'],
        ]);

        TiendaReemplazo::olvidarCache();
    }

    protected function tearDown(): void
    {
        TiendaReemplazo::olvidarCache();
        parent::tearDown();
    }

    /** El equipo fijo de Decasa Norte. */
    private function equipo(): array
    {
        return [1, 2, 3];   // Paola, Marta, NN
    }

    private function pesos(): array
    {
        TiendaReemplazo::olvidarCache();
        return TiendaReemplazo::pesosDelMes(1, self::MES, $this->equipo());
    }

    public function test_sin_reemplazos_todos_pesan_el_mes_entero(): void
    {
        // Lo de siempre: tres personas, tres partes iguales. Si esto cambiara,
        // el arreglo le estaría moviendo la comisión a quien no debe.
        $this->assertSame(
            [1 => self::DIAS, 2 => self::DIAS, 3 => self::DIAS],
            $this->pesos()
        );
    }

    public function test_un_reemplazo_de_mes_completo_ocupa_el_puesto(): void
    {
        TiendaReemplazo::create([
            'tienda_id' => 1, 'usuario_id' => 4, 'reemplaza_a_id' => 1,
            'desde' => '2026-09-01', 'hasta' => '2026-09-30',
        ]);

        $pesos = $this->pesos();

        // Paola no estuvo: no reparte. Manuela ocupa su lugar, no se suma una
        // cuarta parte que le quitaría a Marta y a NN.
        $this->assertArrayNotHasKey(1, $pesos);
        $this->assertSame(self::DIAS, $pesos[4]);
        $this->assertSame(self::DIAS * 3, (int) array_sum($pesos));
    }

    public function test_un_reemplazo_de_tres_dias_pesa_tres_dias(): void
    {
        TiendaReemplazo::create([
            'tienda_id' => 1, 'usuario_id' => 4, 'reemplaza_a_id' => 1,
            'desde' => '2026-09-10', 'hasta' => '2026-09-12',
        ]);

        $pesos = $this->pesos();

        $this->assertSame(3, $pesos[4], 'del 10 al 12 son tres días, contando los dos extremos');
        $this->assertSame(self::DIAS - 3, $pesos[1], 'a Paola le quedan los días que sí estuvo');
        $this->assertSame(self::DIAS, $pesos[2]);

        // Y el reparto: sobre 90 días de equipo, tres días son un 3,3%.
        $total = array_sum($pesos);
        $this->assertEqualsWithDelta(3.3, $pesos[4] / $total * 100, 0.1);
    }

    public function test_el_total_repartido_no_cambia_nunca(): void
    {
        // Lo que gana quien reemplaza es exactamente lo que pierde el
        // reemplazado. Si esto se rompiera, a Marta y a NN les bajaría la
        // comisión por algo que no tiene que ver con ellas.
        $sinNadie = array_sum($this->pesos());

        TiendaReemplazo::create([
            'tienda_id' => 1, 'usuario_id' => 4, 'reemplaza_a_id' => 1,
            'desde' => '2026-09-08', 'hasta' => '2026-09-22',
        ]);

        $pesos = $this->pesos();

        $this->assertSame($sinNadie, (int) array_sum($pesos));
        $this->assertSame(15, $pesos[4]);
        $this->assertSame(self::DIAS - 15, $pesos[1]);
        // Y las que no tienen nada que ver siguen con su tercio intacto.
        $this->assertSame(self::DIAS, $pesos[2]);
        $this->assertSame(self::DIAS, $pesos[3]);
        $this->assertEqualsWithDelta(
            1 / 3,
            ($pesos[1] + $pesos[4]) / array_sum($pesos),
            0.0001,
            'entre la titular y quien la cubrió suman el tercio de siempre'
        );
    }

    public function test_quien_se_va_a_otra_tienda_pierde_esos_dias_en_la_suya(): void
    {
        // Marta se va 10 días a cubrir a la otra tienda: cobra donde estuvo
        // parada, no en las dos.
        TiendaReemplazo::create([
            'tienda_id' => 2, 'usuario_id' => 2, 'reemplaza_a_id' => 3,
            'desde' => '2026-09-01', 'hasta' => '2026-09-10',
        ]);

        $pesos = $this->pesos();

        $this->assertSame(self::DIAS - 10, $pesos[2]);
        $this->assertSame(self::DIAS, $pesos[1]);
    }

    public function test_lo_que_pasa_fuera_del_mes_no_cuenta(): void
    {
        TiendaReemplazo::create([
            'tienda_id' => 1, 'usuario_id' => 4, 'reemplaza_a_id' => 1,
            'desde' => '2026-08-20', 'hasta' => '2026-09-05',
        ]);

        $pesos = $this->pesos();

        // Del 1 al 5 de septiembre son cinco días; lo de agosto es de agosto.
        $this->assertSame(5, $pesos[4]);
        $this->assertSame(self::DIAS - 5, $pesos[1]);
    }

    public function test_un_reemplazo_sin_fecha_de_regreso_cuenta_hasta_hoy(): void
    {
        // Abierto: no se sabe cuándo vuelve. No puede cobrar por adelantado
        // días que todavía no han pasado.
        $mesActual = now()->format('Y-m');
        TiendaReemplazo::create([
            'tienda_id' => 1, 'usuario_id' => 4, 'reemplaza_a_id' => 1,
            'desde' => now()->startOfMonth()->toDateString(), 'hasta' => null,
        ]);

        TiendaReemplazo::olvidarCache();
        $pesos = TiendaReemplazo::pesosDelMes(1, $mesActual, $this->equipo());

        $this->assertSame(now()->day, $pesos[4]);
        $this->assertLessThanOrEqual(now()->daysInMonth, $pesos[4]);
    }

    public function test_un_traslado_a_mitad_de_mes_entra_como_uno_mas(): void
    {
        // El caso de Genesis: cerraron su tienda y se pasó a otra el día 27.
        // No cubre a nadie —el equipo de allá crece— y cuenta solo los días
        // que lleva.
        TiendaReemplazo::create([
            'tienda_id' => 1, 'tipo' => TiendaReemplazo::TRASLADO, 'usuario_id' => 4,
            'desde' => '2026-09-27', 'hasta' => '2026-09-30',
        ]);

        $pesos = $this->pesos();

        $this->assertSame(4, $pesos[4], 'del 27 al 30 son cuatro días');
        // Nadie del equipo pierde nada: no está cubriendo a ninguno.
        $this->assertSame(self::DIAS, $pesos[1]);
        $this->assertSame(self::DIAS, $pesos[2]);
        $this->assertSame(self::DIAS, $pesos[3]);
        // Y el reparto ahora se hace entre más: el pool se parte en 94 días.
        $this->assertSame(self::DIAS * 3 + 4, (int) array_sum($pesos));
    }

    public function test_al_trasladarse_deja_de_contar_en_la_tienda_de_origen(): void
    {
        // Marta, del equipo de la tienda 1, se traslada a la 2 el día 21.
        TiendaReemplazo::create([
            'tienda_id' => 2, 'tipo' => TiendaReemplazo::TRASLADO, 'usuario_id' => 2,
            'desde' => '2026-09-21', 'hasta' => '2026-09-30',
        ]);

        $pesos = $this->pesos();

        // 20 días aquí; los otros 10 los estuvo en la otra tienda.
        $this->assertSame(20, $pesos[2]);
        $this->assertSame(self::DIAS, $pesos[1], 'a los demás no les cambia nada');
    }

    public function test_cubrir_dentro_de_la_propia_tienda_no_paga_dos_veces(): void
    {
        // Marta ya es del equipo y encima cubre a NN unos días: se queda con
        // su parte y con la de NN esos días, pero su peso no puede pasar del
        // mes entero ni el total repartido puede crecer.
        TiendaReemplazo::create([
            'tienda_id' => 1, 'usuario_id' => 2, 'reemplaza_a_id' => 3,
            'desde' => '2026-09-01', 'hasta' => '2026-09-30',
        ]);

        $pesos = $this->pesos();

        $this->assertSame(self::DIAS, $pesos[2]);
        $this->assertArrayNotHasKey(3, $pesos, 'NN no estuvo');
        $this->assertSame(self::DIAS * 2, (int) array_sum($pesos));
    }
}
