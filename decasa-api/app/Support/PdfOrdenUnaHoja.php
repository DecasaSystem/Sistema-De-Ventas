<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * La orden en PDF, lo más grande que quepa en UNA hoja carta.
 *
 * El problema es que el largo del contenido no tiene techo: una orden con
 * tres ítems y descripciones largas ocupa más que una de siete ítems
 * escuetos, así que ningún tamaño de letra fijo sirve — o se ve chiquito
 * para la mayoría, o se va a dos hojas para unas pocas.
 *
 * En vez de elegir un tamaño, se prueban varios de mayor a menor y se deja
 * el primero que quepa. La escala se aplica por el DPI de DomPDF, que
 * convierte los px de CSS a puntos (pt = px × 72 / dpi): bajarlo agranda
 * TODO de forma pareja — letra, márgenes, tablas, firmas y el logo — sin
 * tener que tocar la plantilla ni arriesgarse a descuadrarla.
 */
class PdfOrdenUnaHoja
{
    /**
     * El DPI con el que DomPDF interpreta los px de CSS por defecto. A 96,
     * la plantilla se ve como se diseñó originalmente.
     */
    private const DPI_BASE = 96;

    /**
     * De más grande a más chico. El primero es el que se lleva casi todas
     * las órdenes; los últimos son la red de seguridad para las que traen
     * mucho texto, que antes simplemente se iban a la segunda hoja.
     */
    private const ESCALAS = [1.20, 1.12, 1.06, 1.00, 0.94, 0.88];

    /**
     * @param  array<string, mixed>  $datos  Lo que la vista necesita.
     * @return array{0: \Barryvdh\DomPDF\PDF, 1: float} El PDF y la escala usada.
     */
    public static function generar(array $datos): array
    {
        $ultimo = null;
        $ultimaEscala = 1.0;

        foreach (self::ESCALAS as $escala) {
            $pdf = Pdf::loadView('pdf.orden', $datos);
            $pdf->setPaper('letter');
            // Menos DPI = más puntos por px = todo más grande.
            $pdf->setOption('dpi', self::DPI_BASE / $escala);
            $pdf->getDomPDF()->render();

            $ultimo = $pdf;
            $ultimaEscala = $escala;

            if ($pdf->getDomPDF()->getCanvas()->get_page_count() <= 1) {
                break;
            }
        }

        // Si ni la más chica cupo, se devuelve igual: una orden enorme en dos
        // hojas es mejor que ninguna, y achicar más la dejaría ilegible.
        return [$ultimo, $ultimaEscala];
    }
}
