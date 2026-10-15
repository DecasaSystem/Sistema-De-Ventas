<?php

namespace App\Support;

use App\Models\PlantillaDocumento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Los textos de los documentos que el negocio edita desde Gestión →
 * Documentos, sin pasar por un programador.
 *
 * Los de secciones propias son dos (los demás —empresa, plazos de garantía,
 * cotización, acta de satisfacción, mensajes de WhatsApp— son listas de campos
 * descritas en EsquemasPlantillas):
 *  - El anexo de garantías que el cliente lee y firma (en la tienda o con el
 *    enlace). Es texto legal: cada anexo guarda la versión que leyó y su PDF
 *    sale siempre con ESA versión, aunque después se cambie el texto.
 *  - La orden de entrega, la hoja que se lleva el conductor. Lo editable es
 *    la redacción (título, condiciones, rótulos de la firma, pie); los datos
 *    del cliente, los productos y la plata salen de la orden y no se tocan.
 *
 * Guardar nunca pisa: crea una versión nueva (tabla plantillas_documento). La
 * vigente es la última; sin filas, vale el texto de fábrica que trae el código.
 * Así se puede ver quién cambió qué y volver a una versión anterior (que es
 * guardarla otra vez, como versión nueva).
 */
final class PlantillasDocumento
{
    public const ANEXO         = 'anexo_garantia';
    public const ORDEN_ENTREGA = 'orden_entrega';

    /** Versión del texto de fábrica de la orden de entrega (la del anexo es AnexoGarantiaTexto::VERSION). */
    public const VERSION_FABRICA_ENTREGA = 'fabrica';

    /** Los datos que se pueden poner entre llaves en la orden de entrega. */
    public const DATOS_ENTREGA = ['referencia', 'cliente', 'tienda', 'asesor'];

    /** @var array<string,array> recordado por petición (CachesDePeticion) */
    private static array $vigentes = [];

    public static function olvidarCache(): void
    {
        self::$vigentes = [];
    }

    /** @return array<int,string> */
    public static function claves(): array
    {
        return [self::ANEXO, self::ORDEN_ENTREGA, ...EsquemasPlantillas::claves()];
    }

    public static function existe(string $clave): bool
    {
        return in_array($clave, self::claves(), true);
    }

    /**
     * Cómo se llama cada documento y dónde sale, para la pantalla de Gestión.
     * Los de campos traen además su esquema (`campos`), con eso se arma el editor.
     */
    public static function info(string $clave): array
    {
        if (EsquemasPlantillas::tiene($clave)) {
            return ['clave' => $clave, ...EsquemasPlantillas::de($clave)];
        }

        return match ($clave) {
            self::ANEXO => [
                'clave'       => self::ANEXO,
                'nombre'      => 'Anexo de garantías',
                'descripcion' => 'Lo que el cliente lee y firma en Nueva orden, en la tienda o con el enlace a distancia. Queda pegado a la orden en PDF.',
                'grupo'       => 'documentos',
                'datos'       => [],
            ],
            self::ORDEN_ENTREGA => [
                'clave'       => self::ORDEN_ENTREGA,
                'nombre'      => 'Orden de entrega',
                'descripcion' => 'La hoja que se lleva el conductor: datos del cliente, lo que va, lo que hay que cobrar y la firma de quien recibe.',
                'grupo'       => 'documentos',
                'datos'       => self::DATOS_ENTREGA,
            ],
        };
    }

    // ── Leer ──────────────────────────────────────────────────────────────────

    /** El texto que trae el código. @return array{version:string,contenido:array} */
    public static function deFabrica(string $clave): array
    {
        if (EsquemasPlantillas::tiene($clave)) {
            return ['version' => self::VERSION_FABRICA_ENTREGA, 'contenido' => EsquemasPlantillas::fabrica($clave)];
        }

        return match ($clave) {
            self::ANEXO => [
                'version'   => AnexoGarantiaTexto::VERSION,
                'contenido' => [
                    'titulo'    => AnexoGarantiaTexto::TITULO,
                    'secciones' => AnexoGarantiaTexto::secciones(),
                    'checklist' => AnexoGarantiaTexto::checklist(),
                ],
            ],
            self::ORDEN_ENTREGA => [
                'version'   => self::VERSION_FABRICA_ENTREGA,
                'contenido' => [
                    'titulo'             => 'ORDEN DE ENTREGA',
                    'etiqueta_saldo'     => 'SALDO CONTRA ENTREGA — TOTAL A CANCELAR',
                    'condiciones'        => [],
                    'firma_recibe'       => 'RECIBE A SATISFACCIÓN',
                    'firma_recibe_ayuda' => 'Nombre y cédula de quien recibe',
                    'firma_entrega'      => 'ENTREGA',
                    'pie'                => 'Decasa',
                    'renglones_blanco'   => 8,
                ],
            ],
        };
    }

    /**
     * La versión vigente: la última guardada o, si no hay, la de fábrica.
     * @return array{version:string,contenido:array}
     */
    public static function vigente(string $clave): array
    {
        if (isset(self::$vigentes[$clave])) return self::$vigentes[$clave];

        $fila = self::hayTabla()
            ? PlantillaDocumento::where('clave', $clave)->orderByDesc('id')->first(['version', 'contenido'])
            : null;

        return self::$vigentes[$clave] = $fila
            ? ['version' => $fila->version, 'contenido' => self::conFaltantes($clave, $fila->contenido)]
            : self::deFabrica($clave);
    }

    /**
     * Una versión concreta: la que leyó el cliente de un anexo. Si no se
     * encuentra (un anexo de antes de esta tabla trae la de fábrica), sale la
     * de fábrica cuando coincide y, si no, la vigente.
     * @return array{version:string,contenido:array}
     */
    public static function deVersion(string $clave, ?string $version): array
    {
        if ($version === null || $version === '') return self::vigente($clave);

        $vigente = self::vigente($clave);
        if ($vigente['version'] === $version) return $vigente;

        $fila = self::hayTabla()
            ? PlantillaDocumento::where('clave', $clave)->where('version', $version)->first(['version', 'contenido'])
            : null;
        if ($fila) return ['version' => $fila->version, 'contenido' => self::conFaltantes($clave, $fila->contenido)];

        $fabrica = self::deFabrica($clave);
        return $fabrica['version'] === $version ? $fabrica : $vigente;
    }

    /** Todas las versiones guardadas, de la más nueva a la más vieja, y al final la de fábrica. */
    public static function historial(string $clave): array
    {
        $usos = $clave === self::ANEXO ? self::anexosFirmadosPorVersion() : [];

        $filas = self::hayTabla()
            ? PlantillaDocumento::with('autor:id,nombre')->where('clave', $clave)->orderByDesc('id')->get()
            : collect();

        $lista = $filas->map(fn (PlantillaDocumento $p) => [
            'version'   => $p->version,
            'nota'      => $p->nota,
            'autor'     => $p->autor?->nombre,
            'creado_at' => $p->created_at,
            'contenido' => self::conFaltantes($clave, $p->contenido),
            'firmados'  => $usos[$p->version] ?? 0,
            'fabrica'   => false,
        ])->all();

        $fabrica = self::deFabrica($clave);
        $lista[] = [
            'version'   => $fabrica['version'],
            'nota'      => 'Texto de fábrica (el que traía el sistema).',
            'autor'     => null,
            'creado_at' => null,
            'contenido' => $fabrica['contenido'],
            'firmados'  => $usos[$fabrica['version']] ?? 0,
            'fabrica'   => true,
        ];

        return $lista;
    }

    // ── Guardar ───────────────────────────────────────────────────────────────

    /**
     * Guarda el texto como versión nueva. Devuelve null si no cambió nada
     * (darle "Guardar" sin tocar no debe llenar el historial).
     *
     * @throws ValidationException
     */
    public static function guardar(string $clave, array $entrada, ?int $usuarioId, ?string $nota = null): ?PlantillaDocumento
    {
        $vigente   = self::vigente($clave);
        $contenido = self::normalizar($clave, $entrada);

        if ($contenido == $vigente['contenido']) return null;

        $fila = DB::transaction(function () use ($clave, $contenido, $usuarioId, $nota) {
            // Dos supervisores guardando a la vez: el candado evita que los dos
            // saquen el mismo número de versión del mismo día.
            $hoy    = now('America/Bogota')->format('Y-m-d');
            $usadas = PlantillaDocumento::where('clave', $clave)->where('version', 'like', "$hoy%")
                ->lockForUpdate()->pluck('version')->all();

            $version = $hoy;
            for ($n = 2; in_array($version, $usadas, true); $n++) $version = "$hoy.$n";

            return PlantillaDocumento::create([
                'clave'      => $clave,
                'version'    => $version,
                'contenido'  => $contenido,
                'nota'       => $nota !== null && trim($nota) !== '' ? mb_substr(trim($nota), 0, 255) : null,
                'creado_por' => $usuarioId,
            ]);
        });

        unset(self::$vigentes[$clave]);

        return $fila;
    }

    /**
     * Revisa y limpia lo que llega de la pantalla. Lo que se guarda es
     * siempre la forma completa, así el PDF no tiene que adivinar.
     *
     * @throws ValidationException
     */
    public static function normalizar(string $clave, array $e): array
    {
        return match ($clave) {
            self::ANEXO         => self::normalizarAnexo($e),
            self::ORDEN_ENTREGA => self::normalizarEntrega($e),
            default             => self::normalizarPorEsquema($clave, $e),
        };
    }

    /** Valida y limpia una plantilla de campos (EsquemasPlantillas). */
    private static function normalizarPorEsquema(string $clave, array $e): array
    {
        $out = [];
        foreach (EsquemasPlantillas::de($clave)['campos'] as $c) {
            $campo = $c['campo'];
            $nombre = $c['etiqueta'];
            $v = $e[$campo] ?? null;

            if ($c['tipo'] === 'numero') {
                if (! is_numeric($v) || (int) $v != $v || (int) $v < $c['min'] || (int) $v > $c['max']) {
                    self::error($campo, "\"$nombre\" tiene que ser un número entero entre {$c['min']} y {$c['max']}.");
                }
                $out[$campo] = (int) $v;
                continue;
            }

            if ($c['tipo'] === 'parrafos') {
                $lista = self::parrafos($v ?? []);
                if ($c['requerido'] && ! $lista) self::error($campo, "\"$nombre\" no puede quedar vacío.");
                if (count($lista) > $c['maxItems']) self::error($campo, "\"$nombre\" tiene demasiados renglones (máximo {$c['maxItems']}).");
                foreach ($lista as $p) {
                    if (mb_strlen($p) > $c['max']) self::error($campo, "Un renglón de \"$nombre\" es muy largo (máximo {$c['max']} letras).");
                }
                $out[$campo] = $lista;
                continue;
            }

            // texto (un renglón) o multilinea (conserva los saltos, quita los
            // espacios de más y los renglones vacíos repetidos).
            $t = $c['tipo'] === 'multilinea' ? self::multilinea($v) : self::texto($v);
            if ($c['tipo'] === 'texto') $t = str_replace("\n", ' ', $t);
            if ($c['requerido'] && $t === '') self::error($campo, "\"$nombre\" no puede quedar vacío.");
            if (mb_strlen($t) > $c['max']) self::error($campo, "\"$nombre\" es muy largo (máximo {$c['max']} letras).");
            if (($dato = EsquemasPlantillas::datoObligatorio($clave, $campo)) && ! str_contains($t, '{' . $dato . '}')) {
                self::error($campo, "\"$nombre\" tiene que llevar {{$dato}}: sin eso el cliente no recibe el enlace.");
            }
            $out[$campo] = $t;
        }

        return $out;
    }

    private static function normalizarAnexo(array $e): array
    {
        $titulo = self::texto($e['titulo'] ?? '');
        if ($titulo === '') self::error('titulo', 'El documento necesita un título.');
        if (mb_strlen($titulo) > 300) self::error('titulo', 'El título es muy largo (máximo 300 letras).');

        $secciones = is_array($e['secciones'] ?? null) ? array_values($e['secciones']) : [];
        if (! $secciones) self::error('secciones', 'El anexo necesita al menos una sección.');
        if (count($secciones) > 30) self::error('secciones', 'Son demasiadas secciones (máximo 30).');

        $ids = [];
        $limpias = [];
        foreach ($secciones as $i => $s) {
            $n = $i + 1;
            $t = self::texto($s['titulo'] ?? '');
            if ($t === '') self::error("secciones.$i.titulo", "La sección $n no tiene título.");
            if (mb_strlen($t) > 200) self::error("secciones.$i.titulo", "El título de la sección $n es muy largo.");

            $parrafos = self::parrafos($s['parrafos'] ?? []);
            if (! $parrafos) self::error("secciones.$i.parrafos", "La sección \"$t\" está vacía.");
            if (count($parrafos) > 60) self::error("secciones.$i.parrafos", "La sección \"$t\" tiene demasiados párrafos (máximo 60).");
            foreach ($parrafos as $p) {
                if (mb_strlen($p) > 4000) self::error("secciones.$i.parrafos", "Un párrafo de \"$t\" es muy largo (máximo 4.000 letras).");
            }

            $limpias[] = ['id' => self::idUnico($s['id'] ?? null, $t, $ids), 'titulo' => $t, 'parrafos' => $parrafos];
        }

        $preguntas = is_array($e['checklist'] ?? null) ? array_values($e['checklist']) : [];
        if (count($preguntas) > 15) self::error('checklist', 'Son demasiadas preguntas (máximo 15).');

        $idsQ = [];
        $check = [];
        foreach ($preguntas as $i => $q) {
            $p = self::texto($q['pregunta'] ?? '');
            if ($p === '') continue;   // una pregunta en blanco es una que se quitó
            if (mb_strlen($p) > 300) self::error("checklist.$i.pregunta", 'Una pregunta del check list es muy larga (máximo 300 letras).');
            $check[] = ['id' => self::idUnico($q['id'] ?? null, $p, $idsQ), 'pregunta' => $p];
        }

        return ['titulo' => $titulo, 'secciones' => $limpias, 'checklist' => $check];
    }

    private static function normalizarEntrega(array $e): array
    {
        $fabrica = self::deFabrica(self::ORDEN_ENTREGA)['contenido'];
        $limites = [
            'titulo' => 60, 'etiqueta_saldo' => 80, 'firma_recibe' => 60,
            'firma_recibe_ayuda' => 100, 'firma_entrega' => 60, 'pie' => 80,
        ];
        $nombres = [
            'titulo' => 'El título', 'etiqueta_saldo' => 'El rótulo del saldo', 'firma_recibe' => 'El rótulo de quien recibe',
            'firma_recibe_ayuda' => 'La ayuda de la firma', 'firma_entrega' => 'El rótulo de quien entrega', 'pie' => 'El pie de página',
        ];

        $out = [];
        foreach ($limites as $campo => $max) {
            $v = self::texto($e[$campo] ?? '');
            // Sin estos la hoja queda sin decir qué es o quién firma.
            if ($v === '' && in_array($campo, ['titulo', 'etiqueta_saldo', 'firma_recibe', 'firma_entrega'], true)) {
                self::error($campo, "{$nombres[$campo]} no puede quedar vacío.");
            }
            if (mb_strlen($v) > $max) self::error($campo, "{$nombres[$campo]} es muy largo (máximo $max letras).");
            $out[$campo] = $v;
        }

        $condiciones = self::parrafos($e['condiciones'] ?? []);
        if (count($condiciones) > 12) self::error('condiciones', 'Son demasiados párrafos de condiciones (máximo 12).');
        foreach ($condiciones as $p) {
            if (mb_strlen($p) > 1500) self::error('condiciones', 'Un párrafo de las condiciones es muy largo (máximo 1.500 letras).');
        }

        $renglones = $e['renglones_blanco'] ?? $fabrica['renglones_blanco'];
        if (! is_numeric($renglones) || (int) $renglones < 0 || (int) $renglones > 15) {
            self::error('renglones_blanco', 'Los renglones para anotar a mano van de 0 a 15.');
        }

        return [
            'titulo'             => $out['titulo'],
            'etiqueta_saldo'     => $out['etiqueta_saldo'],
            'condiciones'        => $condiciones,
            'firma_recibe'       => $out['firma_recibe'],
            'firma_recibe_ayuda' => $out['firma_recibe_ayuda'],
            'firma_entrega'      => $out['firma_entrega'],
            'pie'                => $out['pie'],
            'renglones_blanco'   => (int) $renglones,
        ];
    }

    /**
     * Pone los datos donde el texto dice {referencia}, {cliente}, {tienda}...
     * Solo los que vienen en $datos; lo demás entre llaves se deja como está.
     * Un dato vacío se lleva el espacio de antes: "Hola {cliente}, soy" sin
     * nombre queda "Hola, soy". (La app hace lo mismo en utils/textosNegocio.js.)
     */
    public static function conDatos(string $texto, array $datos): string
    {
        foreach ($datos as $k => $v) {
            $v = (string) ($v ?? '');
            $texto = $v === ''
                ? preg_replace('/ ?\{' . preg_quote($k, '/') . '\}/u', '', $texto)
                : str_replace('{' . $k . '}', $v, $texto);
        }

        return $texto;
    }

    /**
     * La versión que estaba vigente en una fecha: el acta de una entrega del
     * martes sale con el texto del martes aunque hoy diga otra cosa.
     * @return array{version:string,contenido:array}
     */
    public static function vigenteEn(string $clave, ?\DateTimeInterface $fecha): array
    {
        if (! $fecha || ! self::hayTabla()) return self::vigente($clave);

        $fila = PlantillaDocumento::where('clave', $clave)->where('created_at', '<=', $fecha)
            ->orderByDesc('id')->first(['version', 'contenido']);

        return $fila
            ? ['version' => $fila->version, 'contenido' => self::conFaltantes($clave, $fila->contenido)]
            : self::deFabrica($clave);
    }

    /** Los datos de la empresa vigentes (nombre, NIT, dirección...). */
    public static function empresa(): array
    {
        return self::vigente(EsquemasPlantillas::EMPRESA)['contenido'];
    }

    /**
     * El renglón de datos de la empresa para un encabezado de PDF:
     * "Decasa Muebles y Decoración · NIT 900… · Cra 14… · 300…". Lo vacío no sale.
     */
    public static function renglonEmpresa(): string
    {
        $e = self::empresa();

        return collect([
            $e['nombre_completo'] ?? '',
            ! empty($e['nit']) ? 'NIT ' . $e['nit'] : '',
            $e['direccion'] ?? '', $e['telefono'] ?? '', $e['correo'] ?? '', $e['sitio_web'] ?? '',
        ])->filter(fn ($x) => trim((string) $x) !== '')->implode(' · ');
    }

    // ── Ayudas ────────────────────────────────────────────────────────────────

    /** Un texto de varios renglones: sin espacios de más ni más de un renglón vacío seguido. */
    private static function multilinea(mixed $v): string
    {
        if (! is_string($v)) return '';
        $lineas = array_map(fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), explode("\n", str_replace("\r", '', $v)));

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lineas)));
    }

    public static function hayTabla(): bool
    {
        return Esquema::tabla('plantillas_documento');
    }

    /** Una versión guardada antes de que existiera un campo nuevo lo toma de fábrica. */
    private static function conFaltantes(string $clave, ?array $contenido): array
    {
        return array_replace(self::deFabrica($clave)['contenido'], $contenido ?? []);
    }

    /** Cuántos anexos firmados hay con cada versión: para saber qué texto tiene firmado la gente. */
    private static function anexosFirmadosPorVersion(): array
    {
        if (! Esquema::tabla('anexos_garantia')) return [];

        return DB::table('anexos_garantia')->where('estado', 'firmado')
            ->selectRaw('version, COUNT(*) AS total')->groupBy('version')
            ->pluck('total', 'version')->map(fn ($n) => (int) $n)->all();
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $v))) : '';
    }

    /**
     * Los párrafos llegan como lista o como un solo texto (un párrafo por
     * renglón). Se quitan los renglones vacíos.
     * @return array<int,string>
     */
    private static function parrafos(mixed $v): array
    {
        $lista = is_array($v) ? $v : (is_string($v) ? explode("\n", $v) : []);

        return array_values(array_filter(
            array_map(fn ($p) => self::texto(is_string($p) ? $p : ''), $lista),
            fn ($p) => $p !== '',
        ));
    }

    /**
     * El id de una sección o pregunta: el que ya traía si sirve (así el
     * historial se puede comparar) o uno sacado del título.
     */
    private static function idUnico(mixed $id, string $titulo, array &$usados): string
    {
        $base = is_string($id) && preg_match('/^[a-z0-9_]{1,40}$/', $id)
            ? $id
            : (Str::limit(Str::slug($titulo, '_'), 30, '') ?: 'seccion');

        $final = $base;
        for ($n = 2; in_array($final, $usados, true); $n++) $final = mb_substr($base, 0, 36) . "_$n";
        $usados[] = $final;

        return $final;
    }

    private static function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
