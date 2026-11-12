<?php

namespace App\Services\Alegra;

use App\Support\Esquema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Qué producto de Alegra le corresponde a cada mueble del sistema.
 *
 * Así factura Decasa hoy (FEN1917): el ítem es el mueble ("SOFA TELAVID") y la
 * descripción es la variante ("TAUPE"). Cada mueble ya existe en Alegra, con
 * inventario por bodega; el sistema no crea productos allá, los empareja:
 *  1. Lo ya emparejado (tabla alegra_productos) manda.
 *  2. Si no, se busca en Alegra por el nombre (sin tildes ni mayúsculas:
 *     "Sofá Telavid" = "SOFA TELAVID"). Si hay exactamente uno, ese es.
 *  3. Si no hay ninguno o hay varios, el facturador lo escoge en la revisión
 *     y queda guardado para las siguientes facturas.
 */
class ProductosEnAlegra
{
    public function __construct(private ClienteAlegra $alegra) {}

    /** @return array{id: string, nombre: ?string, origen: string}|null lo ya guardado */
    public static function guardado(int $productoId): ?array
    {
        if (! Esquema::tabla('alegra_productos')) return null;
        $f = DB::table('alegra_productos')->where('producto_id', $productoId)->first();

        return $f ? ['id' => (string) $f->alegra_item_id, 'nombre' => $f->alegra_nombre, 'origen' => $f->origen] : null;
    }

    public static function guardar(int $productoId, string $itemId, ?string $nombre, string $origen, ?int $usuarioId): void
    {
        if (! Esquema::tabla('alegra_productos')) return;
        DB::table('alegra_productos')->updateOrInsert(
            ['producto_id' => $productoId],
            ['alegra_item_id' => $itemId, 'alegra_nombre' => $nombre, 'origen' => $origen,
             'usuario_id' => $usuarioId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * El producto de Alegra para este mueble.
     *
     * @return array{id: ?string, nombre: ?string, origen: string, candidatos: array}
     *   origen: guardado | automatico | no_esta | varios | sin_conexion
     */
    public function para(int $productoId, string $nombreSistema): array
    {
        if ($g = self::guardado($productoId)) {
            return ['id' => $g['id'], 'nombre' => $g['nombre'], 'origen' => 'guardado', 'candidatos' => []];
        }

        $encontrados = $this->buscar($nombreSistema);
        if ($encontrados === null) {
            return ['id' => null, 'nombre' => null, 'origen' => 'sin_conexion', 'candidatos' => []];
        }

        $buscado = self::normal($nombreSistema);
        $exactos = array_values(array_filter($encontrados, fn ($i) => self::normal($i['nombre']) === $buscado));
        if (count($exactos) === 1) {
            return ['id' => $exactos[0]['id'], 'nombre' => $exactos[0]['nombre'], 'origen' => 'automatico', 'candidatos' => []];
        }

        return [
            'id' => null, 'nombre' => null,
            'origen' => $exactos ? 'varios' : 'no_esta',
            'candidatos' => array_slice($exactos ?: $encontrados, 0, 8),
        ];
    }

    /** Productos activos de Alegra que se parecen al nombre (10 min de memoria). */
    public function buscar(string $texto): ?array
    {
        $texto = trim($texto);
        if ($texto === '' || ! ClienteAlegra::configurado()) return null;

        $clave = 'alegra_buscar_item_' . md5(self::normal($texto));
        if (is_array($g = Cache::get($clave))) return $g;

        try {
            $r = $this->alegra->get('items', ['query' => $texto, 'status' => 'active', 'limit' => 30]);
        } catch (SinRespuestaDeAlegra) {
            return null;
        }
        if (! $r->ok || ! is_array($r->json)) return null;

        $lista = array_values(array_map(fn ($i) => [
            'id' => (string) ($i['id'] ?? ''), 'nombre' => (string) ($i['name'] ?? ''),
            'referencia' => $i['reference'] ?? null,
        ], array_filter($r->json, fn ($i) => is_array($i) && isset($i['id']) && CatalogoAlegra::activo($i))));
        Cache::put($clave, $lista, now()->addMinutes(10));

        return $lista;
    }

    public static function normal(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
