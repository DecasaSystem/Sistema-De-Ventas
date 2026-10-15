<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\PlantillasDocumento;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Gestión → Documentos, las plantillas de campos: datos de la empresa,
 * mensajes de WhatsApp, condiciones de la cotización (los plazos de garantía
 * se prueban en GarantiaPosventaTest y el acta en EntregaPorProductoTest).
 *
 * El esquema se monta a mano: el historial de migraciones no corre en SQLite.
 */
class PlantillasDeGestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('usuarios', function (Blueprint $t) {
            $t->id(); $t->string('nombre'); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('rol')->nullable(); $t->boolean('activo')->default(true); $t->timestamp('created_at')->nullable();
        });
        (require base_path('database/migrations/2026_10_22_000001_crear_plantillas_documento.php'))->up();
    }

    private function usuario(string $rol): Usuario
    {
        return Usuario::create(['nombre' => ucfirst($rol), 'email' => "$rol@d.com", 'password' => 'x', 'rol' => $rol, 'created_at' => now()]);
    }

    public function test_la_app_lee_los_textos_y_solo_el_supervisor_los_cambia(): void
    {
        $vendedor = $this->usuario('vendedor');

        // Sin cambios: los de fábrica.
        $this->actingAs($vendedor)->getJson('/api/textos-del-negocio')->assertOk()
            ->assertJsonPath('empresa.nombre', 'Decasa')
            ->assertJsonPath('cotizacion.dias_vigencia', 15)
            ->assertJsonPath('acta.titulo', 'ACTA DE SATISFACCIÓN');

        $mensajes = PlantillasDocumento::vigente('mensajes_whatsapp')['contenido'];
        $mensajes['saludo_redes'] = "Hola {cliente}, te habla {asesor}.\n\n\n\nBienvenido a {empresa_completo}.";

        $this->actingAs($vendedor)->putJson('/api/plantillas-documento/mensajes_whatsapp', ['contenido' => $mensajes])->assertForbidden();
        $this->actingAs($vendedor)->getJson('/api/plantillas-documento')->assertForbidden();

        $jefa = $this->usuario('supervisor');
        $this->actingAs($jefa)->putJson('/api/plantillas-documento/mensajes_whatsapp', ['contenido' => $mensajes])
            ->assertOk()->assertJsonPath('cambio', true);
        \App\Support\CachesDePeticion::olvidarTodo();

        // Los renglones vacíos repetidos se dejan en uno.
        $this->actingAs($vendedor)->getJson('/api/textos-del-negocio')->assertOk()
            ->assertJsonPath('mensajes.saludo_redes', "Hola {cliente}, te habla {asesor}.\n\nBienvenido a {empresa_completo}.");

        // La lista de Gestión trae el esquema para armar el editor.
        $doc = $this->actingAs($jefa)->getJson('/api/plantillas-documento/empresa')->assertOk();
        $this->assertSame('nombre', $doc->json('campos.0.campo'));
        $this->assertSame('negocio', $doc->json('grupo'));
        $this->assertCount(7, $this->actingAs($jefa)->getJson('/api/plantillas-documento')->json());
    }

    public function test_el_mensaje_para_firmar_tiene_que_llevar_el_enlace(): void
    {
        $jefa = $this->usuario('supervisor');
        $mensajes = PlantillasDocumento::vigente('mensajes_whatsapp')['contenido'];
        $mensajes['firma_pedido'] = 'Hola {cliente}, firma tu pedido por favor.';

        $this->actingAs($jefa)->putJson('/api/plantillas-documento/mensajes_whatsapp', ['contenido' => $mensajes])
            ->assertStatus(422)->assertJsonValidationErrors('firma_pedido');

        // Ni la empresa sin nombre.
        $empresa = PlantillasDocumento::empresa();
        $empresa['nombre'] = '   ';
        $this->actingAs($jefa)->putJson('/api/plantillas-documento/empresa', ['contenido' => $empresa])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
    }

    public function test_un_dato_vacio_se_lleva_el_espacio_de_antes(): void
    {
        $t = 'Hola {cliente}, soy {asesor} de {empresa}.';
        $this->assertSame('Hola, soy Laura de Decasa.', PlantillasDocumento::conDatos($t, ['cliente' => '', 'asesor' => 'Laura', 'empresa' => 'Decasa']));
        $this->assertSame('Hola Ana, soy Laura de {empresa}.', PlantillasDocumento::conDatos($t, ['cliente' => 'Ana', 'asesor' => 'Laura']));
    }

    public function test_la_cotizacion_sale_con_las_condiciones_y_el_nombre_de_gestion(): void
    {
        PlantillasDocumento::guardar('empresa', ['nombre' => 'Muebles Norte', 'nombre_completo' => 'Muebles Norte S.A.S.', 'telefono' => '300 111 2233'], null);
        PlantillasDocumento::guardar('cotizacion', [
            'condiciones'   => "No incluye transporte fuera de Armenia.\nVálida hasta el {valida_hasta}.",
            'dias_vigencia' => 20,
        ], null);
        \App\Support\CachesDePeticion::olvidarTodo();

        $html = $this->cotizacionPdf(Carbon::parse('2026-11-04'));
        $this->assertStringContainsString('No incluye transporte fuera de Armenia.', $html);
        $this->assertStringContainsString('Válida hasta el 04/11/2026.', $html);
        $this->assertStringContainsString('Muebles Norte S.A.S. · 300 111 2233', $html);
        $this->assertStringNotContainsString('SODEGE', $html);
        $this->assertStringNotContainsString('no reserva mercancía', $html);

        // Sin fecha de validez, el renglón que la nombra no sale.
        $sinFecha = $this->cotizacionPdf(null);
        $this->assertStringNotContainsString('Válida hasta el', $sinFecha);
        $this->assertStringNotContainsString('{valida_hasta}', $sinFecha);

        // Los días por defecto los lee la app.
        $this->actingAs($this->usuario('vendedor'))->getJson('/api/textos-del-negocio')
            ->assertJsonPath('cotizacion.dias_vigencia', 20);
    }

    private function cotizacionPdf(?Carbon $validaHasta): string
    {
        $cotizacion = new Fluent([
            'id' => 1, 'cotizacion_ref' => 'COT-7', 'cotizacion_valida_hasta' => $validaHasta, 'esta_vencida' => false,
            'created_at' => now(), 'valor_total' => 0, 'descuento_total' => 0, 'notas' => null,
            'cliente' => new Fluent(['nombre' => 'Ana']), 'tienda' => new Fluent(['nombre' => 'Decasa Norte']),
            'vendedor' => new Fluent(['nombre' => 'Laura']), 'items' => collect(),
        ]);

        return view('pdf.cotizacion', ['cotizacion' => $cotizacion, 'firmaVendedor' => null, 'logoBase64' => null])->render();
    }
}
