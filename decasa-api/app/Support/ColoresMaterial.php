<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Colores para marcar materiales y lo que significa cada uno (usuario, 2026-11-10: "un
 * color que represente cosas, por ejemplo falta actualizar el precio").
 *
 * Los colores son fijos (la pantalla sabe pintarlos); lo que significa cada uno lo escribe
 * quien maneja Costos y vive en `configuracion.materiales_colores` (JSON color => texto).
 * Un color con el significado vacío se puede usar igual.
 */
class ColoresMaterial
{
    public const COLORES = ['rojo', 'ambar', 'verde', 'azul', 'morado', 'gris'];

    public const CLAVE = 'materiales_colores';

    /** Lo que trae de fábrica: solo un ejemplo, se cambia en la pantalla. */
    private const DE_FABRICA = [
        'rojo'   => 'Falta actualizar el precio',
        'ambar'  => 'Por revisar',
        'verde'  => 'Precio al día',
        'azul'   => '',
        'morado' => '',
        'gris'   => '',
    ];

    /** @return array<string, string> color => significado, en el orden de COLORES */
    public static function leyenda(): array
    {
        $guardado = json_decode((string) DB::table('configuracion')->where('clave', self::CLAVE)->value('valor'), true);
        $guardado = is_array($guardado) ? $guardado : [];

        $out = [];
        foreach (self::COLORES as $c) {
            $out[$c] = array_key_exists($c, $guardado) ? (string) $guardado[$c] : self::DE_FABRICA[$c];
        }

        return $out;
    }

    /** @param array<string, ?string> $significados solo los colores conocidos; los demás se ignoran */
    public static function guardar(array $significados): array
    {
        $leyenda = self::leyenda();
        foreach (self::COLORES as $c) {
            if (array_key_exists($c, $significados)) $leyenda[$c] = trim((string) $significados[$c]);
        }

        DB::table('configuracion')->updateOrInsert(
            ['clave' => self::CLAVE],
            ['valor' => json_encode($leyenda, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );

        return $leyenda;
    }
}
