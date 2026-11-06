<?php

namespace App\Support;

/**
 * Unidad canónica de un material (`materiales.unidad_norm`): la que ve el cotizador
 * para decir cuántas láminas, metros o tablas lleva un mueble.
 *
 * La migración `2026_07_14_120000_normalizar_materiales` la llenó una vez, y desde
 * entonces nadie la ponía al día: un material creado en la app quedaba sin ella, y uno al
 * que le cambiaban "METRO" por "LAMINA" seguía en `metro` para la IA mientras el costo se
 * multiplicaba por el precio de la lámina. Mismo mapa que la migración, más las palabras
 * con que empiezan las unidades escritas a mano ("LAMINA DE 18 MM" → lamina).
 */
class UnidadMaterial
{
    /** Igual que en la migración: unidad escrita → unidad canónica */
    private const MAPA = [
        'lamina'   => ['LAMINA', 'LAMINAS', 'LAMINA 3 x 1,22 MTS', 'LAMINA DE 12 "', 'LAMINA DE 15 M,M',
                       'LAMINA DE 18 M,M', 'LAMINA DE 2,44 X 1,22', 'LAMINA DE 244 X 122',
                       'LAMINA DE 3,00 X 122', 'LAMINA DE TRIPLEX DE 4 M.M', 'LAMINA EN CEDRO',
                       'LAMINAS DE 122 X 244', 'LAMINAS DE 244 X 151'],
        'sabana'   => ['SABANA', 'SABANAS'],
        'metro'    => ['METRO', 'METROS', 'MTS', 'MRTROS', 'METROS DE 44'],
        'tabla'    => ['TABLA', 'TABLAS', 'TABLA DE PINO 33 X 14 X 396', 'TABLAS DE 33 X 14 X 396',
                       'TABLAS DE PINO', 'TABLAS DE SAJO', 'JUEGO DE TABLAS'],
        'telera'   => ['TELERA', 'TELERAS', 'TELERAS DE PINO IMPORTADO'],
        'tira'     => ['TIRA', 'TIRAS', 'TIRAS DE 33 X 14'],
        'pulgada'  => ['PULGADAS', 'PULGADAS DE CEDRO'],
        'botella'  => ['BOTELLA', 'BOTELLAS', 'BOTELLA DE PL'],
        'juego'    => ['JUEGO', 'JUEGOS', 'JUEGO    PINTADA', 'JUEGO PINTADA', 'JUEGO DE', 'JUEGOS CON TUERCAS',
                       'JUEGOS DE 35', 'JUEGOS DE 40', 'C-15 (juego x 2 unidades)',
                       'CLIK CLAK (juego x 2 unidades)', 'PARES'],
        'bolsa'    => ['BOLSAS'],
        'carril'   => ['CARRILES'],
        'tornillo' => ['TORNILLOS', 'TORNILLOS 2"'],
        'piel'     => ['PIELEAS', 'PIELES'],
        'unidad'   => ['UNIDAD', 'UNIDADES', 'UNIDADES CON TUERCAS', 'BOTONES', 'BOMBILLOS', 'BRAZOS',
                       'MANIJAS', 'PATAS', 'TUBINO', 'TUBINOS', 'CONICAS', 'ESPEJO', 'VIDRIO',
                       'VIDRIO 10 M.M', 'HERRAJE', 'HERRAJE PARA CAMA', 'HIERRO', 'ESQUELETO',
                       'BASE', 'BASE CAMA', 'TOMA CON PUERTOS', 'TRONCO', 'COMPLETO', 'CORTE',
                       'MADERA', 'COMPRA ARMADA', 'COMPRA BOGOTA'],
    ];

    /** Primera palabra de una unidad escrita a mano → canónica (sin tildes, en mayúscula). */
    private const PRIMERA_PALABRA = [
        'LAMINA' => 'lamina', 'LAMINAS' => 'lamina',
        'SABANA' => 'sabana', 'SABANAS' => 'sabana',
        'METRO' => 'metro', 'METROS' => 'metro', 'MTS' => 'metro', 'MT' => 'metro', 'M' => 'metro',
        'TABLA' => 'tabla', 'TABLAS' => 'tabla',
        'TELERA' => 'telera', 'TELERAS' => 'telera',
        'TIRA' => 'tira', 'TIRAS' => 'tira',
        'PULGADA' => 'pulgada', 'PULGADAS' => 'pulgada',
        'BOTELLA' => 'botella', 'BOTELLAS' => 'botella',
        'JUEGO' => 'juego', 'JUEGOS' => 'juego', 'PAR' => 'juego', 'PARES' => 'juego',
        'BOLSA' => 'bolsa', 'BOLSAS' => 'bolsa',
        'CARRIL' => 'carril', 'CARRILES' => 'carril',
        'TORNILLO' => 'tornillo', 'TORNILLOS' => 'tornillo',
        'PIEL' => 'piel', 'PIELES' => 'piel',
        'UNIDAD' => 'unidad', 'UNIDADES' => 'unidad', 'UND' => 'unidad', 'UN' => 'unidad', 'U' => 'unidad',
    ];

    /** null si no hay unidad (el cotizador cae a "unidad"); 'otro' si no se reconoce. */
    public static function normalizar(?string $unidad): ?string
    {
        $u = trim((string) $unidad);
        if ($u === '') return null;

        $clave = self::clave($u);
        foreach (self::MAPA as $norm => $variantes) {
            foreach ($variantes as $v) {
                if (self::clave($v) === $clave) return $norm;
            }
        }

        $primera = strtok($clave, ' .,(');
        return self::PRIMERA_PALABRA[$primera] ?? 'otro';
    }

    /** Mayúsculas, sin tildes y con un solo espacio entre palabras. */
    private static function clave(string $s): string
    {
        $s = strtr(mb_strtoupper($s), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
