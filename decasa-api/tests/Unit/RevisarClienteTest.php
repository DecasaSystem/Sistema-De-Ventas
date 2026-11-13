<?php

namespace Tests\Unit;

use App\Services\Facturacion\RevisarCliente;
use PHPUnit\Framework\TestCase;

/** Los datos del cliente mal escritos que la DIAN rechaza (2026-11-13, la #4421). */
class RevisarClienteTest extends TestCase
{
    private function codigos(array $c): array
    {
        return array_column(RevisarCliente::problemas($c), 'codigo');
    }

    private function de(array $c, string $codigo): ?array
    {
        foreach (RevisarCliente::problemas($c) as $p) if ($p['codigo'] === $codigo) return $p;
        return null;
    }

    public function test_la_4421_empresa_con_cedula_y_el_dv_pegado(): void
    {
        $p = $this->de(['nombre' => 'INVERSIONES CONVERSA SAS', 'documento' => '9006232083', 'tipo_documento' => 'CC', 'tipo_persona' => 'natural'], 'cliente_empresa');

        $this->assertNotNull($p);
        $this->assertSame('advertencia', $p['tipo']);
        $this->assertSame(['tipo_documento' => 'NIT', 'tipo_persona' => 'juridica', 'cedula' => '900623208', 'dv' => '3'], $p['arreglo']['campos']);
        $this->assertStringContainsString('900623208-3', $p['arreglo']['texto']);
    }

    public function test_reconoce_nombres_de_empresa(): void
    {
        foreach (['Muebles El Roble S.A.S.', 'ACME LTDA', 'Constructora Andina S.A.', 'Cooperativa de Caficultores', 'Fundación Sonrisas', 'Grupo XYZ S A S', 'Tienda Juan E.U.'] as $n) {
            $this->assertTrue(RevisarCliente::pareceEmpresa($n), $n);
        }
        foreach (['Ana María Gómez Ruiz', 'Sasha Pérez', 'Juan Sanabria', 'Luisa Fernanda Saa'] as $n) {
            $this->assertFalse(RevisarCliente::pareceEmpresa($n), $n);
        }
    }

    public function test_empresa_bien_registrada_no_avisa(): void
    {
        $this->assertSame([], $this->codigos(['nombre' => 'ACME SAS', 'documento' => '900623208', 'tipo_documento' => 'NIT', 'dv' => '3', 'tipo_persona' => 'juridica']));
    }

    public function test_cedula_de_10_cifras_que_es_un_nit(): void
    {
        $p = $this->de(['nombre' => 'Conversa', 'documento' => '9006232083', 'tipo_documento' => 'CC', 'tipo_persona' => 'natural'], 'cedula_parece_nit');
        $this->assertNotNull($p);
        $this->assertSame('900623208', $p['arreglo']['campos']['cedula']);

        // Una cédula de verdad (10 cifras, empieza por 1) no.
        $this->assertSame([], $this->codigos(['nombre' => 'Ana Gómez', 'documento' => '1094888777', 'tipo_documento' => 'CC', 'tipo_persona' => 'natural']));
    }

    public function test_nit_con_el_dv_pegado_y_dv_malo(): void
    {
        $p = $this->de(['nombre' => 'ACME SAS', 'documento' => '9006232083', 'tipo_documento' => 'NIT', 'tipo_persona' => 'juridica'], 'nit_con_dv');
        $this->assertSame(['cedula' => '900623208', 'dv' => '3'], $p['arreglo']['campos']);

        $malo = $this->de(['nombre' => 'ACME SAS', 'documento' => '900623208', 'tipo_documento' => 'NIT', 'dv' => '7', 'tipo_persona' => 'juridica'], 'dv_malo');
        $this->assertSame('bloqueo', $malo['tipo']);
        $this->assertSame(['dv' => '3'], $malo['arreglo']['campos']);
    }

    public function test_juridica_con_cedula_y_cedulas_raras(): void
    {
        $this->assertContains('juridica_con_cedula', $this->codigos(['nombre' => 'Pedro', 'documento' => '12345678', 'tipo_documento' => 'CC', 'tipo_persona' => 'juridica']));
        $this->assertContains('cedula_con_letras', $this->codigos(['nombre' => 'Ana Gómez', 'documento' => '12A45', 'tipo_documento' => 'CC']));
        $this->assertContains('cedula_rara', $this->codigos(['nombre' => 'Ana Gómez', 'documento' => '123', 'tipo_documento' => 'CC']));
    }

    public function test_correo_y_telefono(): void
    {
        $p = $this->de(['nombre' => 'Ana Gómez', 'email' => 'ana@gmail.con'], 'correo_dudoso');
        $this->assertSame(['email' => 'ana@gmail.com'], $p['arreglo']['campos']);
        $this->assertContains('correo_malo', $this->codigos(['nombre' => 'Ana Gómez', 'email' => 'ana gmail.com']));
        $this->assertSame([], $this->codigos(['nombre' => 'Ana Gómez', 'email' => 'ana@gmail.com', 'telefono' => '300 123 4567']));

        $t = $this->de(['nombre' => 'Ana Gómez', 'telefono' => '3128251383 JUAN M'], 'telefono_con_texto');
        $this->assertSame('info', $t['tipo']);
        $this->assertSame(['telefono' => '3128251383'], $t['arreglo']['campos']);
    }
}
