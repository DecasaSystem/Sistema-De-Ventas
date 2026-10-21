<?php

namespace App\Support;

use App\Models\AnexoGarantia;
use App\Models\Orden;
use App\Models\OrdenItem;
use Illuminate\Support\Collection;

/**
 * La declaración para la fabricación de muebles con diseños y medidas
 * especiales (dueño, 2026-10-21).
 *
 * Por qué existe: lo que no está en el catálogo "es algo que no hemos hecho
 * nunca", y si al entregarlo no es lo que el cliente imaginaba, nadie tiene
 * cómo mostrar qué se acordó. El vendedor escribe en la orden cómo va cada
 * mueble (la FICHA: tipo, madera, tela, relleno, pintura, medidas y detalles)
 * y sube los bocetos; el cliente lo ve, lo aprueba producto por producto y lo
 * firma. Es el mismo papel que firmaba antes ("suministré las medidas… los
 * exonero de cualquier responsabilidad en cuanto a las mismas").
 *
 * Se firma con el anexo de garantías, con UNA sola firma (la misma decisión
 * del 2026-10-09: orden y anexo se firman juntos), pero queda como su propio
 * documento con su propio PDF. Se pide donde se pide el anexo; en la tienda
 * también vale la foto del papel firmado (`ordenes.declaracion_foto_url`).
 *
 * Qué productos la piden se escoge en Gestión → Ajustes (AjustesSistema):
 * los diseños especiales (fuera del catálogo) sí; lo personalizado, solo si
 * lo prenden.
 *
 * Lo que el cliente aprobó queda congelado en el anexo (`especiales`), y su
 * huella (`especiales_hash`) dice si después se cambió: entonces la orden no
 * se crea con esa firma (hay que firmar de nuevo lo que de verdad va).
 */
final class DeclaracionEspeciales
{
    /**
     * Los campos de la ficha, en el orden del papel.
     * grupo: tipo (III), descripcion (I), medidas (II).
     */
    public const CAMPOS = [
        'tipo_mueble' => ['etiqueta' => 'Tipo de mueble',     'grupo' => 'tipo',        'max' => 150],
        'madera'      => ['etiqueta' => 'Madera',             'grupo' => 'descripcion', 'max' => 200],
        'tela'        => ['etiqueta' => 'Tela',               'grupo' => 'descripcion', 'max' => 200],
        'relleno'     => ['etiqueta' => 'Relleno',            'grupo' => 'descripcion', 'max' => 200],
        'pintura'     => ['etiqueta' => 'Pintura / acabado',  'grupo' => 'descripcion', 'max' => 200],
        'ancho'       => ['etiqueta' => 'Ancho',              'grupo' => 'medidas',     'max' => 60],
        'largo'       => ['etiqueta' => 'Largo',              'grupo' => 'medidas',     'max' => 60],
        'alto'        => ['etiqueta' => 'Alto',               'grupo' => 'medidas',     'max' => 60],
        'profundidad' => ['etiqueta' => 'Profundidad',        'grupo' => 'medidas',     'max' => 60],
        'detalles'    => ['etiqueta' => 'Detalles',           'grupo' => 'descripcion', 'max' => 3000],
    ];

    /** Cuántas medidas como mínimo: con una sola no se sabe el tamaño del mueble. */
    public const MIN_MEDIDAS = 2;

    // ── Qué productos la piden ────────────────────────────────────────────────

    /**
     * El tipo de un ítem tal como llega al crear la orden (las mismas reglas
     * que OrdenItem::getTipoItemAttribute y tipoDeItemCarrito en la app).
     */
    public static function tipoDeLinea(array $i): string
    {
        if (! empty($i['es_restauracion'])) return 'restauracion';
        if (! empty($i['producto_unico']))  return 'producto_unico';
        if (! empty($i['retapizar']))       return 'retapizar';
        $sinCatalogo = empty($i['producto_id']);
        if (empty($i['es_personalizado']) && ! $sinCatalogo) return 'catalogo';
        if ($sinCatalogo)                    return 'diseno_especial';
        if (! empty($i['fabricar_pedido']))  return 'fabricar';

        return 'personalizado';
    }

    /** ¿Ese tipo de producto pide la declaración, según Gestión → Ajustes? */
    public static function aplicaATipo(string $tipo): bool
    {
        $clave = match ($tipo) {
            'diseno_especial' => AjustesSistema::DECLARACION_ESPECIALES,
            'personalizado'   => AjustesSistema::DECLARACION_PERSONALIZADOS,
            default           => null,
        };
        if (! $clave) return false;
        // Sin la tabla de ajustes (pruebas que montan su propio esquema), lo de fábrica.
        if (! Esquema::tabla('configuracion')) return $clave === AjustesSistema::DECLARACION_ESPECIALES;

        return AjustesSistema::activo($clave);
    }

    /** Los ítems de un pedido (como llegan al crear la orden) que la piden. */
    public static function lineasQueAplican(array $items): array
    {
        return array_values(array_filter($items, fn ($i) => is_array($i) && self::aplicaATipo(self::tipoDeLinea($i))));
    }

    /** Los ítems vivos de una orden guardada que la piden. */
    public static function itemsQueAplican(Orden $orden): Collection
    {
        $orden->loadMissing('items');

        return $orden->items->filter(fn (OrdenItem $i) => $i->estaVivo() && self::aplicaATipo($i->tipo_item))->values();
    }

    // ── La ficha ──────────────────────────────────────────────────────────────

    /**
     * Limpia lo que llega de la pantalla: solo los campos de la ficha, texto
     * sin espacios de más y con su tope. Sin nada escrito, null.
     */
    public static function normalizarFicha(mixed $ficha): ?array
    {
        if (! is_array($ficha)) return null;

        $out = [];
        foreach (self::CAMPOS as $k => $c) {
            $v = $ficha[$k] ?? null;
            if (is_int($v) || is_float($v)) $v = (string) $v;
            if (! is_string($v)) continue;
            $v = $k === 'detalles'
                ? trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $v))))
                : trim(preg_replace('/\s+/u', ' ', $v));
            if ($v === '') continue;
            $out[$k] = mb_substr($v, 0, $c['max']);
        }

        return $out ?: null;
    }

    /** Qué le falta a la ficha para que el cliente la pueda aprobar, o null si está completa. */
    public static function faltaEnFicha(?array $ficha): ?string
    {
        $f = self::normalizarFicha($ficha) ?? [];

        if (empty($f['tipo_mueble'])) return 'el tipo de mueble';

        $descripcion = array_filter(array_keys(self::CAMPOS), fn ($k) => self::CAMPOS[$k]['grupo'] === 'descripcion' && ! empty($f[$k]));
        if (! $descripcion) return 'la descripción (madera, tela, relleno, pintura o detalles)';

        $medidas = array_filter(array_keys(self::CAMPOS), fn ($k) => self::CAMPOS[$k]['grupo'] === 'medidas' && ! empty($f[$k]));
        if (count($medidas) < self::MIN_MEDIDAS) return 'las medidas (al menos ' . self::MIN_MEDIDAS . ': ancho, largo, alto o profundidad)';

        return null;
    }

    /** La ficha en renglones "Etiqueta: valor", agrupada como el papel (para el PDF y la pantalla). */
    public static function grupos(?array $ficha): array
    {
        $f = self::normalizarFicha($ficha) ?? [];
        $g = ['descripcion' => [], 'medidas' => [], 'tipo' => []];
        foreach (self::CAMPOS as $k => $c) {
            if (isset($f[$k])) $g[$c['grupo']][] = ['campo' => $k, 'label' => $c['etiqueta'], 'value' => $f[$k]];
        }

        return $g;
    }

    // ── La huella ─────────────────────────────────────────────────────────────

    /**
     * Huella de lo que el cliente aprobó: qué producto (del catálogo o el
     * nombre escrito) y su ficha. No entran cantidad ni precio (de eso cuida la
     * huella del anexo) ni los bocetos (quedan congelados en el anexo y el PDF
     * sale de ahí): esta solo dice si cambió CÓMO se hace el mueble.
     *
     * @param array<int,array{producto_id?:int|null,nombre_custom?:string|null,ficha?:array|null}> $lineas
     */
    public static function huella(array $lineas): string
    {
        $partes = collect($lineas)->map(function ($l) {
            $id    = ! empty($l['producto_id'])
                ? 'p' . (int) $l['producto_id']
                : 'c:' . mb_strtolower(trim((string) ($l['nombre_custom'] ?? '')));
            $ficha = self::normalizarFicha($l['ficha'] ?? $l['ficha_especial'] ?? null) ?? [];
            ksort($ficha);
            $ficha = array_map(fn ($v) => mb_strtolower($v), $ficha);

            return $id . '|' . json_encode($ficha, JSON_UNESCAPED_UNICODE);
        })->sort()->values()->all();

        return hash('sha256', json_encode($partes, JSON_UNESCAPED_UNICODE));
    }

    /** La huella de los ítems de una orden guardada que piden la declaración. */
    public static function huellaDeOrden(Orden $orden): ?string
    {
        $items = self::itemsQueAplican($orden);
        if ($items->isEmpty()) return null;

        return self::huella($items->map(fn (OrdenItem $i) => [
            'producto_id'   => $i->producto_id,
            'nombre_custom' => $i->producto_id ? null : $i->nombre_custom,
            'ficha'         => $i->ficha_especial,
        ])->all());
    }

    // ── Lo que ve el cliente (congelado en el anexo) ──────────────────────────

    /**
     * Valida y limpia los productos que manda la pantalla para que el cliente
     * los apruebe. Cada uno tiene que traer su ficha completa: el cliente no
     * puede aprobar un mueble del que no se sabe cómo va.
     *
     * @return array{0: array, 1: ?string} [productos, error]
     */
    public static function normalizarProductos(mixed $productos): array
    {
        if (! is_array($productos)) return [[], null];

        $out = [];
        foreach (array_values($productos) as $n => $p) {
            if (! is_array($p)) continue;
            $nombre = trim((string) ($p['nombre'] ?? '')) ?: 'Producto ' . ($n + 1);
            $ficha  = self::normalizarFicha($p['ficha'] ?? null);
            if ($falta = self::faltaEnFicha($ficha)) {
                return [[], "Al producto \"{$nombre}\" le falta {$falta} para que el cliente lo pueda aprobar."];
            }
            $out[] = [
                'nombre'        => mb_substr($nombre, 0, 200),
                'cantidad'      => is_numeric($p['cantidad'] ?? null) ? (float) $p['cantidad'] + 0 : 1,
                'tipo'          => mb_substr((string) ($p['tipo'] ?? ''), 0, 120) ?: null,
                'producto_id'   => ! empty($p['producto_id']) ? (int) $p['producto_id'] : null,
                'nombre_custom' => empty($p['producto_id']) ? mb_substr(trim((string) ($p['nombre_custom'] ?? $nombre)), 0, 200) : null,
                'ficha'         => $ficha,
                // Solo imágenes subidas (https), como el resto del resumen.
                'bocetos'       => array_values(array_slice(array_filter(
                    (array) ($p['bocetos'] ?? []),
                    fn ($u) => is_string($u) && str_starts_with($u, 'https://') && mb_strlen($u) <= 500,
                ), 0, 10)),
            ];
        }

        return [$out, null];
    }

    /**
     * Los productos de una orden guardada, como los ve el cliente. Devuelve
     * el error si a alguno le falta la ficha (hay que llenarla en Editar orden).
     *
     * @return array{0: array, 1: ?string}
     */
    public static function productosDeOrden(Orden $orden): array
    {
        $productos = self::itemsQueAplican($orden)->map(function (OrdenItem $i) {
            $i->loadMissing('producto:id,nombre');

            return [
                'nombre'        => $i->producto?->nombre ?? $i->nombre_custom ?? 'Producto',
                'cantidad'      => (int) $i->cantidad,
                'tipo'          => $i->tipo_item === 'diseno_especial' ? 'Diseño especial' : 'Personalizado',
                'producto_id'   => $i->producto_id,
                'nombre_custom' => $i->producto_id ? null : $i->nombre_custom,
                'ficha'         => $i->ficha_especial,
                'bocetos'       => array_values(array_filter(
                    $i->bocetos_list,
                    fn ($u) => is_string($u) && str_starts_with($u, 'https://'),
                )),
            ];
        })->all();

        return self::normalizarProductos($productos);
    }

    // ── Revisar una orden ─────────────────────────────────────────────────────

    /**
     * ¿A esta orden (ya guardada) le falta la declaración? Devuelve el mensaje
     * para el vendedor, o null si no la necesita o ya la tiene: firmada en el
     * sistema con los productos como están hoy, o la foto del papel.
     */
    public static function faltaEnOrden(Orden $orden, ?string $fotoUrl = null): ?string
    {
        $huella = self::huellaDeOrden($orden);
        if ($huella === null) return null;
        if ($fotoUrl || $orden->declaracion_foto_url) return null;

        if (AnexoGarantia::hayTabla() && self::hayColumnas()) {
            $firmada = AnexoGarantia::where('orden_id', $orden->id)->where('estado', 'firmado')
                ->whereNotNull('especiales_hash')->latest('id')->first();
            if ($firmada && hash_equals((string) $firmada->especiales_hash, $huella)) return null;
            if ($firmada) {
                return 'Las especificaciones de los productos especiales cambiaron después de que el cliente firmó la declaración. Mándasela a firmar de nuevo o sube la foto de la declaración firmada en papel.';
            }
        }

        return 'Falta la declaración de diseños especiales: que el cliente la firme en el sistema o sube la foto de la declaración firmada en papel.';
    }

    /** Si la base ya tiene las columnas de la declaración (las pruebas montan su propio esquema). */
    public static function hayColumnas(): bool
    {
        // Recordado (Esquema): en producción cada pregunta al esquema es un viaje a la base.
        return Esquema::columna('anexos_garantia', 'especiales_hash');
    }

    // ── El texto ──────────────────────────────────────────────────────────────

    /** El texto que se firma hoy (Gestión → Documentos). */
    public static function vigente(): array
    {
        $v = PlantillasDocumento::vigente(EsquemasPlantillas::DECLARACION);

        return ['version' => $v['version'], ...$v['contenido']];
    }

    /** El texto con que se firmó una declaración (aunque hoy diga otra cosa). */
    public static function deVersion(?string $version): array
    {
        $v = PlantillasDocumento::deVersion(EsquemasPlantillas::DECLARACION, $version);

        return ['version' => $v['version'], ...$v['contenido']];
    }
}
