<?php

namespace App\Support;

/**
 * Departamentos y municipios con el nombre EXACTO que acepta Alegra.
 *
 * Alegra valida la ciudad y el departamento del cliente contra su catálogo de
 * Colombia (developer.alegra.com/reference/colombia, sección "Departamentos y
 * municipios"); con "quindio" o "Armenia Q." rechaza el contacto y la factura
 * no sale. `resources/data/municipios-alegra.json` es ese catálogo tal cual
 * (2026-11-11: 33 departamentos, 1.123 municipios).
 *
 * La pantalla usa la lista del DANE (`DireccionColombia`, la de Nueva orden),
 * que en unos pocos nombres es distinta ("Norte de Santander" ↔ "N. de
 * Santander", "Cartagena" ↔ "Cartagena de Indias"): aquí se traduce, sin
 * tildes ni mayúsculas, y el nombre corto vale si no hay duda.
 */
class MunicipiosAlegra
{
    /** Departamentos que el DANE llama distinto. */
    private const ALIAS_DEPTOS = [
        'norte de santander'                                  => 'N. de Santander',
        'san andres y providencia'                            => 'San Andrés',
        'archipielago de san andres providencia y santa catalina' => 'San Andrés',
        'bogota'                                              => 'Bogotá D.C.',
        'bogota dc'                                           => 'Bogotá D.C.',
        'bogota d c'                                          => 'Bogotá D.C.',
        'bogota distrito capital'                             => 'Bogotá D.C.',
        'guainia'                                             => 'Guainia',
    ];

    private static ?array $catalogo = null;

    /** @return array<string, string[]> */
    public static function catalogo(): array
    {
        if (self::$catalogo !== null) return self::$catalogo;
        $ruta = resource_path('data/municipios-alegra.json');

        return self::$catalogo = is_file($ruta) ? (json_decode((string) file_get_contents($ruta), true) ?: []) : [];
    }

    /** "Quindío" para "quindio", "QUINDÍO "…; null si no está. */
    public static function departamento(?string $texto): ?string
    {
        $n = self::normal((string) $texto);
        if ($n === '') return null;
        if (isset(self::ALIAS_DEPTOS[$n])) return self::ALIAS_DEPTOS[$n];
        foreach (array_keys(self::catalogo()) as $d) {
            if (self::normal($d) === $n) return $d;
        }

        return null;
    }

    /**
     * El municipio con su nombre de Alegra dentro de ese departamento; null si no
     * está o si el texto corto sirve para más de uno.
     */
    public static function municipio(?string $departamento, ?string $texto): ?string
    {
        $dep = self::departamento($departamento);
        $n   = self::normal((string) $texto);
        if (! $dep || $n === '') return null;

        $lista = self::catalogo()[$dep] ?? [];
        foreach ($lista as $m) {
            if (self::normal($m) === $n) return $m;
        }
        // "Cartagena" → "Cartagena de Indias", "Buga" → "Guadalajara de Buga".
        $parecidos = array_values(array_filter($lista, fn ($m) => preg_match('/\b' . preg_quote($n, '/') . '\b/', self::normal($m))));
        if (count($parecidos) === 1) return $parecidos[0];
        // Bogotá D.C. tiene un solo municipio: cualquier forma de escribirlo es ese.
        if (count($lista) === 1 && str_contains(self::normal($lista[0]), $n)) return $lista[0];

        return null;
    }

    private static function normal(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
