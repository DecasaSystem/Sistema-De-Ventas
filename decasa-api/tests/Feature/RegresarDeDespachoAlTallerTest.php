<?php

namespace Tests\Feature;

use App\Models\Despacho;
use App\Models\DespachoItem;
use App\Models\EntregaLinea;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Produccion;
use App\Models\ProduccionPaso;
use App\Models\ProduccionRetorno;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lo que ya salió del taller y todavía no se entregó, puede volver — y volver
 * barato.
 *
 * El caso real: el comedor está en la bodega esperando el camión y se le
 * parte una pata al moverlo. Hasta ahora la única forma de regresarlo era
 * dejar la producción en `pendiente`, o sea borrarle el flujo y rehacerlo
 * entero: ebanistería, tapizado, laca y despacho para pegar una pata.
 *
 * Lo que se prueba aquí es justo lo contrario: que quien la devuelve elija a
 * qué paso vuelve y cuáles pasos se rehacen, y que los que no se marquen NO
 * se repitan.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class RegresarDeDespachoAlTallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('gestiona_produccion')->default(false); $t->boolean('acceso_produccion')->default(false);
            $t->boolean('acceso_despacho')->default(false); $t->boolean('acceso_entregas')->default(false);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('apto_produccion')->default(false);
            $t->boolean('no_usa_programa')->default(false);
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->boolean('comisiones_compartidas')->default(false); });
        Schema::create('clientes', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('telefono')->nullable(); $t->string('direccion')->nullable(); $t->timestamps(); });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('estado')->default('listo_entrega');
            $t->timestamp('listo_entrega_at')->nullable();
            $t->decimal('valor_total', 12, 2)->default(0); $t->decimal('descuento_total', 12, 2)->default(0);
            $t->decimal('descuento_condicionado', 12, 2)->default(0); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('serie')->nullable(); $t->unsignedInteger('serie_numero')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable(); $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->unsignedBigInteger('tienda_origen_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->integer('cantidad_entregada')->default(0);
            $t->decimal('precio_unitario', 12, 2)->default(0); $t->boolean('es_personalizado')->default(true);
            $t->boolean('es_restauracion')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('retapizar')->default(false); $t->boolean('es_regalo')->default(false);
            $t->boolean('llevar_ahora')->default(false); $t->boolean('fabricar_pedido')->default(false);
            $t->boolean('usa_stock_tienda')->default(false);
            $t->date('fecha_entrega_prom')->nullable(); $t->date('devuelto_en')->nullable();
            $t->text('motivo_devolucion')->nullable(); $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id')->nullable(); $t->string('destino')->default('orden');
            $t->unsignedBigInteger('producto_id')->nullable(); $t->unsignedBigInteger('variante_id')->nullable();
            $t->unsignedBigInteger('combo_config_id')->nullable(); $t->string('variante_detalle')->nullable();
            $t->integer('cantidad')->default(1); $t->text('specs')->nullable();
            $t->unsignedBigInteger('creado_por')->nullable(); $t->timestamp('depositado_at')->nullable();
            $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->date('fecha_real')->nullable();
            $t->string('estado')->default('pendiente'); $t->text('motivo_retraso')->nullable();
            $t->unsignedBigInteger('despachado_por')->nullable();
        });
        Schema::create('produccion_pasos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->string('tipo_proceso');
            $t->string('linea')->default('normal');
            $t->unsignedTinyInteger('orden')->default(1); $t->string('estado')->default('pendiente');
            $t->timestamp('iniciado_at')->nullable(); $t->timestamp('completado_at')->nullable();
            $t->unsignedBigInteger('completado_por')->nullable(); $t->text('trabajadores')->nullable();
            $t->unsignedInteger('rechazos')->default(0); $t->text('ultimo_rechazo')->nullable();
            $t->unsignedBigInteger('rechazado_por_id')->nullable(); $t->timestamp('rechazado_at')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion_retornos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('produccion_id'); $t->unsignedBigInteger('paso_destino_id');
            $t->text('pasos_rehacer'); $t->text('motivo'); $t->string('foto_url')->nullable();
            $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->unsignedBigInteger('devuelto_por_id');
            $t->timestamp('created_at')->nullable(); $t->timestamp('resuelto_at')->nullable();
        });
        Schema::create('proceso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id'); $t->unsignedBigInteger('tipo_proceso_id');
            $t->string('linea')->default('ambas');
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor');
        });
        Schema::create('tipos_proceso', function (Blueprint $t) {
            $t->id(); $t->string('clave'); $t->string('nombre'); $t->boolean('activo')->default(true);
            $t->unsignedTinyInteger('orden')->default(1); $t->string('descripcion')->nullable();
            $t->string('color')->nullable();
        });
        Schema::create('paso_trabajadores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('paso_id'); $t->unsignedBigInteger('usuario_id');
            $t->unsignedBigInteger('asignado_por')->nullable(); $t->timestamp('asignado_at')->nullable();
            $t->decimal('horas', 8, 2)->nullable(); $t->unsignedTinyInteger('calidad')->nullable();
            $t->text('comentario')->nullable();
            $t->unsignedBigInteger('calificado_por')->nullable(); $t->timestamp('calificado_at')->nullable();
            $t->timestamps();
        });
        Schema::create('despachos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('camion_id')->nullable(); $t->unsignedBigInteger('conductor_id')->nullable();
            $t->unsignedBigInteger('entregado_por_id')->nullable(); $t->unsignedBigInteger('supervisor_id')->nullable();
            $t->date('fecha_despacho')->nullable(); $t->string('estado')->default('borrador');
            $t->string('tipo')->default('ruta'); $t->text('notas')->nullable();
            $t->string('nombre_ruta')->nullable(); $t->text('instrucciones')->nullable(); $t->timestamps();
        });
        Schema::create('despacho_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_id'); $t->unsignedBigInteger('orden_id');
            $t->unsignedInteger('posicion')->default(1); $t->string('estado')->default('pendiente');
            $t->string('foto_producto')->nullable(); $t->string('foto_pago')->nullable();
            $t->text('fotos_pago')->nullable(); $t->timestamp('entregado_at')->nullable();
            $t->string('firma_recibido_url')->nullable(); $t->string('recibido_por_nombre')->nullable();
            $t->string('recibido_por_cedula')->nullable(); $t->boolean('conforme')->nullable();
            $t->text('observaciones_entrega')->nullable(); $t->string('foto_novedad_url')->nullable();
            $t->string('firma_omitida_motivo')->nullable();
        });
        Schema::create('entrega_lineas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('despacho_item_id'); $t->unsignedBigInteger('orden_item_id');
            $t->integer('cantidad')->default(1); $t->string('resultado')->default('entregado');
        });
        Schema::create('devoluciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('orden_item_id')->nullable();
            $t->unsignedBigInteger('despacho_item_id')->nullable(); $t->integer('cantidad')->default(1);
            $t->text('motivo')->nullable(); $t->string('estado')->default('pendiente'); $t->timestamps();
        });
        Schema::create('orden_mensajes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->text('mensaje'); $t->string('imagen_url')->nullable(); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 12, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('orden_fijadas', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
        });

        DB::table('tipos_proceso')->insert([
            ['id' => 1, 'clave' => 'ebanisteria', 'nombre' => 'Ebanistería', 'activo' => true, 'orden' => 1],
            ['id' => 2, 'clave' => 'tapizado',    'nombre' => 'Tapizado',    'activo' => true, 'orden' => 2],
            ['id' => 3, 'clave' => 'laca',        'nombre' => 'Laca',        'activo' => true, 'orden' => 3],
            ['id' => 4, 'clave' => 'despacho',    'nombre' => 'Despacho',    'activo' => true, 'orden' => 9000],
        ]);
    }

    /**
     * El comedor terminado, esperando el camión: los cuatro pasos hechos y la
     * orden lista para entrega.
     *
     * @return array{0: Orden, 1: OrdenItem, 2: Produccion, 3: array<string, ProduccionPaso>}
     */
    private function piezaEsperandoElCamion(): array
    {
        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Cliente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 7, 'nombre' => 'Comedor 6 puestos', 'categoria' => 'comedores']);

        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1,
                                'estado' => 'listo_entrega', 'listo_entrega_at' => now(),
                                'valor_total' => 3000000, 'numero_orden' => 5001]);

        $item = OrdenItem::create(['orden_id' => $orden->id, 'producto_id' => 7, 'tienda_origen_id' => 1,
                                   'cantidad' => 1, 'precio_unitario' => 3000000, 'es_personalizado' => true]);

        $prod = Produccion::create(['orden_item_id' => $item->id, 'estado' => 'listo',
                                    'fecha_inicio' => now()->subDays(20)->toDateString(),
                                    'fecha_real' => now()->toDateString()]);

        $pasos = [];
        foreach ([['ebanisteria', 1], ['tapizado', 2], ['laca', 3], ['despacho', 4]] as [$tipo, $orden_]) {
            $pasos[$tipo] = ProduccionPaso::create([
                'produccion_id' => $prod->id, 'tipo_proceso' => $tipo, 'orden' => $orden_,
                'estado' => 'completado', 'completado_at' => now(), 'completado_por' => 1,
            ]);
        }

        return [$orden, $item, $prod, $pasos];
    }

    private function jefe(): Usuario
    {
        return Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor',
                                'gestiona_produccion' => true, 'acceso_produccion' => true, 'created_at' => now()]);
    }

    /** El ebanista que va a recibir la pieza de vuelta. */
    private function ebanista(): Usuario
    {
        $u = Usuario::create(['nombre' => 'Adrián', 'email' => 'a@d.com', 'password' => 'x', 'rol' => 'trabajador',
                              'apto_produccion' => true, 'created_at' => now()]);
        DB::table('proceso_trabajadores')->insert(['usuario_id' => $u->id, 'tipo_proceso_id' => 1]);

        return $u;
    }

    // ── Lo que hay que poder hacer ───────────────────────────────────────────

    /**
     * El corazón de todo: vuelve a ebanistería, se rehacen ebanistería y
     * despacho, y NO se rehacen tapizado ni laca. Pegar una pata no puede
     * costar tapizar el mueble otra vez.
     */
    public function test_solo_se_rehacen_los_pasos_que_se_marcan(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'pasos_rehacer'   => [],   // solo lo obligatorio: destino + despacho
                'motivo'          => 'Se partió una pata al bajarlo de la estantería.',
            ])
            ->assertOk();

        // Donde se retoma el trabajo: activo, y con el motivo a la vista.
        $this->assertSame('en_proceso', $pasos['ebanisteria']->fresh()->estado);
        $this->assertStringContainsString('pata', $pasos['ebanisteria']->fresh()->ultimo_rechazo);

        // Lo que no hace falta repetir sigue hecho.
        $this->assertSame('completado', $pasos['tapizado']->fresh()->estado);
        $this->assertSame('completado', $pasos['laca']->fresh()->estado);

        // Y la salida del taller se vuelve a hacer siempre: la pieza entró otra vez.
        $this->assertSame('pendiente', $pasos['despacho']->fresh()->estado);
    }

    /** Lo que sí se marca, se rehace. */
    public function test_lo_marcado_vuelve_a_quedar_pendiente(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'pasos_rehacer'   => [$pasos['laca']->id],
                'motivo'          => 'Quedó torcida y hay que volver a lacarla.',
            ])
            ->assertOk();

        $this->assertSame('en_proceso', $pasos['ebanisteria']->fresh()->estado);
        $this->assertSame('completado', $pasos['tapizado']->fresh()->estado);   // no se marcó
        $this->assertSame('pendiente',  $pasos['laca']->fresh()->estado);
        $this->assertSame('pendiente',  $pasos['despacho']->fresh()->estado);
    }

    /**
     * Al cerrar el paso al que volvió, el taller salta los que quedaron hechos
     * y la pieza cae directo en despacho. Es la prueba de que "no rehacer
     * todo" no es solo cómo se ven los estados: es el camino que recorre.
     */
    public function test_al_terminar_el_arreglo_salta_directo_a_despacho(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();
        $ebanista = $this->ebanista();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'motivo'          => 'Falta la manija derecha.',
            ])->assertOk();

        // Le llega al ebanista como cualquier otro trabajo.
        $mios = $this->actingAs($ebanista)->getJson('/api/produccion/mis-pasos')->assertOk()->json();
        $this->assertContains($pasos['ebanisteria']->id, collect($mios)->pluck('id')->all());

        $this->actingAs($ebanista)
            ->patchJson("/api/produccion/pasos/{$pasos['ebanisteria']->id}/completar", [
                'trabajadores' => [['usuario_id' => $ebanista->id, 'tiempo' => 1, 'unidad' => 'hora']],
            ])->assertOk();

        // Ni tapizado ni laca se reabrieron: el siguiente es despacho.
        $this->assertSame('completado', $pasos['tapizado']->fresh()->estado);
        $this->assertSame('completado', $pasos['laca']->fresh()->estado);
        $this->assertSame('en_proceso', $pasos['despacho']->fresh()->estado);
        $this->assertSame('pendiente_despachador', $prod->fresh()->estado);
    }

    /** La orden deja de estar lista: le falta esta pieza otra vez. */
    public function test_la_orden_vuelve_a_en_produccion(): void
    {
        [$orden, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['laca']->id,
                'motivo'          => 'Se rayó el costado.',
            ])->assertOk();

        $this->assertSame('en_produccion', $orden->fresh()->estado);
        $this->assertNull($orden->fresh()->listo_entrega_at);

        // Y la producción ya no está lista ni tiene fecha de salida.
        $this->assertSame('en_proceso', $prod->fresh()->estado);
        $this->assertNull($prod->fresh()->fecha_real);
    }

    /** Sale de la cola de despacho sola: ya no es entregable. */
    public function test_desaparece_de_la_cola_de_despacho(): void
    {
        [$orden, , $prod, $pasos] = $this->piezaEsperandoElCamion();
        $jefe = $this->jefe();
        $jefe->update(['acceso_despacho' => true]);

        $antes = $this->actingAs($jefe)->getJson('/api/despacho/cola')->assertOk()->json();
        $this->assertContains($orden->id, collect($antes)->pluck('id')->all());

        $this->actingAs($jefe)
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['tapizado']->id,
                'motivo'          => 'La tela no era la que pidió el cliente.',
            ])->assertOk();

        $despues = $this->actingAs($jefe)->getJson('/api/despacho/cola')->assertOk()->json();
        $this->assertNotContains($orden->id, collect($despues)->pluck('id')->all());
    }

    /**
     * Si ya estaba subida a una ruta que no ha salido, se baja de ella. Dejarla
     * sería mandar al conductor a cargar algo que está otra vez en el taller.
     */
    public function test_se_baja_de_la_ruta_que_todavia_no_sale(): void
    {
        [$orden, $item, $prod, $pasos] = $this->piezaEsperandoElCamion();

        $ruta    = Despacho::create(['estado' => 'borrador', 'fecha_despacho' => now()->toDateString()]);
        $entrega = DespachoItem::create(['despacho_id' => $ruta->id, 'orden_id' => $orden->id,
                                         'posicion' => 1, 'estado' => 'pendiente']);
        EntregaLinea::create(['despacho_item_id' => $entrega->id, 'orden_item_id' => $item->id,
                              'cantidad' => 1, 'resultado' => 'entregado']);

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'motivo'          => 'Llegó golpeado a la bodega.',
            ])->assertOk();

        // Era lo único que llevaba: la entrega se va entera y la orden queda
        // libre para volver a la cola cuando el taller la termine.
        $this->assertNull(DespachoItem::find($entrega->id));
        $this->assertSame(0, EntregaLinea::where('despacho_item_id', $entrega->id)->count());
    }

    /** Queda el rastro: a qué paso volvió, qué se rehace, quién y por qué. */
    public function test_queda_registrado_el_retorno(): void
    {
        [$orden, , $prod, $pasos] = $this->piezaEsperandoElCamion();
        $jefe = $this->jefe();

        $this->actingAs($jefe)
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['tapizado']->id,
                'pasos_rehacer'   => [$pasos['laca']->id],
                'motivo'          => 'La tela no era la que pidió el cliente.',
            ])->assertOk();

        $retorno = ProduccionRetorno::where('produccion_id', $prod->id)->first();
        $this->assertNotNull($retorno);
        $this->assertSame($pasos['tapizado']->id, $retorno->paso_destino_id);
        $this->assertSame($jefe->id, $retorno->devuelto_por_id);
        $this->assertNull($retorno->resuelto_at);
        // Destino + lo marcado + despacho, que va siempre.
        $this->assertEqualsCanonicalizing(
            [$pasos['tapizado']->id, $pasos['laca']->id, $pasos['despacho']->id],
            $retorno->pasos_rehacer,
        );

        // Y en el hilo de la orden, que es donde el vendedor va a buscar por
        // qué se corrió la fecha.
        $this->assertSame(1, DB::table('orden_mensajes')->where('orden_id', $orden->id)->count());
    }

    /** El retorno se cierra solo cuando la pieza vuelve a quedar lista. */
    public function test_el_retorno_se_cierra_cuando_la_pieza_queda_lista(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'motivo'          => 'Falta la manija.',
            ])->assertOk();

        $prod->fresh()->update(['estado' => 'listo']);

        $this->assertNotNull(ProduccionRetorno::where('produccion_id', $prod->id)->first()->resuelto_at);
    }

    // ── Lo que NO se puede ───────────────────────────────────────────────────

    /** Una pieza que sigue en el taller se corrige desde "Mis pasos", no aquí. */
    public function test_no_se_devuelve_lo_que_nunca_salio_del_taller(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();
        $prod->update(['estado' => 'en_proceso']);

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'motivo'          => 'Algo pasó.',
            ])
            ->assertStatus(422);

        $this->assertSame('completado', $pasos['ebanisteria']->fresh()->estado);
    }

    /** Ya entregada, lo que vuelve es una devolución: arrastra plata y decisión. */
    public function test_no_se_devuelve_lo_ya_entregado(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();
        $prod->update(['estado' => 'entregado']);

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['laca']->id,
                'motivo'          => 'Llegó rayado a la casa.',
            ])
            ->assertStatus(422);
    }

    /** Si el camión ya salió, la pieza no está en la bodega: está en la calle. */
    public function test_no_se_devuelve_lo_que_va_en_camino(): void
    {
        [$orden, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $ruta = Despacho::create(['estado' => 'en_ruta', 'fecha_despacho' => now()->toDateString()]);
        DespachoItem::create(['despacho_id' => $ruta->id, 'orden_id' => $orden->id,
                              'posicion' => 1, 'estado' => 'pendiente']);

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'motivo'          => 'Se dañó.',
            ])
            ->assertStatus(422);
    }

    /** Sin motivo no se devuelve: es lo único que va a leer quien lo arregle. */
    public function test_el_motivo_es_obligatorio(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $this->actingAs($this->jefe())
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
            ])
            ->assertStatus(422);
    }

    /** El vendedor de la orden no manda en el taller. */
    public function test_un_vendedor_cualquiera_no_puede_devolver(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $vendedor = Usuario::create(['nombre' => 'Vendedor', 'email' => 'v@d.com', 'password' => 'x',
                                     'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($vendedor)
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'motivo'          => 'Me pareció que estaba mal.',
            ])
            ->assertStatus(403);
    }

    /**
     * Quien está en la puerta sí: es quien tiene el mueble delante cuando
     * aparece el golpe.
     */
    public function test_el_despachador_si_puede_devolver(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $despachador = Usuario::create(['nombre' => 'Despacho', 'email' => 'd@d.com', 'password' => 'x',
                                        'rol' => 'trabajador', 'acceso_despacho' => true, 'created_at' => now()]);

        $this->actingAs($despachador)
            ->patchJson("/api/produccion/{$prod->id}/retorno", [
                'paso_destino_id' => $pasos['ebanisteria']->id,
                'motivo'          => 'Le falta una manija.',
            ])
            ->assertOk();
    }

    /** Las opciones que pinta la pantalla: a qué pasos se puede volver. */
    public function test_las_opciones_traen_los_pasos_hechos(): void
    {
        [, , $prod, $pasos] = $this->piezaEsperandoElCamion();

        $datos = $this->actingAs($this->jefe())
            ->getJson("/api/produccion/{$prod->id}/retorno")
            ->assertOk()
            ->json();

        $this->assertTrue($datos['se_puede']);
        $this->assertNull($datos['impedimento']);
        $this->assertEqualsCanonicalizing(
            collect($pasos)->pluck('id')->all(),
            collect($datos['pasos'])->pluck('id')->all(),
        );
        $this->assertSame('Ebanistería', $datos['pasos'][0]['label']);
        $this->assertTrue(collect($datos['pasos'])->firstWhere('tipo_proceso', 'despacho')['es_despacho']);
    }

    /** Cuando no se puede, la pantalla tiene que poder decir por qué. */
    public function test_las_opciones_explican_por_que_no_se_puede(): void
    {
        [, , $prod] = $this->piezaEsperandoElCamion();
        $prod->update(['estado' => 'entregado']);

        $datos = $this->actingAs($this->jefe())
            ->getJson("/api/produccion/{$prod->id}/retorno")
            ->assertOk()
            ->json();

        $this->assertFalse($datos['se_puede']);
        $this->assertStringContainsString('entregó', $datos['impedimento']);
    }
}
