<?php

namespace App\Http\Controllers;

use App\Models\Herramienta;
use App\Models\Modulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que cada empresa le cambia al programa: cómo se llaman los módulos, con
 * qué icono salen, qué módulos crea a partir de otros y qué tiene a mano el
 * asesor para copiar.
 *
 * Leer lo puede cualquiera que haya entrado —son los nombres de su propia
 * pantalla—; cambiarlo, sólo quien administra.
 */
class PersonalizacionController extends Controller
{
    /**
     * Hasta dónde llega un icono. Un nombre de heroicons son treinta letras;
     * un icono dibujado a mano es el trazo de un SVG y puede pasar de mil. Con
     * esto cabe un dibujo razonable y no un archivo entero.
     */
    private const ICONO_MAX = 8000;

    // ── Módulos ───────────────────────────────────────────────────────────────

    /** GET /api/modulos */
    public function modulos()
    {
        $modulos = Modulo::orderBy('orden')->orderBy('nombre')
            ->get(['id', 'clave', 'plantilla', 'nombre', 'icono', 'config', 'visible', 'orden'])
            ->map(fn (Modulo $m) => $this->paraPantalla($m));

        return response()->json($modulos);
    }

    /**
     * PATCH /api/modulos
     *
     * Llega la lista completa de lo que cambió. La clave viaja para saber a
     * quién se le cambia el nombre, pero nunca se escribe: es lo que el código
     * busca, y renombrarla dejaría el módulo huérfano.
     *
     * La config (unidad, singular, decimales) sólo se escribe en los módulos
     * que nacieron de una plantilla: los de siempre no la usan.
     */
    public function guardarModulos(Request $request)
    {
        $data = $request->validate([
            'modulos'           => 'required|array|min:1',
            'modulos.*.clave'   => 'required|string|exists:modulos,clave',
            'modulos.*.nombre'  => 'required|string|max:60',
            'modulos.*.icono'   => 'required|string|max:' . self::ICONO_MAX,
            'modulos.*.visible' => 'nullable|boolean',
            'modulos.*.orden'   => 'nullable|integer|min:0|max:9999',
            'modulos.*.config'  => 'nullable|array',
            ...self::reglasConfig('modulos.*.config.'),
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['modulos'] as $m) {
                $modulo = Modulo::where('clave', $m['clave'])->first();
                $modulo->nombre  = trim($m['nombre']);
                $modulo->icono   = $m['icono'];
                $modulo->visible = $m['visible'] ?? true;
                $modulo->orden   = $m['orden']   ?? 0;
                if ($modulo->esCopia() && array_key_exists('config', $m)) {
                    $modulo->config = self::limpiarConfig($m['config'] ?? []);
                }
                $modulo->save();
            }
        });

        return $this->modulos();
    }

    /**
     * POST /api/modulos
     *
     * Un módulo nuevo a partir de otro: Espumas a partir de Telas. Nace con su
     * propia clave —de la plantilla y el nombre, para que se lea de qué es— y
     * al final de la lista, que es donde uno espera lo que acaba de crear.
     */
    public function crearModulo(Request $request)
    {
        $data = $request->validate([
            'plantilla' => 'required|string|in:' . implode(',', array_keys(Modulo::PLANTILLAS)),
            'nombre'    => 'required|string|max:60',
            'icono'     => 'required|string|max:' . self::ICONO_MAX,
            'config'    => 'nullable|array',
            ...self::reglasConfig('config.'),
        ]);

        $nombre = trim($data['nombre']);

        $modulo = Modulo::create([
            'clave'     => $this->claveLibre($data['plantilla'], $nombre),
            'plantilla' => $data['plantilla'],
            'nombre'    => $nombre,
            'icono'     => $data['icono'],
            'config'    => self::limpiarConfig($data['config'] ?? []),
            'visible'   => true,
            'orden'     => (int) Modulo::max('orden') + 10,
        ]);

        return response()->json($this->paraPantalla($modulo), 201);
    }

    /**
     * DELETE /api/modulos/{id}
     *
     * Sólo se borra lo que la empresa creó: un módulo de siempre no tiene
     * cómo volver, así que ese se apaga con `visible`. Se van con él sus
     * ítems; la pantalla avisa antes.
     */
    public function eliminarModulo(int $id)
    {
        $modulo = Modulo::findOrFail($id);

        if (! $modulo->esCopia()) {
            return response()->json(['message' => 'Este módulo viene con el programa: apágalo en vez de borrarlo.'], 422);
        }

        $modulo->delete();

        return response()->json(['ok' => true]);
    }

    /** Lo que ve la pantalla de un módulo. La config va completa: no tiene que saber qué trae la plantilla. */
    private function paraPantalla(Modulo $m): array
    {
        return [
            'id'        => $m->id,
            'clave'     => $m->clave,
            'plantilla' => $m->plantilla,
            'nombre'    => $m->nombre,
            'icono'     => $m->icono,
            'config'    => $m->esCopia() ? $m->configCompleta() : null,
            'visible'   => $m->visible,
            'orden'     => $m->orden,
        ];
    }

    /** Las reglas de la config de un módulo copiado, con el prefijo de donde venga. */
    private static function reglasConfig(string $prefijo): array
    {
        return [
            $prefijo . 'unidad'    => 'nullable|string|max:12',
            $prefijo . 'singular'  => 'nullable|string|max:40',
            $prefijo . 'decimales' => 'nullable|integer|min:0|max:2',
        ];
    }

    /** Sólo lo que la config conoce, recortado, y sin vacíos. */
    private static function limpiarConfig(array $config): array
    {
        $limpia = [];
        foreach (['unidad', 'singular'] as $campo) {
            $valor = trim((string) ($config[$campo] ?? ''));
            if ($valor !== '') {
                $limpia[$campo] = $valor;
            }
        }
        if (isset($config['decimales']) && $config['decimales'] !== '') {
            $limpia['decimales'] = (int) $config['decimales'];
        }

        return $limpia;
    }

    /**
     * Una clave que no exista: `telas-espumas`, y si ya la hay, `telas-espumas-2`.
     * Con el nombre de la plantilla adelante para que al verla en la lista se
     * sepa de qué pantalla es.
     */
    private function claveLibre(string $plantilla, string $nombre): string
    {
        // Corta: la barra de abajo guarda los accesos fijos por este nombre y
        // no acepta más de cuarenta letras.
        $base  = rtrim(Str::limit($plantilla . '-' . (Str::slug($nombre) ?: 'modulo'), 36, ''), '-');
        $clave = $base;
        $n     = 1;
        while (Modulo::where('clave', $clave)->exists()) {
            $clave = $base . '-' . (++$n);
        }

        return $clave;
    }

    // ── Herramientas ──────────────────────────────────────────────────────────

    /**
     * GET /api/herramientas
     *
     * Para el panel del asesor van sólo las activas; para la pantalla de
     * administración, todas, porque desde ahí se vuelven a encender.
     */
    public function herramientas(Request $request)
    {
        $query = Herramienta::orderBy('seccion')->orderBy('orden')->orderBy('id');

        if (! $request->boolean('todas')) {
            $query->where('activo', true);
        }

        return response()->json($query->get());
    }

    /** POST /api/herramientas */
    public function crearHerramienta(Request $request)
    {
        $data = $this->validarHerramienta($request);

        // Al final de su sección, que es donde uno espera que aparezca lo que
        // acaba de crear.
        $data['orden'] = (int) Herramienta::where('seccion', $data['seccion'])->max('orden') + 10;

        return response()->json(Herramienta::create($data), 201);
    }

    /** PATCH /api/herramientas/{id} */
    public function actualizarHerramienta(Request $request, int $id)
    {
        $herramienta = Herramienta::findOrFail($id);
        $herramienta->update($this->validarHerramienta($request, parcial: true));

        return response()->json($herramienta);
    }

    /** DELETE /api/herramientas/{id} */
    public function eliminarHerramienta(int $id)
    {
        Herramienta::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function validarHerramienta(Request $request, bool $parcial = false): array
    {
        // Al editar sólo se valida lo que venga: la pantalla puede mandar
        // únicamente el interruptor de activo sin reenviar todo el contenido.
        $regla = fn (string $reglas) => $parcial ? "sometimes|{$reglas}" : $reglas;

        return $request->validate([
            'seccion'   => $regla('required|string|max:60'),
            'titulo'    => $regla('required|string|max:120'),
            'tipo'      => $regla('required|in:' . implode(',', Herramienta::TIPOS)),
            'contenido' => $regla('required|string|max:2000'),
            'subtitulo' => 'nullable|string|max:200',
            'icono'     => 'nullable|string|max:' . self::ICONO_MAX,
            'activo'    => 'nullable|boolean',
            'orden'     => 'nullable|integer|min:0|max:9999',
        ]);
    }
}
