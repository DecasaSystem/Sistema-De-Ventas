<?php

namespace Tests\Unit;

use App\Http\Controllers\ComisionController;
use Tests\TestCase;

/**
 * Listas y su Excel dicen en palabras de dónde sale cada peso. Si aparece una
 * forma de pago nueva y nadie le escribe su explicación, la pantalla solo
 * mostraría la cifra y otra vez habría que preguntar "¿y esto por qué?"
 * (dueño, 2026-11-04). Esta prueba lee el archivo del front y exige que diga
 * las mismas formas que el cálculo.
 */
class ExplicarListasCubreTodoTest extends TestCase
{
    public function test_cada_forma_de_pago_tiene_su_explicacion_en_listas(): void
    {
        $archivo = base_path('../decasa-app/src/utils/explicarListas.js');
        if (! is_file($archivo)) {
            $this->markTestSkipped('Sin el front al lado (el contenedor de Render solo trae la API).');
        }

        $js = file_get_contents($archivo);
        $this->assertMatchesRegularExpression('/FORMAS_EXPLICADAS = \[(.*?)\]/s', $js);
        preg_match('/FORMAS_EXPLICADAS = \[(.*?)\]/s', $js, $m);
        preg_match_all("/'([a-z_0-9]+)'/", $m[1], $formas);

        $this->assertEqualsCanonicalizing(ComisionController::FORMAS_DE_PAGO, $formas[1],
            'Una forma de pago nueva necesita su explicación en explicarListas.js (y en FORMAS_EXPLICADAS).');
    }
}
