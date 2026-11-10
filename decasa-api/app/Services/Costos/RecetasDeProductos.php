<?php

namespace App\Services\Costos;

use App\Services\CategoriasProducto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Costos conectado con Inventario: cada producto del catálogo con su receta.
 *
 * Pedido del usuario (2026-11-06): "en vez de meterlos manual dos veces, meterlo una vez en
 * inventario y que aparezca en costos", con la imagen, y los que aún no tienen materiales
 * "en espera". El producto es la fuente (nombre, foto, categoría, precio); la ficha técnica
 * es su receta. No se crean fichas vacías: un producto sin ficha sale "sin receta" y la
 * ficha nace cuando alguien le pone materiales. Así el cotizador, Márgenes y Finanzas no
 * ven fichas en $0.
 *
 * Versiones: una opción de un tipo de variante que cambia el precio (la cama de 1.40 y la
 * de 1.60) lleva su propia receta (`fichas_tecnicas.variante_config_id`), porque no todas
 * cuestan lo mismo (usuario, 2026-11-06). La que no la tiene está en espera: no se le
 * presta la receta general. La general (sin versión) es opcional: plantilla para copiar y
 * referencia del cotizador cuando no se sabe la medida. Una sola receta por producto y por
 * versión: con dos, Finanzas las promediaba y Márgenes las mostraba como dos productos.
 *
 * Las fichas que vinieron del Excel y no están enlazadas salen como "sueltas", con una
 * sugerencia del producto (y la medida) al que se parecen.
 */
class RecetasDeProductos
{
    /** Palabras que no dicen qué mueble es ("CAMA DE 1.40 EN PINO" ≈ "CAMA PINO"). */
    private const RELLENO = ['de', 'del', 'la', 'el', 'los', 'las', 'en', 'con', 'sin', 'y', 'para', 'por', 'x', 'a', 'mts', 'mt', 'm', 'cm'];

    /**
     * Lo que no se fabrica en el taller (usuario, 2026-11-10): "productos que son importados y
     * no sabemos sus costos". No quedan en espera de receta; se apartan con su origen.
     */
    public const ORIGENES = ['importado', 'comprado'];

    public static function hayVersiones(): bool
    {
        return Schema::hasColumn('fichas_tecnicas', 'variante_config_id');
    }

    public static function hayNoFabricados(): bool
    {
        return Schema::hasTable('productos_no_fabricados');
    }

    /**
     * Marca un producto como importado o comprado hecho (no se le calcula costo), o le quita
     * la marca con `$origen` null. Sus recetas, si tenía, no se tocan: si vuelve a fabricarse
     * siguen ahí.
     */
    public function marcarOrigen(int $productoId, ?string $origen, ?string $nota, ?int $usuarioId): void
    {
        if (! $origen) {
            DB::table('productos_no_fabricados')->where('producto_id', $productoId)->delete();
            return;
        }

        DB::table('productos_no_fabricados')->updateOrInsert(
            ['producto_id' => $productoId],
            [
                'origen'         => $origen,
                'nota'           => trim((string) $nota) ?: null,
                'marcado_por_id' => $usuarioId,
                'updated_at'     => now(),
                'created_at'     => now(),
            ],
        );
    }

    /**
     * Todo lo de la pestaña Productos de Costos: productos activos con sus versiones y
     * recetas, y las fichas sueltas con su sugerencia.
     */
    public function listado(): array
    {
        $productos = DB::table('productos')
            ->where('activo', true)
            ->orderBy('categoria')->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria', 'precio_base', 'foto_url']);

        $versiones = $this->versionesDe($productos->pluck('id')->all());
        $fichas    = $this->fichas();
        $porProducto = $fichas->whereNotNull('producto_id')->groupBy('producto_id');
        $activos   = $productos->keyBy('id');
        $noFabricados = self::hayNoFabricados()
            ? DB::table('productos_no_fabricados')->get(['producto_id', 'origen', 'nota'])->keyBy('producto_id')
            : collect();

        $filas = $productos->map(function ($p) use ($versiones, $porProducto, $noFabricados) {
            $suyas = $porProducto->get($p->id, collect());
            $base  = $suyas->whereNull('variante_config_id')->values();

            $vers = collect($versiones->get($p->id, []))->map(function ($v) use ($suyas) {
                $propias = $suyas->where('variante_config_id', $v['config_id'])->values();
                return $v + [
                    'fichas'     => $propias->map(fn ($f) => $this->resumenFicha($f, $v['precio']))->all(),
                    'repetida'   => $propias->count() > 1,
                ];
            })->values();

            // Recetas de versiones que ya no existen (se borró el tipo): cuentan como del producto
            $huerfanas = $suyas->filter(fn ($f) => $f->variante_config_id
                && ! $vers->contains('config_id', (int) $f->variante_config_id));

            // Con medidas, cada una lleva su receta: no todas cuestan lo mismo (usuario,
            // 2026-11-06). La receta general no cubre a ninguna; queda de plantilla.
            // Solo las que cambian el precio la necesitan; las otras versiones son opcionales.
            $requeridas = $vers->where('requerida', true)->count();
            $faltan = $vers->filter(fn ($v) => $v['requerida'] && empty($v['fichas']))->count();
            $todas  = $suyas->values();

            return [
                'id'          => (int) $p->id,
                'nombre'      => $p->nombre,
                'categoria'   => $p->categoria,
                'precio_base' => (float) $p->precio_base,
                'foto_url'    => $p->foto_url,
                'categoria_ficha' => $this->categoriaParaFicha($p->categoria),
                'fichas'      => $base->concat($huerfanas)->map(fn ($f) => $this->resumenFicha($f, (float) $p->precio_base))->values()->all(),
                'repetida'    => $base->count() + $huerfanas->count() > 1,
                'versiones'   => $vers->all(),
                'faltan'      => $faltan,
                // Importado o comprado hecho: no se le calcula costo de fabricación
                'no_fabricado' => ($nf = $noFabricados->get($p->id))
                    ? ['origen' => $nf->origen, 'nota' => $nf->nota]
                    : null,
                'estado'      => $nf ? 'no_fabricado' : $this->estado($todas, $requeridas, $faltan),
            ];
        })->values();

        // Sueltas: sin producto, o de un producto apagado
        $sueltas = $fichas->filter(fn ($f) => ! $f->producto_id || ! $activos->has($f->producto_id))->values();
        $ocupados = $fichas->whereNotNull('producto_id')
            ->map(fn ($f) => $f->producto_id . ':' . ($f->variante_config_id ?? 0))->flip();

        // Las palabras de cada producto se sacan una vez, no una por ficha suelta. Los que no
        // se fabrican no se sugieren: una ficha del Excel no es la receta de un importado.
        $candidatos = $productos->reject(fn ($p) => $noFabricados->has($p->id))->map(fn ($p) => (object) [
            'p'      => $p,
            'tok'    => $this->palabras($p->nombre),
            'cat'    => rtrim(CategoriasProducto::clave($p->categoria), 's'),
            'medidas' => collect($versiones->get($p->id, []))
                ->map(fn ($v) => $v + ['medida' => $this->medida($v['opcion'])])->all(),
        ])->filter(fn ($c) => ! empty($c->tok))->values();

        $sueltas = $sueltas->map(fn ($f) => $this->resumenFicha($f, null) + [
            'producto_apagado' => (bool) $f->producto_id,
            'sugerencias'      => $this->sugerencias($f, $candidatos, $ocupados),
        ])->values();

        return [
            'productos' => $filas->all(),
            'sueltas'   => $sueltas->all(),
            'resumen'   => [
                'productos'  => $filas->count(),
                'sin_receta' => $filas->where('estado', 'sin_receta')->count(),
                'incompleta' => $filas->where('estado', 'incompleta')->count(),
                'revisar'    => $filas->where('estado', 'revisar')->count(),
                'lista'      => $filas->where('estado', 'lista')->count(),
                'no_fabricados' => $filas->where('estado', 'no_fabricado')->count(),
                'sueltas'    => $sueltas->count(),
            ],
        ];
    }

    /**
     * Revisa que la ficha pueda ser la receta de ese producto (y versión): la versión es de
     * ese producto y nadie más tiene ese lugar. `$salvo` = la ficha que se está moviendo.
     */
    public function validarLugar(?int $productoId, ?int $configId, ?int $salvo = null): void
    {
        if (! $productoId) {
            if ($configId) throw ValidationException::withMessages(['variante_config_id' => 'Una versión necesita su producto.']);
            return;
        }

        if ($configId) {
            $deEse = DB::table('producto_variante_configs')->where('id', $configId)->where('producto_id', $productoId)->exists();
            if (! $deEse) {
                throw ValidationException::withMessages(['variante_config_id' => 'Esa versión no es de este producto.']);
            }
        }

        $ocupada = DB::table('fichas_tecnicas')
            ->where('producto_id', $productoId)
            ->when(self::hayVersiones(), fn ($q) => $configId
                ? $q->where('variante_config_id', $configId)
                : $q->whereNull('variante_config_id'))
            ->when($salvo, fn ($q) => $q->where('id', '!=', $salvo))
            ->first(['id', 'nombre']);

        if ($ocupada) {
            throw ValidationException::withMessages([
                'producto_id' => "Ya tiene receta: «{$ocupada->nombre}». Ábrela y edítala, o desvincúlala primero.",
            ]);
        }
    }

    // ── Vincular: la ficha toma el nombre y la foto del producto ─────────────

    public static function hayDescripcion(): bool
    {
        return Schema::hasColumn('fichas_tecnicas', 'descripcion');
    }

    /**
     * El nombre de la receta de un producto: el del producto en Inventario y, si es de una
     * versión, con la opción ("CAMA BALI 1.40"). En mayúscula, como todas las fichas.
     */
    public function nombrePara(int $productoId, ?int $configId = null): ?string
    {
        $nombre = DB::table('productos')->where('id', $productoId)->value('nombre');
        if ($nombre === null) return null;

        $opcion = $configId
            ? DB::table('producto_variante_configs as c')
                ->join('tipo_variante_opciones as o', 'o.id', '=', 'c.opcion_id')
                ->where('c.id', $configId)->value('o.nombre')
            : null;

        return mb_strtoupper(trim(trim($nombre) . ' ' . trim((string) $opcion)));
    }

    /**
     * Lo que cambia en una ficha al vincularla o desvincularla (pedido del usuario,
     * 2026-11-06: "apenas se vincule, que tome el nombre del producto, el que tenía quede
     * como descripción y se ponga la imagen del producto").
     *
     * - Nombre: el del producto (y la versión). El que tenía queda como descripción solo si
     *   la ficha no tenía una: así se guarda el nombre original (el del Excel) y no el de un
     *   producto anterior al que estuvo vinculada.
     * - Foto: la del producto. No se copia: se quita la propia y la pantalla muestra la del
     *   producto, que sigue a la de Inventario si allá la cambian. Si el producto no tiene
     *   foto, la ficha se queda con la suya.
     * - Al desvincular, el nombre se queda, y si la ficha no tenía foto propia se queda con
     *   la del producto que tenía (para no quedar sin imagen).
     *
     * @return array campos para `update()`; vacío si no cambia nada
     */
    public function camposAlVincular(object $ficha, ?int $productoId, ?int $configId): array
    {
        $campos = [];

        if (! $productoId) {
            if (! $ficha->foto_url && $ficha->producto_id) {
                $foto = DB::table('productos')->where('id', $ficha->producto_id)->value('foto_url');
                if ($foto) $campos['foto_url'] = $foto;
            }
            return $campos;
        }

        $nuevo = $this->nombrePara($productoId, $configId);
        if ($nuevo && $nuevo !== $ficha->nombre) {
            $campos['nombre'] = $nuevo;
            if (self::hayDescripcion() && trim((string) ($ficha->descripcion ?? '')) === '') {
                $campos['descripcion'] = $ficha->nombre;
            }
        }

        $fotoProducto = DB::table('productos')->where('id', $productoId)->value('foto_url');
        if ($fotoProducto && $ficha->foto_url) $campos['foto_url'] = null;

        return $campos;
    }

    /**
     * Renombraron el producto en Inventario: sus recetas lo siguen. Devuelve los ids de las
     * fichas que cambiaron (hay que volver a indexarlas para el cotizador).
     */
    public function renombrarRecetasDe(int $productoId): array
    {
        if (! Schema::hasTable('fichas_tecnicas') || ! Schema::hasColumn('fichas_tecnicas', 'producto_id')) return [];

        $cols = ['id', 'nombre'];
        if (self::hayVersiones()) $cols[] = 'variante_config_id';

        $cambiadas = [];
        foreach (DB::table('fichas_tecnicas')->where('producto_id', $productoId)->get($cols) as $f) {
            $nuevo = $this->nombrePara($productoId, $f->variante_config_id ?? null);
            if ($nuevo && $nuevo !== $f->nombre) {
                DB::table('fichas_tecnicas')->where('id', $f->id)->update(['nombre' => $nuevo, 'updated_at' => now()]);
                $cambiadas[] = (int) $f->id;
            }
        }

        return $cambiadas;
    }

    /**
     * La categoría con la que nace la ficha. El cotizador agrupa por las categorías de las
     * fichas (vinieron del Excel: CAMAS, SOFAS…), así que si la del producto es una de ellas
     * escrita distinto ("Sofás"), se usa la de la ficha; si no, la del producto en mayúscula.
     */
    public function categoriaParaFicha(?string $categoria): string
    {
        $clave = CategoriasProducto::clave($categoria);
        if ($clave === '') return '';

        $existentes = $this->categoriasDeFichas();
        foreach ($existentes as $c) {
            $k = CategoriasProducto::clave($c);
            if ($k === $clave || rtrim($k, 's') === rtrim($clave, 's')) return $c;
        }

        return mb_strtoupper(trim($categoria));
    }

    // ── Piezas ───────────────────────────────────────────────────────────────

    private ?Collection $categorias = null;

    private function categoriasDeFichas(): Collection
    {
        return $this->categorias ??= DB::table('fichas_tecnicas')->distinct()->pluck('categoria')->filter()->values();
    }

    /**
     * Las versiones del producto: cada opción de sus tipos de variante (la cama de 1.40, la
     * silla eléctrica, el baúl…). Todas se pueden enlazar a una receta (usuario, 2026-11-10:
     * "si me sale el producto, que también me salgan sus variantes para elegir"); antes solo
     * salían las de tipos marcados "cambia el precio", y esa marca en Inventario solo esconde
     * el campo de precio, así que muchos productos no mostraban ninguna.
     *
     * `requerida`: la de un tipo que cambia el precio. Solo esas quedan "en espera" si les
     * falta la receta (cada medida lleva la suya, 2026-11-06); las demás son opcionales y no
     * cambian el estado del producto.
     */
    private function versionesDe(array $productoIds): Collection
    {
        if (empty($productoIds) || ! Schema::hasTable('producto_variante_configs')) return collect();

        return DB::table('producto_variante_configs as c')
            ->join('tipos_variante as t', 't.id', '=', 'c.tipo_variante_id')
            ->join('tipo_variante_opciones as o', 'o.id', '=', 'c.opcion_id')
            ->join('productos as p', 'p.id', '=', 'c.producto_id')
            ->whereIn('c.producto_id', $productoIds)
            ->orderByDesc('t.afecta_precio')->orderBy('t.nombre')->orderBy('o.nombre')
            ->get(['c.id', 'c.producto_id', 'c.precio_adicional', 't.nombre as tipo', 't.afecta_precio', 'o.nombre as opcion', 'p.precio_base'])
            ->groupBy('producto_id')
            ->map(fn ($g) => $g->map(fn ($c) => [
                'config_id' => (int) $c->id,
                'tipo'      => $c->tipo,
                'opcion'    => $c->opcion,
                // `precio_adicional` es el precio completo de esa versión (0 = el del producto)
                'precio'    => (float) $c->precio_adicional > 0 ? (float) $c->precio_adicional : (float) $c->precio_base,
                'requerida' => (bool) $c->afecta_precio,
            ])->values()->all());
    }

    private function fichas(): Collection
    {
        $cols = ['ft.id', 'ft.nombre', 'ft.categoria', 'ft.producto_id', 'ft.costo_materiales',
                 'ft.costo_mano_obra', 'ft.costo_total', 'ft.foto_url'];
        if (self::hayVersiones()) $cols[] = 'ft.variante_config_id';
        if (self::hayDescripcion()) $cols[] = 'ft.descripcion';

        $fichas = DB::table('fichas_tecnicas as ft')->orderBy('ft.nombre')->get($cols);
        if (! self::hayVersiones()) $fichas->each(fn ($f) => $f->variante_config_id = null);

        // Lo mismo que marca la lista de siempre: ítems sin precio y materiales fuera del catálogo
        $revisar = DB::table('ficha_tecnica_items')
            ->groupBy('ficha_tecnica_id')
            ->selectRaw("ficha_tecnica_id,
                SUM(CASE WHEN precio_unitario <= 0 OR TRIM(descripcion) = '' THEN 1 ELSE 0 END) AS sin_precio,
                SUM(CASE WHEN es_mano_obra = 0 AND material_id IS NULL AND TRIM(descripcion) <> '' THEN 1 ELSE 0 END) AS fuera_catalogo")
            ->get()->keyBy('ficha_tecnica_id');

        return $fichas->each(function ($f) use ($revisar) {
            $r = $revisar->get($f->id);
            $f->sin_precio     = (int) ($r->sin_precio ?? 0);
            $f->fuera_catalogo = (int) ($r->fuera_catalogo ?? 0);
        });
    }

    private function resumenFicha(object $f, ?float $precioVenta): array
    {
        return [
            'id'               => (int) $f->id,
            'nombre'           => $f->nombre,
            'descripcion'      => $f->descripcion ?? null,
            'categoria'        => $f->categoria,
            'foto_url'         => $f->foto_url,
            'costo_materiales' => (float) $f->costo_materiales,
            'costo_mano_obra'  => (float) $f->costo_mano_obra,
            'costo_total'      => (float) $f->costo_total,
            'sin_precio'       => $f->sin_precio,
            'fuera_catalogo'   => $f->fuera_catalogo,
            'precio_venta'     => $precioVenta,
        ];
    }

    /**
     * sin_receta · incompleta (con medidas: alguna sin su receta) · revisar (costo en 0 o
     * ítems sin precio / fuera del catálogo) · lista
     */
    private function estado(Collection $fichas, int $versiones = 0, int $faltan = 0): string
    {
        // Con medidas, lo que cuenta son las recetas de las medidas, no la general
        if ($versiones > 0 && $faltan === $versiones) return 'sin_receta';
        if ($fichas->isEmpty()) return 'sin_receta';
        if ($faltan > 0) return 'incompleta';

        $mal = $fichas->contains(fn ($f) => (float) $f->costo_total <= 0 || $f->sin_precio > 0 || $f->fuera_catalogo > 0);

        return $mal ? 'revisar' : 'lista';
    }

    /**
     * A qué producto (y medida) se parece una ficha suelta. Parecido por palabras del nombre
     * (sin medidas ni relleno), con un empujón si la categoría coincide; la medida del nombre
     * de la ficha ("1.40") escoge la versión. Solo propone lugares libres.
     */
    private function sugerencias(object $ficha, Collection $candidatos, Collection $ocupados): array
    {
        $tokFicha = $this->palabras($ficha->nombre);
        if (empty($tokFicha)) return [];

        $medida = $this->medida($ficha->nombre);
        $catF   = rtrim(CategoriasProducto::clave($ficha->categoria), 's');

        return $candidatos
            ->map(function ($c) use ($tokFicha, $medida, $catF, $ocupados) {
                $p = $c->p;

                $comunes = count(array_intersect($tokFicha, $c->tok));
                if ($comunes === 0) return null;

                $score = $comunes / count(array_unique(array_merge($tokFicha, $c->tok)));
                if ($catF !== '' && $catF === $c->cat) $score += 0.15;

                $version = null;
                if ($medida) {
                    $version = collect($c->medidas)->first(fn ($v) => $v['medida'] === $medida);
                    if ($version) $score += 0.15;
                }

                $lugar = $p->id . ':' . ($version['config_id'] ?? 0);
                if ($ocupados->has($lugar)) return null;

                return [
                    'producto_id' => (int) $p->id,
                    'nombre'      => $p->nombre,
                    'foto_url'    => $p->foto_url,
                    'config_id'   => $version['config_id'] ?? null,
                    'version'     => $version ? $version['opcion'] : null,
                    'score'       => round($score, 3),
                ];
            })
            ->filter(fn ($s) => $s && $s['score'] >= 0.34)
            ->sortByDesc('score')
            ->take(3)
            ->values()
            ->all();
    }

    /** Palabras que identifican el mueble, sin tildes, sin números ni relleno. */
    private function palabras(?string $nombre): array
    {
        $t = mb_strtolower(Str::ascii((string) $nombre));
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t);

        return array_values(array_unique(array_filter(explode(' ', $t), fn ($w) =>
            mb_strlen($w) >= 3 && ! ctype_digit($w) && ! in_array($w, self::RELLENO, true))));
    }

    /** "1.40", "1,40", "140" o "140x190" → "1.40". null si no trae medida. */
    public function medida(?string $texto): ?string
    {
        $t = (string) $texto;
        if (preg_match('/(?<!\d)(\d)[.,](\d{2})(?!\d)/', $t, $m)) return "{$m[1]}.{$m[2]}";
        if (preg_match('/(?<!\d)(1\d{2}|2[0-2]\d|[89]\d)(?!\d)/', $t, $m)) {
            return number_format(((int) $m[1]) / 100, 2, '.', '');
        }

        return null;
    }
}
