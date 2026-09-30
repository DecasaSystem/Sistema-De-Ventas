<?php

namespace App\Http\Controllers;

use App\Models\CatalogoTela;
use Illuminate\Http\Request;

class CatalogoTelaController extends Controller
{
    /**
     * GET /catalogo-telas
     * Retorna las entradas del catálogo agrupadas: [{ marca, tipos: [{ tipo, colores: [{id,color}] }] }]
     */
    public function index()
    {
        $rows = CatalogoTela::where('activo', true)
            ->orderBy('marca')->orderBy('tipo')->orderBy('color')
            ->get(['id', 'marca', 'tipo', 'color', 'referencia', 'textura']);

        // Por el nombre de venta ("LAYLA 01 CRUDO"), no por el nombre a secas:
        // es lo que se elige en la orden, y con dos LAYLA en BEIGE el nombre
        // solo no dice cuál (ver CatalogoTela::nombreVenta).
        $grouped = $rows->groupBy('marca')->map(fn($marcaRows, $marca) => [
            'marca' => $marca,
            'tipos' => $marcaRows->groupBy(fn ($r) => $r->nombre_venta)->map(fn($tipoRows, $tipo) => [
                'tipo'    => $tipo,
                'colores' => $tipoRows->map(fn($r) => [
                    'id'         => $r->id,
                    'color'      => $r->color,
                    'referencia' => $r->referencia,
                    'textura'    => $r->textura,
                ])->values(),
            ])->values(),
        ])->values();

        return response()->json($grouped);
    }

    /**
     * POST /catalogo-telas
     * Agrega una nueva combinación marca+tipo+color al catálogo.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'marca'           => 'required|string|max:100',
            'tipo'            => 'required|string|max:100',
            'color'           => 'required|string|max:100',
            'referencia'      => 'nullable|string|max:200',
            'textura'         => 'nullable|string|max:100',
            'foto_url'        => 'nullable|string|max:500',
            'metros_iniciales'=> 'nullable|numeric|min:0',
        ]);

        $referencia = isset($data['referencia']) && trim($data['referencia']) !== '' ? trim($data['referencia']) : null;

        // La misma tela es proveedor + nombre + referencia + color: LAYLA 01
        // CRUDO y LAYLA 02 PERLA pueden ser las dos BEIGE y son distintas.
        // Si ya existe (aunque se hubiera eliminado), se reusa.
        $tela = CatalogoTela::igualA($data['marca'], $data['tipo'], $referencia, $data['color'])
            ?? CatalogoTela::create([
                'marca'      => trim($data['marca']),
                'tipo'       => trim($data['tipo']),
                'color'      => trim($data['color']),
                'referencia' => $referencia,
                'textura'    => isset($data['textura']) && trim($data['textura']) !== '' ? trim($data['textura']) : null,
                'foto_url'   => $data['foto_url'] ?? null,
                'activo'     => true,
            ]);

        if (!$tela->activo) {
            $tela->update(['activo' => true]);
        }
        // Si ya existía sin foto y ahora se envía una, guardarla.
        if (!empty($data['foto_url']) && $tela->foto_url !== $data['foto_url']) {
            $tela->update(['foto_url' => $data['foto_url']]);
        }

        $metros = (float) ($data['metros_iniciales'] ?? 0);
        if ($metros > 0) {
            \Illuminate\Support\Facades\DB::table('catalogo_telas')
                ->where('id', $tela->id)
                ->increment('metros_disponibles', $metros);
            $tela = $tela->fresh();
        }

        return response()->json($this->paraPantalla($tela), 201);
    }

    /**
     * PATCH /catalogo-telas/{id}
     * Actualiza la foto (u otros datos) de una tela existente.
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'foto_url'   => 'sometimes|nullable|string|max:500',
            'referencia' => 'sometimes|nullable|string|max:200',
            'textura'    => 'sometimes|nullable|string|max:100',
            // Corregir cómo se creó: el nombre con la referencia pegada, un
            // color mal escrito, otro proveedor.
            'marca'      => 'sometimes|required|string|max:100',
            'tipo'       => 'sometimes|required|string|max:100',
            'color'      => 'sometimes|required|string|max:100',
        ]);
        foreach (['marca', 'tipo', 'color', 'referencia', 'textura'] as $campo) {
            if (array_key_exists($campo, $data) && is_string($data[$campo])) {
                $data[$campo] = trim($data[$campo]) === '' && in_array($campo, ['referencia', 'textura'], true)
                    ? null
                    : trim($data[$campo]);
            }
        }

        $tela = CatalogoTela::findOrFail($id);

        $marca = array_key_exists('marca', $data) ? $data['marca'] : $tela->marca;
        $tipo  = array_key_exists('tipo', $data)  ? $data['tipo']  : $tela->tipo;
        $color = array_key_exists('color', $data) ? $data['color'] : $tela->color;
        $ref   = array_key_exists('referencia', $data) ? $data['referencia'] : $tela->referencia;

        // Lo que la identifica: proveedor, nombre, referencia y color.
        $cambiaIdentidad = $marca !== $tela->marca || $tipo !== $tela->tipo || $color !== $tela->color
            || mb_strtolower(trim((string) $ref)) !== mb_strtolower(trim((string) $tela->referencia));

        if ($cambiaIdentidad) {
            // Las órdenes guardan su tela como texto ("Proveedor · Nombre de
            // venta · Color") y con eso se enlazan a lo que tienen apartado. Si
            // una orden la tiene apartada y ese texto cambia, deja de
            // encontrarla y el apartado queda suelto. La referencia solo
            // cuenta si cambia el nombre de venta (en las del Excel, no).
            $cambiaTexto = $marca !== $tela->marca || $color !== $tela->color
                || CatalogoTela::nombreVenta($tipo, $ref) !== $tela->nombre_venta;
            if ($cambiaTexto && $this->metrosApartados($tela) > 0) {
                return response()->json([
                    'message' => 'Tiene metros apartados para órdenes: el proveedor, el nombre, la referencia y el '
                               . 'color no se pueden cambiar hasta que se entreguen, porque las órdenes la encuentran '
                               . 'por ellos. La textura sí.',
                ], 422);
            }

            $otra = CatalogoTela::igualA($marca, $tipo, $ref, $color, $tela->id);
            if ($otra) {
                $cual = CatalogoTela::nombreVenta($tipo, $ref);
                return response()->json([
                    'message' => $otra->activo
                        ? "Ya existe \"{$cual}\" en {$color} de {$marca}. Si es la misma, elimina esta y recárgale los metros a esa."
                        : "Hay una \"{$cual}\" en {$color} de {$marca} que se eliminó. Créala de nuevo para recuperarla y elimina esta.",
                ], 422);
            }
        }

        $tela->update($data);

        return response()->json($this->paraPantalla($tela->fresh()));
    }

    /** Metros que las órdenes tienen apartados de esta tela. */
    private function metrosApartados(CatalogoTela $tela): float
    {
        $apartados = (float) $tela->metros_reservados;
        if ($apartados <= 0 && \Illuminate\Support\Facades\Schema::hasTable('tela_reservas')) {
            $apartados = (float) \Illuminate\Support\Facades\DB::table('tela_reservas')
                ->where('catalogo_tela_id', $tela->id)
                ->where('estado', 'reservada')
                ->sum('metros');
        }
        return $apartados;
    }

    /** Lo que la pantalla de Telas necesita de una tela (lo mismo que devuelve store()). */
    private function paraPantalla(CatalogoTela $tela): array
    {
        return [
            'ok'                 => true,
            'id'                 => $tela->id,
            'marca'              => $tela->marca,
            'tipo'               => $tela->tipo,
            'nombre_venta'       => $tela->nombre_venta,
            'color'              => $tela->color,
            'referencia'         => $tela->referencia,
            'textura'            => $tela->textura,
            'foto_url'           => $tela->foto_url,
            'metros_disponibles' => (float) $tela->metros_disponibles,
            'metros_reservados'  => (float) $tela->metros_reservados,
            'metros_libres'      => round((float) $tela->metros_disponibles - (float) $tela->metros_reservados, 2),
        ];
    }

    /**
     * POST /catalogo-telas/batch
     * Agrega varios colores de una vez para una misma marca+tipo.
     */
    public function storeBatch(Request $request)
    {
        $data = $request->validate([
            'marca'     => 'required|string|max:100',
            'tipo'      => 'required|string|max:100',
            'colores'   => 'required|array|min:1',
            'colores.*' => 'required|string|max:100',
        ]);

        $creados = [];
        foreach ($data['colores'] as $color) {
            // Sin referencia: la misma regla que store() (ver CatalogoTela::igualA).
            $tela = CatalogoTela::igualA($data['marca'], $data['tipo'], null, $color)
                ?? CatalogoTela::create([
                    'marca' => trim($data['marca']), 'tipo' => trim($data['tipo']), 'color' => trim($color),
                    'activo' => true,
                ]);
            if (!$tela->activo) {
                $tela->update(['activo' => true]);
            }
            $creados[] = $tela;
        }

        return response()->json($creados, 201);
    }

    /**
     * DELETE /catalogo-telas/{id}
     * Desactiva una entrada del catálogo.
     */
    public function destroy(int $id)
    {
        $tela = CatalogoTela::findOrFail($id);

        // No se borra de la base: queda inactiva. Las órdenes que la nombran
        // siguen diciendo qué tela llevaban, y si alguien la vuelve a crear
        // con el mismo proveedor, nombre y color, reaparece (ver store()).
        //
        // Lo que no se puede es quitar una tela que una orden tiene apartada:
        // esos metros se liberan o se gastan con la orden, y sin la tela el
        // apartado quedaría colgando de algo que ya no aparece en ningún lado.
        $apartados = $this->metrosApartados($tela);
        if ($apartados > 0) {
            return response()->json([
                'message' => "No se puede eliminar: tiene {$apartados} m apartados para órdenes. "
                           . 'Primero hay que cambiarles la tela o esperar a que se entreguen.',
            ], 422);
        }

        $tela->update(['activo' => false]);
        return response()->json(['ok' => true]);
    }
}
