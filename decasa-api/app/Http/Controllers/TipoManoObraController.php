<?php

namespace App\Http\Controllers;

use App\Models\TipoManoObra;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tipos de mano de obra sin costo fijo (usuario, 2026-11-11: "esqueletería… no tienen un costo
 * fijo ni calculable, entonces un apartado para crear las opciones de mano de obra y al añadirla
 * un select de esos creados y que el mismo ponga el costo").
 *
 * El tipo solo dice QUÉ trabajo es; el costo lo pone quien arma cada ficha. Por eso, a
 * diferencia de Tarifas, cambiar un tipo nunca cambia el costo de una ficha: solo el nombre
 * que se ve en sus renglones.
 */
class TipoManoObraController extends Controller
{
    /** GET /costos/tipos-mano-obra — todos (los archivados también), con en cuántas fichas se usan. */
    public function index()
    {
        $usos = DB::table('ficha_tecnica_items')->whereNotNull('tipo_mano_obra_id')
            ->groupBy('tipo_mano_obra_id')
            ->selectRaw('tipo_mano_obra_id, COUNT(DISTINCT ficha_tecnica_id) AS n')
            ->pluck('n', 'tipo_mano_obra_id');

        $tipos = TipoManoObra::orderBy('orden')->orderBy('nombre')->get()
            ->map(fn ($t) => $t->toArray() + ['usos' => (int) ($usos[$t->id] ?? 0)]);

        return response()->json($tipos);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['nombre' => 'required|string|max:100']);
        $nombre = $this->limpio($data['nombre']);

        if ($repetido = $this->otroConElNombre($nombre)) return $this->yaExiste($repetido);

        $tipo = TipoManoObra::create([
            'nombre' => $nombre,
            'orden'  => (int) TipoManoObra::max('orden') + 1,
        ]);

        return response()->json($tipo->toArray() + ['usos' => 0], 201);
    }

    /**
     * PATCH /costos/tipos-mano-obra/{id} — renombrar o archivar/activar. Renombrar cambia el
     * nombre en los renglones de las fichas que lo usan (no su costo).
     */
    public function update(Request $request, int $id)
    {
        $tipo = TipoManoObra::findOrFail($id);
        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:100',
            'activo' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($tipo, $data) {
            if (isset($data['nombre'])) {
                $nombre = $this->limpio($data['nombre']);
                if ($nombre !== $tipo->nombre) {
                    if ($repetido = $this->otroConElNombre($nombre, $tipo->id)) {
                        abort($this->yaExiste($repetido));
                    }
                    DB::table('ficha_tecnica_items')->where('tipo_mano_obra_id', $tipo->id)
                        ->update(['descripcion' => $nombre, 'updated_at' => now()]);
                    $tipo->nombre = $nombre;
                }
            }
            if (array_key_exists('activo', $data)) $tipo->activo = $data['activo'];
            $tipo->save();
        });

        return response()->json($tipo->fresh()->toArray() + [
            'usos' => (int) DB::table('ficha_tecnica_items')->where('tipo_mano_obra_id', $tipo->id)->distinct()->count('ficha_tecnica_id'),
        ]);
    }

    /**
     * DELETE /costos/tipos-mano-obra/{id} — solo si ninguna ficha lo usa. Si lo usan, se
     * archiva (deja de ofrecerse y las fichas siguen igual): no se borra lo que tiene costos.
     */
    public function destroy(int $id)
    {
        $tipo = TipoManoObra::findOrFail($id);

        if (DB::table('ficha_tecnica_items')->where('tipo_mano_obra_id', $tipo->id)->exists()) {
            return response()->json(['message' => 'Hay fichas que lo usan: archívalo en vez de borrarlo.'], 422);
        }

        $tipo->delete();

        return response()->json(['ok' => true]);
    }

    /** "  esqueleteria " → "Esqueleteria": primera letra en mayúscula, espacios de más fuera. */
    private function limpio(string $nombre): string
    {
        $n = preg_replace('/\s+/u', ' ', trim($nombre));

        return mb_strtoupper(mb_substr($n, 0, 1)) . mb_substr($n, 1);
    }

    private function otroConElNombre(string $nombre, ?int $salvo = null): ?TipoManoObra
    {
        return TipoManoObra::whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($nombre)])
            ->when($salvo, fn ($q) => $q->where('id', '!=', $salvo))
            ->first();
    }

    private function yaExiste(TipoManoObra $repetido)
    {
        return response()->json([
            'message'      => "Ya existe «{$repetido->nombre}»" . ($repetido->activo ? '.' : ' (archivado: actívalo).'),
            'existente_id' => $repetido->id,
        ], 422);
    }
}
