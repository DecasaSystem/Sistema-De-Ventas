<?php

namespace Tests\Unit;

use App\Support\ReglasComision;
use Tests\TestCase;

/**
 * Las reglas de comisión valen lo mismo que valía el código antes de sacarlas
 * (Fase 0 de docs/plan-comisiones-personalizables.md). Si alguien cambia un
 * valor por defecto aquí, cambia la plata de todo el mundo: eso se hace con
 * una regla con fecha (Fase 1), no tocando el catálogo.
 *
 * Las cifras están escritas a mano a propósito: no se leen de las constantes.
 */
class ReglasComisionTest extends TestCase
{
    public function test_cada_regla_vale_lo_de_siempre(): void
    {
        $esperado = [
            'pct_pool'                  => 0.05,
            'pct_individual'            => 0.05,
            'pct_restauracion'          => 0.05,
            'iva_divisor'               => 1.19,
            'iva_en_venta'              => true,
            'iva_en_restauracion'       => false,
            'franquicia_pct'            => 0.055,
            'franquicia_metodos'        => ['tarjeta', 'addi'],
            'req_pagado_pct'            => 0.5,
            'req_pagado_restauracion'   => true,
            'meta_req_pagado_pct'       => 0.5,
            'dia_pago'                  => 20,
            'meses_espera'              => 1,
            'reparto_pool'              => 'dias',
            // Quien reemplaza entra al pool por días (lo de siempre); su % solo
            // rige si una tienda escoge "un porcentaje de lo que vendió".
            'reparto_reemplazo'         => 'dias',
            'pct_reemplazo'             => 0.05,
            'restauracion_suma_meta'    => false,
            'arrastra_deuda_trimestre'  => true,
            // Pieza 8: el pool de siempre (un % sobre lo que pasa de la meta), sin bono.
            'formula_pool'              => 'excedente',
            'tramos_pool'               => [['desde' => 1.0, 'pct' => 0.05]],
            'bono_meta'                 => 0,
            'compartida_parte_vendedor' => 0.5,
            'restauracion_se_reparte'   => true,
            'reparto_restauracion'      => 'iguales',
            'parte_autor_restauracion'  => 0.5,
            'indep_pct_venta'           => 0.05,
            'indep_pct_bolson'          => 0.05,
            'bolson_modo'               => 'cada_uno_completo',
            'entra_al_bolson'           => true,
            'bolson_incluye_almacenes'  => true,
            'almacen_pct'               => 0.05,
            'almacen_fraccion_meta'     => 0.5,
        ];

        // Ni una de más sin su valor de hoy, ni una de menos.
        $this->assertEqualsCanonicalizing(array_keys($esperado), array_keys(ReglasComision::CATALOGO));

        foreach ($esperado as $clave => $valor) {
            $this->assertSame($valor, ReglasComision::valor($clave, '2026-10', 5, 6), $clave);
        }
    }

    public function test_cada_regla_dice_que_es_y_donde_se_cambia(): void
    {
        foreach (ReglasComision::CATALOGO as $clave => $r) {
            $this->assertContains($r['tipo'], ['fraccion', 'numero', 'entero', 'si_no', 'lista', 'opcion', 'tramos', 'pesos'], $clave);
            if ($r['tipo'] === 'opcion') {
                // La primera opción es lo que hacía el código antes: es el valor por defecto.
                $this->assertSame(array_key_first($r['opciones']), $r['defecto'], $clave);
                foreach ($r['opciones'] as $o) $this->assertNotEmpty($o['nombre'] ?? null, $clave);
            }
            $this->assertNotEmpty($r['nombre'], $clave);
            $this->assertNotEmpty($r['explica'], $clave);
            $this->assertContains('general', $r['alcances'], $clave);
            $this->assertEmpty(array_diff($r['alcances'], ['general', 'tienda', 'usuario']), $clave);
        }
    }

    public function test_cada_regla_esta_en_un_grupo_de_la_pantalla(): void
    {
        $agrupadas = array_merge(...array_column(ReglasComision::GRUPOS, 'claves'));

        $this->assertEqualsCanonicalizing(array_keys(ReglasComision::CATALOGO), $agrupadas);
        $this->assertSame(count($agrupadas), count(array_unique($agrupadas)), 'ninguna en dos grupos');
        $this->assertEmpty(array_diff(ReglasComision::SE_VEN_AL_RECALCULAR, array_keys(ReglasComision::CATALOGO)));
    }

    public function test_quitar_el_iva_da_la_misma_cifra_que_antes(): void
    {
        // Venta: valor ÷ 1,19, al último decimal.
        $this->assertSame(4_200_000 / 1.19, ReglasComision::sinIva(4_200_000, false, '2026-10', 5));
        // Restauración: entera.
        $this->assertSame(1_800_000.0, ReglasComision::sinIva(1_800_000, true, '2026-10', 5));
    }

    public function test_el_literal_sql_es_decimal_y_nunca_cientifico(): void
    {
        // MySQL trata un literal como DECIMAL exacto (un parámetro sería DOUBLE).
        $this->assertSame('0.055', ReglasComision::literal(0.055));
        $this->assertSame('0.5', ReglasComision::literal(0.5));
        $this->assertSame('20', ReglasComision::literal(20));
        $this->assertSame('0', ReglasComision::literal(0));
        $this->assertSame('0.0000001', ReglasComision::literal(1e-7));
        $this->assertSame('0.055', ReglasComision::sql('franquicia_pct', 'c.tienda_id', 'c.mes_venta'));
    }

    public function test_una_regla_que_no_existe_no_se_inventa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReglasComision::valor('pct_que_no_existe', '2026-10');
    }
}
