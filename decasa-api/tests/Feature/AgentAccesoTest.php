<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\AgentService;
use Tests\TestCase;

/**
 * El asistente de IA consulta ventas, caja, producción y padrón de
 * trabajadores. Esas pantallas se le niegan a un conductor o a un ebanista, y
 * el chat no puede ser la puerta lateral que se salte esa regla.
 */
class AgentAccesoTest extends TestCase
{
    public function test_el_asistente_solo_atiende_a_ventas_y_supervision(): void
    {
        foreach (['conductor', 'ebanista', 'despachador', 'costurero', 'rol_inventado'] as $rol) {
            $usuario = new Usuario(['nombre' => 'X', 'rol' => $rol]);

            $respuesta = app(AgentService::class)->chat(
                [['role' => 'user', 'content' => '¿cuánto hay en la caja de todas las tiendas?']],
                $usuario
            );

            $this->assertStringContainsString(
                'solo está disponible',
                $respuesta,
                "El rol '{$rol}' no debería poder usar el asistente."
            );
        }
    }
}
