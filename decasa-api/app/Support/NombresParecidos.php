<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * "¿Quisiste decir…?" para nombres de clientes.
 *
 * Quien busca una orden a veces no se acuerda bien del nombre, o lo escribe
 * como lo oyó: "Maira Gonsales" por "Mayra González". La búsqueda normal pide
 * el texto tal cual y no encuentra nada. Esto encuentra los nombres que se le
 * parecen.
 *
 * Se compara palabra por palabra, sin tildes ni mayúsculas: cada palabra
 * buscada tiene que parecerse a alguna del nombre, en el orden que sea
 * ("gonzales maria" encuentra "María González"). Cuánto se tolera depende del
 * largo: en una palabra de 3 letras una letra cambiada ya es otra palabra; en
 * una de 8, dos letras mal puestas siguen siendo un error de dedo.
 */
class NombresParecidos
{
    /**
     * @param  iterable<string>  $nombres  los candidatos
     * @return string[]  los parecidos, del más al menos parecido
     */
    public static function para(string $buscado, iterable $nombres, int $cuantos = 5): array
    {
        $palabras = self::palabras($buscado);
        // Palabras de una o dos letras ("de", "la", una inicial) no dicen nada
        // de a quién se busca y harían parecido a medio mundo.
        $palabras = array_values(array_filter($palabras, fn ($p) => mb_strlen($p) >= 3));
        if (! $palabras) return [];

        // Los nombres se repiten mucho por palabras: "González" está en cientos
        // de clientes. Cada palabra distinta se compara una sola vez y se
        // recuerda; si no, con miles de clientes la búsqueda tardaba segundos.
        $memo = [];

        $puntajes = [];
        foreach ($nombres as $nombre) {
            $nombre = trim((string) $nombre);
            if ($nombre === '') continue;

            $delNombre = self::palabras($nombre);
            $clave     = implode(' ', $delNombre);
            if ($clave === '' || isset($puntajes[$clave])) continue;   // el mismo nombre dos veces

            $puntaje = self::puntaje($palabras, $delNombre, $memo);
            if ($puntaje !== null) {
                $puntajes[$clave] = ['nombre' => $nombre, 'puntaje' => $puntaje];
            }
        }

        uasort($puntajes, fn ($a, $b) => $a['puntaje'] <=> $b['puntaje'] ?: strcmp($a['nombre'], $b['nombre']));

        return array_slice(array_column(array_values($puntajes), 'nombre'), 0, $cuantos);
    }

    /**
     * Qué tan lejos está el nombre de lo buscado (0 = igual), o null si no se
     * parece: alguna palabra buscada no tiene ninguna parecida en el nombre.
     */
    private static function puntaje(array $buscadas, array $delNombre, array &$memo): ?float
    {
        if (! $delNombre) return null;

        $total = 0;
        foreach ($buscadas as $b) {
            $tope  = self::tolerancia($b);
            $mejor = null;
            foreach ($delNombre as $n) {
                $d = $memo[$b][$n] ??= self::cercania($b, $n, $tope);
                $mejor = $mejor === null ? $d : min($mejor, $d);
                if ($mejor === 0) break;
            }
            if ($mejor > $tope) return null;
            $total += $mejor;
        }

        // Entre dos igual de parecidos, primero el que no tiene palabras de
        // más: "Ana Ruiz" antes que "Ana Ruiz de la Torre" para "ana ruis".
        return $total + max(0, count($delNombre) - count($buscadas)) * 0.1;
    }

    /**
     * Qué tan cerca está la palabra buscada `$b` de una del nombre `$n`.
     * Pasado el tope da igual cuánto: ya no se parece.
     */
    private static function cercania(string $b, string $n, int $tope): int
    {
        // Escribió el comienzo de la palabra ("gonz"): es ella.
        if (str_starts_with($n, $b)) return 0;

        $mejor = $tope + 1;
        // Con más letras de diferencia que el tope, ni se calcula entera.
        if (abs(strlen($n) - strlen($b)) <= $tope) {
            $mejor = self::distancia($b, $n);
        }
        // O la escribió a medias y con un error ("gonsal" → "gonzalez").
        if ($mejor > 0 && strlen($n) > strlen($b)) {
            $mejor = min($mejor, self::distancia($b, substr($n, 0, strlen($b))));
        }

        return $mejor;
    }

    /**
     * Cuántas letras hay que tocar para pasar de una palabra a la otra:
     * cambiar, poner o quitar una, o invertir dos seguidas. Lo último es lo
     * que la distancia de siempre (levenshtein) cuenta como dos, y es el error
     * de dedo más común: "Jhon" / "John", "Pérze" / "Pérez".
     */
    private static function distancia(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la === 0) return $lb;
        if ($lb === 0) return $la;

        $d = [];
        for ($i = 0; $i <= $la; $i++) $d[$i][0] = $i;
        for ($j = 0; $j <= $lb; $j++) $d[0][$j] = $j;

        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $costo = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,           // quitar
                    $d[$i][$j - 1] + 1,           // poner
                    $d[$i - 1][$j - 1] + $costo,  // cambiar
                );
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);   // invertir dos
                }
            }
        }

        return $d[$la][$lb];
    }

    /** Cuántas letras mal se aceptan según el largo de la palabra. */
    private static function tolerancia(string $palabra): int
    {
        $largo = mb_strlen($palabra);
        if ($largo <= 3) return 0;
        if ($largo <= 5) return 1;
        if ($largo <= 8) return 2;
        return 3;
    }

    /** "  María  JOSÉ-Pérez " → ["maria", "jose", "perez"] */
    private static function palabras(string $texto): array
    {
        $limpio = strtolower(Str::ascii($texto));
        $limpio = preg_replace('/[^a-z0-9]+/', ' ', $limpio);
        return array_values(array_filter(explode(' ', trim($limpio)), fn ($p) => $p !== ''));
    }
}
