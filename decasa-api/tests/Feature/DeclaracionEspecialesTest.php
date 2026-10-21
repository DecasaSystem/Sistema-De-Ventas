<?php

namespace Tests\Feature;

use App\Models\AnexoGarantia;
use App\Models\Orden;
use App\Models\OrdenItem;
use App\Models\Usuario;
use App\Support\AjustesSistema;
use App\Support\DeclaracionEspeciales;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La declaración para la fabricación de muebles con diseños y medidas
 * especiales (dueño, 2026-10-21): el vendedor escribe en la orden cómo va el
 * mueble que no está en el catálogo (la ficha), el cliente la ve con los
 * bocetos, aprueba producto por producto y la firma con el anexo (una sola
 * firma, dos documentos). Si después cambia la ficha, esa firma ya no sirve.
 *
 * El esquema se monta a mano, como en AnexoGarantiaTest.
 */
class DeclaracionEspecialesTest extends TestCase
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
        // El detalle de la orden carga su historial de ediciones.
        Schema::create('orden_ediciones', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('orden_id'); $t->unsignedBigInteger('usuario_id');
            $t->json('cambios')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('configuracion', function (Blueprint $t) {
            $t->string('clave')->primary(); $t->text('valor');
        });
        $this->completarEsquemaDeEntregas();

        (require base_path('database/migrations/2026_10_12_000001_anexos_de_garantia.php'))->up();
        (require base_path('database/migrations/2026_10_25_000001_declaracion_disenos_especiales.php'))->up();

        DB::table('tiendas')->insert(['id' => 1, 'nombre' => 'Decasa Norte']);
        DB::table('clientes')->insert(['id' => 1, 'nombre' => 'Ana Ruiz', 'cedula' => '123', 'email' => 'ana@x.com', 'telefono' => '3001112233', 'direccion' => 'Cra 14 # 5-20', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('productos')->insert(['id' => 5, 'nombre' => 'Mesa', 'categoria' => 'comedores', 'foto_url' => 'https://res.cloudinary.com/x/mesa.jpg']);
        DB::table('productos')->insert(['id' => 6, 'nombre' => 'Sofá Milán', 'categoria' => 'sofas']);
        DB::table('inventario')->insert(['producto_id' => 5, 'tienda_id' => 1, 'cantidad_disponible' => 3, 'cantidad_reservada' => 0]);
    }

    // ── Ayudas ────────────────────────────────────────────────────────────────

    private function vendedor(): Usuario
    {
        return Usuario::create([
            'nombre' => 'Vendedora', 'email' => 'v@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => 1, 'firma_url' => 'https://ejemplo/firma-v.png', 'created_at' => now(),
        ]);
    }

    private function ficha(array $cambios = []): array
    {
        return array_merge([
            'tipo_mueble' => 'Biblioteca empotrada',
            'madera'      => 'Cedro',
            'pintura'     => 'Laca blanca mate',
            'ancho'       => '240 cm',
            'alto'        => '210 cm',
            'profundidad' => '35 cm',
            'detalles'    => 'Cinco entrepaños, el de abajo con puertas.',
        ], $cambios);
    }

    /** El ítem del diseño especial como llega al crear la orden. */
    private function itemEspecial(?array $ficha = null, float $precio = 2500000): array
    {
        return [
            'nombre_custom' => 'Biblioteca a la medida', 'cantidad' => 1, 'precio_unitario' => $precio,
            'es_personalizado' => true, 'specs_personalizacion' => ['notas' => 'cedro'],
            'ficha_especial' => $ficha ?? $this->ficha(),
            'boceto_urls' => ['https://res.cloudinary.com/x/boceto1.jpg'],
        ];
    }

    /** Lo que la pantalla le manda al crear el enlace para que el cliente apruebe. */
    private function especiales(?array $ficha = null): array
    {
        return [[
            'nombre' => 'Biblioteca a la medida', 'nombre_custom' => 'Biblioteca a la medida', 'cantidad' => 1,
            'tipo' => 'Diseño especial', 'ficha' => $ficha ?? $this->ficha(),
            'bocetos' => ['https://res.cloudinary.com/x/boceto1.jpg', 'http://malo/x.jpg'],
        ]];
    }

    private function respuestas(array $extra = []): array
    {
        return array_merge([
            'secciones' => array_column(\App\Support\AnexoGarantiaTexto::secciones(), 'id'),
            'checklist' => ['proceso_garantias' => 'si', 'cobertura' => 'si', 'garantia_espuma' => 'si', 'entregas' => 'si'],
            'nombre'    => 'Ana Ruiz',
            'documento' => '123',
            'firma'     => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 400)),
            'declaracion_aprobados' => [0],
        ], $extra);
    }

    /** Crea el anexo con la declaración (en la tienda) y lo firma. Devuelve el id. */
    private function anexoFirmado(Usuario $v, ?array $ficha = null, string $modo = 'presencial'): int
    {
        $r = $this->actingAs($v)->postJson('/api/anexos', [
            'cliente_id' => 1, 'modo' => $modo, 'especiales' => $this->especiales($ficha),
        ])->assertCreated();
        $this->postJson('/api/public/anexos/' . $r->json('token') . '/firmar', $this->respuestas())->assertOk();

        return $r->json('id');
    }

    private function crearOrden(Usuario $v, array $extra = [], ?array $items = null)
    {
        return $this->actingAs($v)->postJson('/api/ordenes', array_merge([
            'cliente_id' => 1, 'tienda_id' => 1, 'canal' => 'fisica', 'anticipo_monto' => 0,
            'firma_url' => 'https://ejemplo/firma-cliente.png', 'anexo_foto_url' => 'https://ejemplo/anexo.jpg',
            'departamento_envio' => 'Quindío', 'ciudad_envio' => 'Armenia', 'direccion_envio' => 'Cra 14',
            'items' => $items ?? [$this->itemEspecial()],
        ], $extra));
    }

    // ── La ficha y su huella ──────────────────────────────────────────────────

    public function test_la_ficha_se_limpia_y_dice_que_le_falta(): void
    {
        $this->assertNull(DeclaracionEspeciales::normalizarFicha(['tipo_mueble' => '  ', 'otra' => 'x']));
        $this->assertSame(['tipo_mueble' => 'Mesa de centro', 'ancho' => '120'],
            DeclaracionEspeciales::normalizarFicha(['tipo_mueble' => "  Mesa   de\ncentro ", 'ancho' => 120, 'campo_raro' => 'no']));

        $this->assertSame('el tipo de mueble', DeclaracionEspeciales::faltaEnFicha(['madera' => 'Pino']));
        $this->assertStringContainsString('descripción', DeclaracionEspeciales::faltaEnFicha(['tipo_mueble' => 'Mesa', 'ancho' => '1', 'alto' => '2']));
        $this->assertStringContainsString('medidas', DeclaracionEspeciales::faltaEnFicha(['tipo_mueble' => 'Mesa', 'madera' => 'Pino', 'ancho' => '1']));
        $this->assertNull(DeclaracionEspeciales::faltaEnFicha($this->ficha()));

        // La huella no se fija en mayúsculas ni espacios, sí en lo que cambia el mueble.
        $base = [['nombre_custom' => 'Biblioteca', 'ficha' => $this->ficha()]];
        $this->assertSame(DeclaracionEspeciales::huella($base),
            DeclaracionEspeciales::huella([['nombre_custom' => ' BIBLIOTECA ', 'ficha' => $this->ficha(['madera' => ' cedro '])]]));
        $this->assertNotSame(DeclaracionEspeciales::huella($base),
            DeclaracionEspeciales::huella([['nombre_custom' => 'Biblioteca', 'ficha' => $this->ficha(['ancho' => '250 cm'])]]));
    }

    public function test_que_productos_la_piden_se_escoge_en_ajustes(): void
    {
        $especial      = ['nombre_custom' => 'X', 'es_personalizado' => true];
        $personalizado = ['producto_id' => 6, 'es_personalizado' => true];
        $catalogo      = ['producto_id' => 5];
        $restauracion  = ['nombre_custom' => 'Silla vieja', 'es_personalizado' => true, 'es_restauracion' => true];
        $unico         = ['nombre_custom' => 'Baúl', 'es_personalizado' => true, 'producto_unico' => true];
        $fabricar      = ['producto_id' => 6, 'es_personalizado' => true, 'fabricar_pedido' => true];

        // De fábrica: solo el diseño especial.
        $this->assertSame([$especial], DeclaracionEspeciales::lineasQueAplican([$especial, $personalizado, $catalogo, $restauracion, $unico, $fabricar]));

        // Prendiendo los personalizados (lo que el dueño no tenía seguro).
        AjustesSistema::definir(AjustesSistema::DECLARACION_PERSONALIZADOS, true);
        $this->assertSame([$especial, $personalizado], DeclaracionEspeciales::lineasQueAplican([$especial, $personalizado, $catalogo]));

        // Y apagando los especiales.
        AjustesSistema::definir(AjustesSistema::DECLARACION_ESPECIALES, false);
        $this->assertSame([$personalizado], DeclaracionEspeciales::lineasQueAplican([$especial, $personalizado]));
    }

    public function test_solo_el_supervisor_cambia_los_interruptores(): void
    {
        $v = $this->vendedor();
        $this->actingAs($v)->getJson('/api/ajustes-sistema')->assertOk()
            ->assertJsonPath('declaracion_disenos_especiales', true)
            ->assertJsonPath('declaracion_personalizados', false);
        $this->actingAs($v)->patchJson('/api/ajustes-sistema', ['declaracion_personalizados' => true])->assertForbidden();

        $jefa = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
        $this->actingAs($jefa)->patchJson('/api/ajustes-sistema', ['declaracion_personalizados' => true])->assertOk()
            ->assertJsonPath('declaracion_personalizados', true)
            ->assertJsonPath('calificar_trabajadores_taller', true);
    }

    // ── Firmar ────────────────────────────────────────────────────────────────

    public function test_el_cliente_ve_cada_producto_y_tiene_que_aprobarlos_todos(): void
    {
        $v = $this->vendedor();
        $r = $this->actingAs($v)->postJson('/api/anexos', [
            'cliente_id' => 1, 'modo' => 'remoto', 'especiales' => $this->especiales(),
            'resumen' => ['total' => 2500000, 'items' => [['nombre' => 'Biblioteca a la medida', 'cantidad' => 1, 'precio' => 2500000]]],
        ])->assertCreated()->assertJsonPath('declaracion', true);
        $token = $r->json('token');

        $pub = $this->getJson("/api/public/anexos/{$token}")->assertOk()
            ->assertJsonPath('declaracion.productos.0.nombre', 'Biblioteca a la medida')
            ->assertJsonPath('declaracion.productos.0.grupos.tipo.0.value', 'Biblioteca empotrada')
            ->assertJsonPath('declaracion.contenido.ciudad', 'Armenia, Quindío');
        // Solo los bocetos subidos (https): el otro no se le muestra.
        $this->assertSame(['https://res.cloudinary.com/x/boceto1.jpg'], $pub->json('declaracion.productos.0.bocetos'));

        // Sin aprobar el producto no se firma.
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas(['declaracion_aprobados' => []]))->assertStatus(422);
        $this->assertSame('pendiente', AnexoGarantia::find($r->json('id'))->estado);

        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();
        $a = AnexoGarantia::find($r->json('id'));
        $this->assertSame('firmado', $a->estado);
        $this->assertSame([0], $a->respuestas['declaracion']['aprobados']);
        // Con los datos de ese día, como pedía el papel.
        $this->assertSame('Cra 14 # 5-20', $a->respuestas['declaracion']['direccion']);
        $this->assertSame('3001112233', $a->respuestas['declaracion']['telefono']);
        $this->assertSame('fabrica', $a->declaracion_version);
    }

    public function test_sin_la_ficha_completa_no_se_manda_a_aprobar(): void
    {
        $this->actingAs($this->vendedor())->postJson('/api/anexos', [
            'cliente_id' => 1, 'modo' => 'presencial', 'especiales' => $this->especiales(['ancho' => '', 'alto' => '', 'profundidad' => '']),
        ])->assertStatus(422)->assertJsonPath('codigo', 'ficha_incompleta');

        $this->assertSame(0, AnexoGarantia::count());
    }

    // ── Crear la orden ────────────────────────────────────────────────────────

    public function test_la_orden_se_crea_con_la_declaracion_firmada_y_guarda_la_ficha(): void
    {
        $v  = $this->vendedor();
        $id = $this->anexoFirmado($v);

        $r = $this->crearOrden($v, ['anexo_id' => $id, 'anexo_foto_url' => null, 'firma_url' => null])->assertCreated();

        $orden = Orden::with('items')->find($r->json('id'));
        $this->assertSame('Biblioteca empotrada', $orden->items[0]->ficha_especial['tipo_mueble']);
        $this->assertSame($orden->id, (int) AnexoGarantia::find($id)->orden_id);
        $this->assertNull(DeclaracionEspeciales::faltaEnOrden($orden));
    }

    public function test_si_cambia_la_ficha_despues_de_firmar_no_se_crea(): void
    {
        $v  = $this->vendedor();
        $id = $this->anexoFirmado($v);

        $this->crearOrden($v, ['anexo_id' => $id], [$this->itemEspecial($this->ficha(['ancho' => '260 cm']))])
            ->assertStatus(422)->assertJsonPath('codigo', 'anexo_desactualizado');

        // Ni si se agrega otro especial que el cliente no aprobó.
        $otro = ['nombre_custom' => 'Mesa auxiliar', 'cantidad' => 1, 'precio_unitario' => 300000, 'es_personalizado' => true,
            'specs_personalizacion' => ['notas' => 'x'], 'ficha_especial' => $this->ficha(['tipo_mueble' => 'Mesa'])];
        $this->crearOrden($v, ['anexo_id' => $id], [$this->itemEspecial(), $otro])
            ->assertStatus(422)->assertJsonPath('codigo', 'anexo_desactualizado');

        $this->assertSame(0, Orden::count());
    }

    public function test_un_anexo_firmado_sin_declaracion_no_sirve_para_un_especial(): void
    {
        $v = $this->vendedor();
        $r = $this->actingAs($v)->postJson('/api/anexos', ['cliente_id' => 1, 'modo' => 'presencial'])->assertCreated();
        $this->postJson('/api/public/anexos/' . $r->json('token') . '/firmar', $this->respuestas(['declaracion_aprobados' => []]))->assertOk();

        $this->crearOrden($v, ['anexo_id' => $r->json('id')])
            ->assertStatus(422)->assertJsonPath('codigo', 'anexo_desactualizado');
    }

    public function test_en_la_tienda_sin_firmarla_se_pide_la_foto_del_papel(): void
    {
        $v = $this->vendedor();

        $this->crearOrden($v)->assertStatus(422)->assertJsonValidationErrors('declaracion_foto_url');

        $r = $this->crearOrden($v, ['declaracion_foto_url' => 'https://res.cloudinary.com/x/declaracion.jpg'])->assertCreated();
        $this->assertSame('https://res.cloudinary.com/x/declaracion.jpg', Orden::find($r->json('id'))->declaracion_foto_url);

        // Lo del catálogo no la pide; y un borrador tampoco (se pide al confirmar).
        $this->crearOrden($v, [], [['producto_id' => 5, 'cantidad' => 1, 'precio_unitario' => 100000]])->assertCreated();
        // (Otro precio: la misma orden en 15 segundos se toma por doble envío.)
        $this->crearOrden($v, ['guardar_borrador' => true, 'firma_url' => null, 'anexo_foto_url' => null],
            [$this->itemEspecial(precio: 1800000)])->assertCreated();
    }

    public function test_apagada_en_ajustes_no_se_pide(): void
    {
        AjustesSistema::definir(AjustesSistema::DECLARACION_ESPECIALES, false);

        $this->crearOrden($this->vendedor())->assertCreated();
    }

    public function test_con_precio_por_consultar_se_pide_al_confirmar_la_cotizacion(): void
    {
        $v = $this->vendedor();
        $r = $this->crearOrden($v, ['firma_url' => null, 'anexo_foto_url' => null], [$this->itemEspecial(precio: 0)])->assertCreated();
        $orden = Orden::find($r->json('id'));
        $this->assertSame('pendiente_cotizacion', $orden->estado);
        OrdenItem::where('orden_id', $orden->id)->update(['precio_unitario' => 2500000]);

        $confirmar = [
            'firma_url' => 'https://ejemplo/f.png', 'anexo_foto_url' => 'https://ejemplo/anexo.jpg',
            'anticipo_monto' => 0, 'anticipo_metodo' => 'efectivo',
        ];
        $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/confirmar-cotizacion", $confirmar)
            ->assertStatus(422)->assertJsonValidationErrors('declaracion_foto_url');

        $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/confirmar-cotizacion",
            $confirmar + ['declaracion_foto_url' => 'https://res.cloudinary.com/x/declaracion.jpg'])->assertOk();
        $this->assertSame('pendiente_anticipo', $orden->fresh()->estado);
    }

    // ── Orden ya creada: mandar a firmar y corregir la ficha ──────────────────

    private function ordenEsperandoFirma(?array $ficha = null): Orden
    {
        $v = $this->vendedor();
        $orden = Orden::create(['cliente_id' => 1, 'tienda_id' => 1, 'vendedor_id' => $v->id, 'canal' => 'whatsapp',
            'estado' => 'pendiente_anticipo', 'valor_total' => 2500000]);
        OrdenItem::create(['orden_id' => $orden->id, 'nombre_custom' => 'Biblioteca a la medida', 'cantidad' => 1,
            'precio_unitario' => 2500000, 'es_personalizado' => true, 'specs_personalizacion' => ['notas' => 'x'],
            'boceto_url' => 'https://res.cloudinary.com/x/boceto1.jpg', 'ficha_especial' => $ficha]);

        return $orden;
    }

    public function test_una_orden_creada_se_manda_con_la_declaracion_que_sale_de_la_orden(): void
    {
        $orden = $this->ordenEsperandoFirma(null);
        $v = Usuario::find($orden->vendedor_id);
        $resumen = ['resumen' => ['total' => 2500000, 'items' => [['nombre' => 'Biblioteca', 'cantidad' => 1, 'precio' => 2500000]]]];

        // Sin ficha no se manda.
        $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/firma-remota", $resumen)
            ->assertStatus(422)->assertJsonPath('codigo', 'ficha_incompleta');

        $item = $orden->items()->first();
        $this->actingAs($v)->patchJson("/api/orden-items/{$item->id}/ficha-especial", ['ficha' => $this->ficha()])
            ->assertOk()->assertJsonPath('falta_en_ficha', null);

        $r = $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/firma-remota", $resumen)->assertCreated();
        $token = $r->json('token');
        $this->getJson("/api/public/anexos/{$token}")->assertOk()
            ->assertJsonPath('declaracion.productos.0.bocetos.0', 'https://res.cloudinary.com/x/boceto1.jpg');

        // Si le cambian la ficha mientras tanto, ese enlace ya no se firma.
        $this->actingAs($v)->patchJson("/api/orden-items/{$item->id}/ficha-especial", ['ficha' => $this->ficha(['madera' => 'Pino'])])->assertOk();
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertStatus(409);

        // Con la ficha como iba, sí; y la orden queda con su firma y sin nada pendiente.
        $this->actingAs($v)->patchJson("/api/orden-items/{$item->id}/ficha-especial", ['ficha' => $this->ficha()])->assertOk();
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();
        $orden->refresh();
        $this->assertSame('https://res.cloudinary.com/x/firma.png', $orden->firma_url);
        $this->assertNull(DeclaracionEspeciales::faltaEnOrden($orden));

        // El detalle de la orden lo dice: nada falta.
        $this->actingAs($v)->getJson("/api/ordenes/{$orden->id}")->assertOk()
            ->assertJsonPath('declaracion.falta', null)
            ->assertJsonPath('declaracion.items.0', $item->id);
    }

    public function test_cambiar_la_ficha_de_una_ya_firmada_pide_firmar_otra_vez(): void
    {
        $orden = $this->ordenEsperandoFirma($this->ficha());
        $v = Usuario::find($orden->vendedor_id);
        $r = $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/firma-remota", ['resumen' => ['total' => 2500000]])->assertCreated();
        $this->postJson('/api/public/anexos/' . $r->json('token') . '/firmar', $this->respuestas())->assertOk();
        $this->assertNull(DeclaracionEspeciales::faltaEnOrden($orden->fresh()));

        $item = $orden->items()->first();
        $res = $this->actingAs($v)->patchJson("/api/orden-items/{$item->id}/ficha-especial", ['ficha' => $this->ficha(['alto' => '220 cm'])])->assertOk();
        $this->assertStringContainsString('cambiaron', $res->json('falta'));
        $this->assertSame(1, DB::table('orden_mensajes')->where('orden_id', $orden->id)->where('mensaje', 'like', '%ficha%')->count());

        // Ya firmada la orden, se puede mandar otra vez solo por la declaración.
        $this->actingAs($v)->postJson("/api/ordenes/{$orden->id}/firma-remota", ['resumen' => ['total' => 2500000]])->assertCreated();
    }

    public function test_otro_vendedor_no_cambia_la_ficha(): void
    {
        $orden = $this->ordenEsperandoFirma($this->ficha());
        DB::table('usuarios')->update(['ve_todas_ordenes' => false]);
        $otro = Usuario::create(['nombre' => 'Otro', 'email' => 'o@d.com', 'password' => 'x', 'rol' => 'vendedor',
            'tienda_default_id' => 2, 've_todas_ordenes' => false, 'created_at' => now()]);

        $this->actingAs($otro)->patchJson('/api/orden-items/' . $orden->items()->first()->id . '/ficha-especial', ['ficha' => $this->ficha()])
            ->assertForbidden();
    }

    // ── El cliente pide un cambio ─────────────────────────────────────────────

    public function test_el_cliente_pide_un_cambio_en_vez_de_firmar(): void
    {
        $v = $this->vendedor();
        $r = $this->actingAs($v)->postJson('/api/anexos', ['cliente_id' => 1, 'modo' => 'remoto', 'especiales' => $this->especiales()])->assertCreated();
        $token = $r->json('token');

        $this->postJson("/api/public/anexos/{$token}/cambio", ['mensaje' => ''])->assertStatus(422);
        $this->postJson("/api/public/anexos/{$token}/cambio", ['mensaje' => 'La quiero de 230 de ancho, no 240.'])->assertOk();

        // El vendedor lo ve en lo que está esperando y le llega el aviso.
        $this->actingAs($v)->getJson('/api/anexos/' . $r->json('id'))->assertOk()
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('cambio_pedido', 'La quiero de 230 de ancho, no 240.');
        $this->assertSame(1, DB::table('notificaciones')->where('usuario_id', $v->id)->where('tipo', 'anexo_cambio')->count());
        $this->getJson("/api/public/anexos/{$token}")->assertJsonPath('cambio_pedido', 'La quiero de 230 de ancho, no 240.');

        // Ya firmado no se pide cambio por aquí.
        $this->postJson("/api/public/anexos/{$token}/firmar", $this->respuestas())->assertOk();
        $this->postJson("/api/public/anexos/{$token}/cambio", ['mensaje' => 'otra cosa'])->assertStatus(422);
    }

    // ── Recargar la página mientras se espera la firma ────────────────────────

    public function test_al_recargar_nueva_orden_retoma_la_espera_con_su_declaracion(): void
    {
        $v = $this->vendedor();
        $r = $this->actingAs($v)->postJson('/api/anexos', [
            'cliente_id' => 1, 'modo' => 'remoto', 'especiales' => $this->especiales(),
            'resumen' => ['total' => 2500000, 'items' => [['nombre' => 'Biblioteca', 'cantidad' => 1, 'precio' => 2500000]]],
        ])->assertCreated();

        // La pantalla se recargó: al escoger el cliente pregunta qué hay en curso.
        $this->actingAs($v)->getJson('/api/anexos/en-curso?cliente_id=1')->assertOk()
            ->assertJsonPath('id', $r->json('id'))
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('declaracion', true)
            ->assertJsonPath('productos_declarados.0', 'Biblioteca a la medida')
            ->assertJsonPath('url', $r->json('url'));

        // El cliente firma con la pantalla cerrada: al volver, ahí está, y la orden se crea con esa firma.
        $this->postJson('/api/public/anexos/' . $r->json('token') . '/firmar', $this->respuestas())->assertOk();
        $this->actingAs($v)->getJson('/api/anexos/en-curso?cliente_id=1')->assertOk()
            ->assertJsonPath('estado', 'firmado');
        $this->crearOrden($v, ['anexo_id' => $r->json('id'), 'canal' => 'whatsapp', 'firma_url' => null, 'anexo_foto_url' => null])->assertCreated();
    }

    // ── Documentos ────────────────────────────────────────────────────────────

    public function test_la_declaracion_firmada_sale_en_pdf_para_el_vendedor_y_el_cliente(): void
    {
        $v  = $this->vendedor();
        $id = $this->anexoFirmado($v);
        $token = AnexoGarantia::find($id)->token;

        $pdf = $this->actingAs($v)->get("/api/anexos/{$id}/declaracion/pdf");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $this->get("/api/public/anexos/{$token}/pdf/declaracion")->assertOk();
        $this->get("/api/public/anexos/{$token}/pdf/anexo")->assertOk();
        // El PDF hace set_time_limit(120): no puede matar las pruebas siguientes.
        set_time_limit(0);
    }

    public function test_sin_firmar_no_hay_documento_publico(): void
    {
        $r = $this->actingAs($this->vendedor())->postJson('/api/anexos', ['cliente_id' => 1, 'modo' => 'remoto', 'especiales' => $this->especiales()])->assertCreated();

        $this->get('/api/public/anexos/' . $r->json('token') . '/pdf/declaracion')->assertNotFound();
    }

    public function test_el_texto_se_edita_en_gestion_y_cada_firma_guarda_su_version(): void
    {
        (require base_path('database/migrations/2026_10_22_000001_crear_plantillas_documento.php'))->up();
        $v  = $this->vendedor();
        $viejo = $this->anexoFirmado($v);

        $jefa = Usuario::create(['nombre' => 'Jefa', 'email' => 'j@d.com', 'password' => 'x', 'rol' => 'supervisor', 'created_at' => now()]);
        $texto = DeclaracionEspeciales::vigente();
        unset($texto['version']);
        $texto['constancia'] = 'Texto nuevo que pidió el abogado.';
        $this->actingAs($jefa)->putJson('/api/plantillas-documento/declaracion_especiales', ['contenido' => $texto])
            ->assertOk()->assertJsonPath('cambio', true);

        // Uno nuevo se firma con el texto nuevo...
        $r = $this->actingAs($v)->postJson('/api/anexos', ['cliente_id' => 1, 'modo' => 'remoto', 'especiales' => $this->especiales()])->assertCreated();
        $this->getJson('/api/public/anexos/' . $r->json('token'))
            ->assertJsonPath('declaracion.contenido.constancia', 'Texto nuevo que pidió el abogado.');

        // ...y el firmado antes conserva el suyo.
        $this->assertSame('fabrica', AnexoGarantia::find($viejo)->declaracion_version);
        $this->assertStringContainsString('suministré las medidas',
            DeclaracionEspeciales::deVersion(AnexoGarantia::find($viejo)->declaracion_version)['constancia']);

        // Gestión cuenta cuántas se firmaron con cada versión.
        $hist = $this->actingAs($jefa)->getJson('/api/plantillas-documento/declaracion_especiales')->assertOk()->json('historial');
        $this->assertSame(1, collect($hist)->firstWhere('version', 'fabrica')['firmados']);
    }
}
