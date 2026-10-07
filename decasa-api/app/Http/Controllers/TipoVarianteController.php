<?php

namespace App\Http\Controllers;

use App\Models\TipoVariante;
use App\Models\TipoVarianteOpcion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TipoVarianteController extends Controller
{
    /**
     * GET /api/tipos-variante
     * Devuelve todos los tipos activos con sus opciones.
     */
    public function index()
    {
        $tipos = TipoVariante::where('activo', true)
            ->orderBy('nombre')
            ->with('opciones')
            ->get();

        return response()->json($tipos);
    }

    /**
     * POST /api/tipos-variante
     * Crea un tipo nuevo.
     */
    public function store(Request $request)
    {
        $request->merge(['nombre' => trim((string) $request->input('nombre'))]);

        // Solo choca con uno que esté en uso. Eliminar un tipo lo desactiva
        // (los productos que lo usaron siguen apuntándole), y antes ese
        // desactivado seguía ocupando el nombre: borrar "alas vintage" y
        // volver a crearlo decía que ya existía, sin que se viera en la lista.
        $data = $request->validate([
            'nombre'        => ['required', 'string', 'max:100',
                                Rule::unique('tipos_variante', 'nombre')->where('activo', true)],
            'afecta_precio' => 'required|boolean',
        ], [
            'nombre.unique' => 'Ya hay un tipo de variante con ese nombre.',
        ]);

        // El nombre sigue siendo único en la tabla, así que el eliminado se
        // vuelve a usar en vez de crear otro. Vuelve sin sus opciones: se creó
        // de nuevo y arranca limpio; las que se agreguen con el mismo nombre
        // se reactivan solas (storeOpciones).
        $eliminado = TipoVariante::where('nombre', $data['nombre'])->where('activo', false)->first();
        if ($eliminado) {
            $eliminado->update(['activo' => true, 'afecta_precio' => $data['afecta_precio']]);
            TipoVarianteOpcion::where('tipo_variante_id', $eliminado->id)->update(['activo' => false]);
            $tipo = $eliminado;
        } else {
            $tipo = TipoVariante::create($data);
        }

        $tipo->load('opciones');

        return response()->json($tipo, 201);
    }

    /**
     * PATCH /api/tipos-variante/{id}
     * Cambia el nombre de un tipo.
     *
     * Los productos y el stock se enlazan por id, así que todo lo que lo usa
     * pasa a mostrar el nombre nuevo. Lo ya vendido no cambia: la orden guardó
     * el texto que se eligió (orden_items.variante_detalle).
     */
    public function update(Request $request, int $id)
    {
        $tipo = TipoVariante::where('activo', true)->findOrFail($id);
        $request->merge(['nombre' => trim((string) $request->input('nombre'))]);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100',
                         Rule::unique('tipos_variante', 'nombre')->where('activo', true)->ignore($tipo->id)],
        ], [
            'nombre.unique' => 'Ya hay un tipo de variante con ese nombre.',
        ]);

        // Un eliminado con ese nombre lo sigue ocupando en la tabla: se le
        // cambia el suyo para dejarlo libre (nadie lo ve ni lo usa).
        TipoVariante::where('nombre', $data['nombre'])->where('activo', false)->get()
            ->each(fn ($t) => $t->update(['nombre' => $this->nombreDeEliminado($t->nombre, $t->id)]));

        $tipo->update(['nombre' => $data['nombre']]);

        return response()->json($tipo->load('opciones'));
    }

    /**
     * DELETE /api/tipos-variante/{id}
     * Desactiva un tipo.
     */
    public function destroy(int $id)
    {
        $tipo = TipoVariante::findOrFail($id);
        $tipo->update(['activo' => false]);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/tipos-variante/{id}/opciones
     * Agrega una o varias opciones a un tipo existente.
     */
    public function storeOpciones(Request $request, int $id)
    {
        $tipo = TipoVariante::where('activo', true)->findOrFail($id);

        $data = $request->validate([
            'opciones'   => 'required|array|min:1',
            'opciones.*' => 'required|string|max:100',
        ]);

        $creadas = [];
        foreach ($data['opciones'] as $nombre) {
            $opcion = TipoVarianteOpcion::firstOrCreate(
                ['tipo_variante_id' => $tipo->id, 'nombre' => trim($nombre)],
                ['activo' => true]
            );
            if (!$opcion->activo) {
                $opcion->update(['activo' => true]);
            }
            $creadas[] = $opcion;
        }

        $tipo->load('opciones');
        return response()->json($tipo, 201);
    }

    /**
     * PATCH /api/tipos-variante/opciones/{id}
     * Cambia el nombre de una opción ("3 puestos" → "Tres puestos").
     *
     * Igual que el tipo: el precio y el stock que tiene en cada producto
     * siguen con ella, y las órdenes ya hechas conservan el nombre de antes.
     */
    public function updateOpcion(Request $request, int $id)
    {
        $opcion = TipoVarianteOpcion::where('activo', true)->findOrFail($id);
        $request->merge(['nombre' => trim((string) $request->input('nombre'))]);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100',
                         Rule::unique('tipo_variante_opciones', 'nombre')
                             ->where('tipo_variante_id', $opcion->tipo_variante_id)
                             ->where('activo', true)
                             ->ignore($opcion->id)],
        ], [
            'nombre.unique' => 'Este tipo ya tiene una opción con ese nombre.',
        ]);

        TipoVarianteOpcion::where('tipo_variante_id', $opcion->tipo_variante_id)
            ->where('nombre', $data['nombre'])->where('activo', false)->get()
            ->each(fn ($o) => $o->update(['nombre' => $this->nombreDeEliminado($o->nombre, $o->id)]));

        $opcion->update(['nombre' => $data['nombre']]);

        return response()->json(TipoVariante::with('opciones')->findOrFail($opcion->tipo_variante_id));
    }

    /**
     * DELETE /api/tipos-variante/opciones/{id}
     * Desactiva una opción.
     */
    public function destroyOpcion(int $id)
    {
        $opcion = TipoVarianteOpcion::findOrFail($id);
        $opcion->update(['activo' => false]);
        return response()->json(['ok' => true]);
    }

    /** "Roble" eliminado → "Roble (eliminado #12)", sin pasar de 100 caracteres. */
    private function nombreDeEliminado(string $nombre, int $id): string
    {
        $sufijo = " (eliminado #{$id})";
        return mb_substr($nombre, 0, 100 - mb_strlen($sufijo)) . $sufijo;
    }
}
