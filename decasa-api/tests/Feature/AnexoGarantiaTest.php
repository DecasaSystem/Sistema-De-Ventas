<?php

namespace Tests\Feature;

use App\Models\AnexoGarantia;
use App\Models\Orden;
use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * El anexo de garantías firmado dentro del sistema: en la tienda o a distancia
 * con un enlace. Se firma por la ruta pública con el token y se pega a la
 * orden al crearla, cuidando que sea la misma orden que el cliente vio.
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class AnexoGarantiaTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.cloudinary.com/*' => Http::response(['secure_url' => 'https://res.cloudinary.com/x/firma.png'])]);

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true);
            $t->boolean('ve_todas_ordenes')->default(true); $t->boolean('independiente')->default(false);
            $t->string('firma_url')->nullable();
            $t->unsignedBigInteger('tienda_default_id')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('tiendas', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->boolean('es_fabrica')->default(false);
            $t->boolean('comisiones_compartidas')->default(false);
        });
        Schema::create('clientes', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('cedula')->nullable(); $t->string('email')->nullable();
            $t->string('telefono')->nullable(); $t->string('direccion')->nullable(); $t->timestamps();
        });
        Schema::create('productos', function (Blueprint $t) { $t->id(); $t->string('nombre'); $t->string('categoria')->nullable(); $t->string('foto_url')->nullable(); });
        Schema::create('ordenes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('cliente_id')->nullable(); $t->unsignedBigInteger('tienda_id')->nullable();
            $t->unsignedBigInteger('vendedor_id')->nullable(); $t->string('canal')->nullable();
            $t->string('tipo')->default('venta'); $t->string('estado')->default('pendiente_anticipo');
            $t->timestamp('listo_entrega_at')->nullable(); $t->boolean('entrega_inmediata')->default(false);
            $t->timestamp('confirmada_en')->nullable();
            $t->decimal('valor_total', 15, 2)->default(0); $t->decimal('descuento_total', 15, 2)->default(0);
            $t->decimal('descuento_condicionado', 15, 2)->default(0);
            $t->decimal('descuento_condicionado_pct', 5, 2)->nullable();
            $t->timestamp('descuento_condicionado_revertido_at')->nullable();
            $t->decimal('anticipo_pct', 5, 2)->default(0); $t->text('notas')->nullable();
            $t->date('fecha_sugerida_vendedor')->nullable();
            $t->boolean('es_compartida')->default(false); $t->unsignedBigInteger('covendedor_id')->nullable();
            $t->string('factura_foto_url')->nullable(); $t->string('firma_url')->nullable();
            $t->string('anexo_foto_url')->nullable();
            $t->string('departamento_envio')->nullable(); $t->string('ciudad_envio')->nullable();
            $t->string('direccion_envio')->nullable();
            $t->unsignedBigInteger('tienda_abonada_id')->nullable();
            $t->string('serie')->nullable(); $t->string('motivo_serie')->nullable();
            $t->unsignedInteger('serie_numero')->nullable(); $t->unsignedInteger('numero_orden')->nullable();
            $t->string('grupo_secuencia')->nullable();
            $t->unsignedInteger('cotizacion_numero')->nullable();
            $t->timestamps();
        });
        Schema::create('orden_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('producto_id')->nullable();
            $t->string('nombre_custom')->nullable(); $t->string('categoria_custom')->nullable();
            $t->unsignedBigInteger('variante_id')->nullable(); $t->unsignedBigInteger('combo_config_id')->nullable();
            $t->string('variante_detalle')->nullable(); $t->unsignedBigInteger('tienda_origen_id')->nullable();
            $t->integer('cantidad')->default(1); $t->decimal('precio_unitario', 15, 2)->default(0);
            $t->boolean('es_personalizado')->default(false); $t->boolean('fabricar_pedido')->default(false);
            $t->boolean('es_restauracion')->default(false); $t->boolean('producto_unico')->default(false);
            $t->boolean('es_regalo')->default(false); $t->boolean('usa_stock_tienda')->default(false);
            $t->json('specs_personalizacion')->nullable(); $t->string('boceto_url')->nullable();
            $t->json('boceto_fotos')->nullable(); $t->date('fecha_entrega_prom')->nullable();
            $t->date('devuelto_en')->nullable(); $t->text('motivo_devolucion')->nullable();
            $t->timestamps();
        });
        Schema::create('produccion', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_item_id'); $t->date('fecha_inicio')->nullable();
            $t->date('fecha_compromiso')->nullable(); $t->string('estado')->default('pendiente');
        });
        Schema::create('inventario', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->integer('cantidad_disponible')->default(0); $t->integer('cantidad_reservada')->default(0);
            $t->integer('stock_minimo')->default(1);
        });
        Schema::create('inventario_movimientos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('producto_id'); $t->unsignedBigInteger('tienda_id');
            $t->unsignedBigInteger('variante_id')->nullable();
            $t->string('tipo'); $t->integer('cantidad'); $t->string('motivo')->nullable();
            $t->unsignedBigInteger('usuario_id')->nullable(); $t->timestamps();
        });
        Schema::create('comisiones', function (Blueprint $t) { $t->string('clave_unica')->nullable()->unique(); $t->string('forma_pago_pagada')->nullable();
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id');
            $t->unsignedBigInteger('tienda_id')->nullable(); $t->char('mes_venta', 7);
            $t->decimal('valor_orden', 15, 2)->default(0); $t->date('fecha_venta')->nullable();
            $t->date('fecha_disponible')->nullable(); $t->string('estado')->default('pendiente');
            $t->decimal('monto_comision', 15, 2)->nullable(); $t->timestamp('fecha_pago')->nullable();
            $t->unsignedBigInteger('pagada_por')->nullable(); $t->boolean('notificado_lista')->default(false);
            $t->boolean('es_covendedor')->default(false); $t->timestamps();
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('usuario_id')->nullable(); $t->string('tipo'); $t->string('titulo');
            $t->text('mensaje'); $t->boolean('leida')->default(false); $t->boolean('urgente')->default(false);
            $t->json('datos')->nullable(); $t->timestamps();
        });
        Schema::create('orden_secuencias', function (Blueprint $t) {
            $t->string('grupo', 50)->primary(); $t->unsignedInteger('ultimo_numero')->default(0);
        });
        Schema::create('pagos', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('vendedor_id')->nullable();
            $t->string('tipo'); $t->decimal('monto', 15, 2); $t->string('metodo')->nullable();
            $t->string('referencia')->nullable(); $t->text('notas')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('orden_mensajes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id')->nullable();
            $t->text('mensaje'); $t->string('imagen_url')->nullable(); $t->json('mencionados')->nullable(); $t->timestamps();
        });
        $this->completarEsquemaDeEntregas();

        (require base_path('database/migrations/2026_10_12_000001_anexos_de_garantia.php'))->up();

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Ana Ruiz', 'cedula' => '123', 'email' => 'ana@x.com', 'telefono' => '3001112233', 'direccion' => 'Cra 14 # 5-20', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('clientes')->insert(['id' => 2, 'nombre' => 'Otro', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa', 'categoria' => 'comedores', 'foto_url' => 'https://res.cloudinary.com/x/mesa.jpg']);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0]);
    }

    private function vendedor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Vendedora', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => 1, 'firma_url' => 'https://ejemplo/firma-v.png', 'created_at' => now(),
        ]);
    }

    private function lineas(int $cantidad = 1, float $precio = 100000): array
    {
        return [['producto_id' => 5, 'cantidad' => $cantidad, 'precio_unitario' => $precio]];
    }

    /** Crea un anexo remoto con el resumen de la orden; devuelve [id, token]. */
    private function anexoRemoto(Usuario $v, int $cantidad = 1): array
    {
        $r = $this->actingAs($v)->postJson('/api/anexos', [
            'cliente_id' => 1, 'modo' => 'remoto',
            'resumen' => [
                'total'  => 100000 * $cantidad,
                'items'  => [['nombre' => 'Mesa', 'cantidad' => $cantidad, 'precio' => 100000]],
                'lineas' => $this->lineas($cantidad),
            ],
        ])->assertCreated();

        return [$r->json('id'), $r->json('token')];
    }

    private function respuestas(array $extra = []): array
    {
        return array_merge([
            'secciones' => array_column(\App\Support\AnexoGarantiaTexto::secciones(), 'id'),
            'checklist' => ['proceso_garantias' => 'si', 'cobertura' => 'si', 'garantia_espuma' => 'no', 'entregas' => 'si'],
            'nombre'    => 'Ana Ruiz',
            'documento' => '123',
            // Una firma de verdad pesa varios KB; esta solo tiene que parecer un PNG.
            'firma'     => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 400)),
        ], $extra);
    }

    public function test_el_cliente_lo_ve_y_lo_firma_por_el_enlace(): void
    {
        $v = $this->vendedor();
        [$id, $token] = $this->anexoRemoto($v);

        $this->getJson("/api/public/anexos/{$token}")->assertOk()
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('cliente.nombre', 'Ana Ruiz')
            ->assertJsonPath('resumen.total', 100000)
            ->assertJsonMissingPath('resumen.lineas');

        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();

        $a = AnexoGarantia::find($id);
        $this->assertSame('firmado', $a->estado);
        $this->assertSame('https://res.cloudinary.com/x/firma.png', $a->firma_url);
        $this->assertSame('no', $a->respuestas['checklist']['garantia_espuma']);
        $this->assertSame(1, DB::table('notificaciones')->where('usuario_id', $v->id)->where('tipo', 'anexo_firmado')->count());

        // Darle dos veces no es un error ni cambia nada.
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas(['nombre' => 'Otro']))
            ->assertOk()->assertJsonPath('ya_firmado', true);
        $this->assertSame('Ana Ruiz', $a->fresh()->nombre_firmante);
    }

    public function test_no_se_firma_sin_leer_todo_ni_responder_el_check_list(): void
    {
        [, $token] = $this->anexoRemoto($this->vendedor());

        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas(['secciones' => ['garantia']]))->assertStatus(422);
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas(['checklist' => ['cobertura' => 'si']]))->assertStatus(422);
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas(['firma' => 'data:image/png;base64,AAAA']))->assertStatus(422);
        $this->getJson('/api/public/anexos/' . str_repeat('x', 48))->assertNotFound();
    }

    public function test_un_enlace_vencido_no_se_firma(): void
    {
        [$id, $token] = $this->anexoRemoto($this->vendedor());
        AnexoGarantia::whereKey($id)->update(['vence_at' => now()->subDay()]);

        $this->getJson("/api/public/anexos/{$token}")->assertStatus(410);
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertStatus(410);
    }

    public function test_la_orden_se_crea_con_la_firma_del_anexo_y_queda_pegado(): void
    {
        $v = $this->vendedor();
        [$id, $token] = $this->anexoRemoto($v);
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();

        $r = $this->actingAs($v)->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'whatsapp', 'anticipo_monto' => 0,
            'anexo_id' => $id,
            'items' => [['producto_id' => 5, 'cantidad' => 1, 'precio_unitario' => 100000, 'es_personalizado' => false]],
        ])->assertCreated();

        $orden = Orden::find($r->json('id'));
        $this->assertSame('https://res.cloudinary.com/x/firma.png', $orden->firma_url, 'sin firmar dos veces');
        $this->assertSame($orden->id, (int) AnexoGarantia::find($id)->orden_id);

        // Como lo carga el detalle de la orden. Con lista de columnas el join
        // de ofMany dejaba `orden_id` ambiguo y tumbaba el detalle.
        $cargada = Orden::with('anexoGarantia')->find($orden->id);
        $this->assertSame($id, $cargada->anexoGarantia->id);
        $this->assertArrayNotHasKey('token', $cargada->anexoGarantia->toArray());
    }

    public function test_si_la_orden_cambio_despues_de_firmar_no_se_crea(): void
    {
        $v = $this->vendedor();
        [$id, $token] = $this->anexoRemoto($v);
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();

        $this->actingAs($v)->postJson('/api/ordenes', [
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'whatsapp', 'anticipo_monto' => 0,
            'anexo_id' => $id,
            'items' => [['producto_id' => 5, 'cantidad' => 2, 'precio_unitario' => 100000, 'es_personalizado' => false]],
        ])->assertStatus(422)->assertJsonPath('codigo', 'anexo_desactualizado');

        $this->assertSame(0, Orden::count());
    }

    public function test_no_se_usa_un_anexo_sin_firmar_ni_el_de_otro_cliente(): void
    {
        $v = $this->vendedor();
        [$id, $token] = $this->anexoRemoto($v);
        $base = [
            'tienda_id' => 1, 'canal' => 'whatsapp', 'anticipo_monto' => 0, 'anexo_id' => $id,
            'firma_url' => 'https://ejemplo/f.png',
            'items' => [['producto_id' => 5, 'cantidad' => 1, 'precio_unitario' => 100000, 'es_personalizado' => false]],
        ];

        $this->actingAs($v)->postJson('/api/ordenes', $base + ['cliente_id' => 1])->assertStatus(422);

        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();
        $this->actingAs($v)->postJson('/api/ordenes', $base + ['cliente_id' => 2])->assertStatus(422);
    }

    public function test_enviar_de_nuevo_deja_sin_servir_el_enlace_anterior(): void
    {
        $v = $this->vendedor();
        [$viejoId, $viejoToken] = $this->anexoRemoto($v);
        [$nuevoId, $nuevoToken] = $this->anexoRemoto($v, 2);

        // El cliente abre el WhatsApp viejo: ya no sirve, no firma la versión anterior.
        $this->getJson("/api/public/anexos/{$viejoToken}")->assertNotFound();
        $this->postJson("/api/public/anexos/{$viejoToken}/firmar", $this->respuestas())->assertNotFound();
        $this->assertSame('anulado', AnexoGarantia::find($viejoId)->estado);
        $this->getJson("/api/public/anexos/{$nuevoToken}")->assertOk();

        // "Cancelar envío" también lo apaga.
        $this->actingAs($v)->postJson("/api/anexos/{$nuevoId}/anular")->assertOk()->assertJsonPath('estado', 'anulado');
        $this->getJson("/api/public/anexos/{$nuevoToken}")->assertNotFound();
    }

    public function test_al_vendedor_le_dice_lo_que_el_cliente_respondio_que_no(): void
    {
        $v = $this->vendedor();
        [$id, $token] = $this->anexoRemoto($v);
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();

        $preguntas = $this->actingAs($v)->getJson("/api/anexos/{$id}")->assertOk()->json('respuestas_no');
        $this->assertCount(1, $preguntas);
        $this->assertStringContainsString('espuma', $preguntas[0]);
    }

    public function test_la_huella_ve_el_cambio_de_tela_pero_no_los_centavos_del_descuento(): void
    {
        $linea = ['producto_id' => 5, 'variante_id' => 3, 'cantidad' => 1, 'precio_unitario' => 1500000];

        // Otra tela al mismo precio: ya no es lo que el cliente vio.
        $this->assertNotSame(
            AnexoGarantia::huella([$linea], 0),
            AnexoGarantia::huella([['variante_id' => 4] + $linea], 0),
        );
        // La pantalla manda el descuento redondeado; la orden, con centavos.
        $this->assertSame(
            AnexoGarantia::huella([$linea], 75000),
            AnexoGarantia::huella([$linea], 74999.6),
        );

        // Un anexo firmado con la huella de antes de este cambio sigue sirviendo.
        $viejo = new AnexoGarantia(['resumen_hash' => AnexoGarantia::huella([$linea], 75000, formatoViejo: true)]);
        $this->assertTrue($viejo->coincideCon([$linea], 75000));
        $this->assertFalse($viejo->coincideCon([['cantidad' => 2] + $linea], 75000));
    }

    // ── Firmar a distancia la orden completa ─────────────────────────────────

    public function test_el_cliente_ve_su_pedido_completo_y_sus_datos(): void
    {
        $v = $this->vendedor();
        $r = $this->actingAs($v)->postJson('/api/anexos', [
            'cliente_id' => 1, 'modo' => 'remoto',
            'resumen' => [
                'total' => 1500000, 'subtotal' => 1500000, 'anticipo' => 750000, 'saldo' => 750000,
                'envio' => ['ciudad' => 'Armenia', 'direccion' => 'Cra 14 # 5-20'],
                'items' => [[
                    'nombre' => 'Sofá Milán', 'detalle' => 'Lino gris', 'cantidad' => 1, 'precio' => 1500000,
                    'foto' => 'https://res.cloudinary.com/x/sofa.jpg',
                    'bocetos' => ['https://res.cloudinary.com/x/boceto.jpg'],
                    'specs' => [['label' => 'Largo', 'value' => '220']],
                ]],
                'lineas' => [['producto_id' => 5, 'cantidad' => 1, 'precio_unitario' => 1500000]],
            ],
        ])->assertCreated();

        $this->getJson('/api/public/anexos/' . $r->json('token'))->assertOk()
            ->assertJsonPath('resumen.items.0.bocetos.0', 'https://res.cloudinary.com/x/boceto.jpg')
            ->assertJsonPath('resumen.items.0.specs.0.label', 'Largo')
            ->assertJsonPath('resumen.saldo', 750000)
            ->assertJsonPath('cliente.telefono', '3001112233')
            ->assertJsonPath('cliente.direccion', 'Cra 14 # 5-20');

        // Solo imágenes por https: nada de otra cosa en la página del cliente.
        $this->actingAs($v)->postJson('/api/anexos', [
            'cliente_id' => 1, 'modo' => 'remoto',
            'resumen' => ['items' => [['nombre' => 'X', 'foto' => 'javascript:alert(1)']]],
        ])->assertStatus(422);
    }

    /** Una orden ya creada que quedó sin firma (esperaba el precio del taller). */
    private function ordenSinFirma(array $extra = []): Orden
    {
        $orden = Orden::create($extra + ['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => 1, 'canal' => 'whatsapp',
            'estado' => 'pendiente_anticipo', 'valor_total' => 200000, 'numero_orden' => 777]);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'producto_id' => 5, 'cantidad' => 2,
            'precio_unitario' => 100000, 'created_at' => now(), 'updated_at' => now()]);

        return $orden;
    }

    private function resumenDeOrden(): array
    {
        return ['resumen' => ['total' => 200000, 'items' => [['nombre' => 'Mesa', 'cantidad' => 2, 'precio' => 100000]]]];
    }

    public function test_una_orden_creada_sin_firma_se_manda_y_queda_firmada(): void
    {
        $v = $this->vendedor();
        $orden = $this->ordenSinFirma();

        $r = $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/firma-remota", $this->resumenDeOrden())->assertCreated();
        $this->actingAs($v)->getJson("/api/ordenes/{$orden->id}/firma-remota")->assertOk()->assertJsonPath('estado', 'pendiente');

        $this->getJson('/api/public/anexos/' . $r->json('token'))->assertOk()
            ->assertJsonPath('resumen.orden_existente', true)
            // La foto del producto la pone el servidor desde el catálogo.
            ->assertJsonPath('resumen.items.0.foto', 'https://res.cloudinary.com/x/mesa.jpg')
            ->assertJsonPath('resumen.referencia', $orden->fresh()->referencia);

        $this->postJson('/api/public/anexos/' . $r->json('token') . '/firmar', $this->respuestas())->assertOk();

        // La firma quedó en la orden, y el anexo pegado a ella.
        $this->assertSame('https://res.cloudinary.com/x/firma.png', $orden->fresh()->firma_url);
        $this->assertSame('firmado', AnexoGarantia::find($r->json('id'))->estado);
        $this->assertSame(1, DB::table('orden_mensajes')->where('orden_id', $orden->id)->count());

        // Ya firmada no se vuelve a mandar.
        $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/firma-remota", $this->resumenDeOrden())->assertStatus(422);
    }

    public function test_si_editan_la_orden_despues_de_mandarla_no_se_firma(): void
    {
        $v = $this->vendedor();
        $orden = $this->ordenSinFirma();
        $r = $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/firma-remota", $this->resumenDeOrden())->assertCreated();

        DB::table('orden_items')->where('orden_id', $orden->id)->update(['precio_unitario' => 90000]);

        $this->postJson('/api/public/anexos/' . $r->json('token') . '/firmar', $this->respuestas())->assertStatus(409);
        $this->assertNull($orden->fresh()->firma_url);
    }

    public function test_no_se_manda_a_firmar_con_productos_sin_precio(): void
    {
        $orden = $this->ordenSinFirma(['estado' => 'pendiente_cotizacion']);
        DB::table('orden_items')->insert(['orden_id' => $orden->id, 'nombre_custom' => 'Sofá a la medida', 'cantidad' => 1,
            'precio_unitario' => 0, 'es_personalizado' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->vendedor())->postJson("/api/ordenes/{$orden->id}/firma-remota", $this->resumenDeOrden())
            ->assertStatus(422);
    }

    public function test_solo_quien_lo_creo_ve_el_estado(): void
    {
        [$id] = $this->anexoRemoto($this->vendedor());
        $otro = Usuario::create(['nombre' => 'Otro', 'email' => 'o@d.com', 'password' => 'x', 'rol' => 'vendedor', 'created_at' => now()]);

        $this->actingAs($otro)->getJson("/api/anexos/{$id}")->assertForbidden();
    }
}
