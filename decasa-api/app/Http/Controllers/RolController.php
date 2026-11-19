<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Esquema;
use App\Support\ModulosDeVenta;
use App\Support\RangoDeUsuarios;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Los roles/puestos de trabajo, configurables por empresa desde Gestión.
 * Cada uno se apoya en un "arquetipo" (vendedor, supervisor, conductor,
 * taller) que determina el comportamiento de fondo — comisiones, caja, si
 * pertenece a una tienda—. El NOMBRE lo pone cada empresa: "Metalero",
 * "Soldador", lo que sea. El arquetipo solo dice cómo se comporta.
 */
class RolController extends Controller
{
    public const ARQUETIPOS = ['vendedor', 'supervisor', 'conductor', 'taller'];

    /** GET /api/roles?incluir_inactivos=1 */
    public function index(Request $request)
    {
        $q = Rol::query()->orderBy('orden')->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get());
    }

    /** POST /api/roles */
    public function store(Request $request)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede crear roles.'], 403);
        }
        if (! RangoDeUsuarios::puedeTocarRoles($request->user())) {
            return response()->json(['message' => 'Los roles los crea y los cambia el super admin.'], 403);
        }

        $data = $request->validate([
            'nombre'    => 'required|string|max:80',
            'arquetipo' => ['required', Rule::in(self::ARQUETIPOS)],
        ] + $this->reglasBanderas() + $this->reglasModulos());

        $clave = $this->claveLibre($data['nombre']);

        $rol = Rol::create([
            'clave'     => $clave,
            'nombre'    => $data['nombre'],
            'arquetipo' => $data['arquetipo'],
            'orden'     => (int) (Rol::max('orden') ?? 0) + 10,
            'activo'    => true,
        ] + $this->soloBanderas($data) + $this->soloModulos($data));

        return response()->json($rol, 201);
    }

    /**
     * PATCH /api/roles/{id}
     *
     * El arquetipo no se puede cambiar después de creado: cambiarlo movería
     * de golpe el comportamiento de fondo (comisiones, caja...) de todo el
     * que tenga este rol, sin que nadie se lo haya pedido a propósito.
     */
    public function update(Request $request, int $id)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede cambiar roles.'], 403);
        }
        if (! RangoDeUsuarios::puedeTocarRoles($request->user())) {
            return response()->json(['message' => 'Los roles los crea y los cambia el super admin.'], 403);
        }

        $rol = Rol::findOrFail($id);

        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:80',
            'orden'  => 'sometimes|integer|min:0|max:9999',
            'activo' => 'sometimes|boolean',
        ] + $this->reglasBanderas(sometimes: true) + $this->reglasModulos());

        $cambianModulos = array_key_exists('modulos_venta', $data);
        $rol->update(array_diff_key($data, ['modulos_venta' => 1]) + $this->soloModulos($data));

        // El rol manda en sus módulos: a todos los que lo tienen se les
        // vuelven a poner, respetando lo que a cada uno se le ajustó a mano.
        if ($cambianModulos) {
            Usuario::where('rol_id', $rol->id)->with('rolAsignado')->get()
                ->each(fn (Usuario $u) => ModulosDeVenta::aplicar($u));
        }

        return response()->json($rol->fresh());
    }

    /**
     * DELETE /api/roles/{id}
     *
     * Solo se borra de verdad si nadie lo tiene asignado. Si algún
     * trabajador lo tiene, se desactiva: borrarlo lo dejaría sin rol de un
     * momento a otro.
     */
    public function destroy(Request $request, int $id)
    {
        if ($request->user()->rol !== 'supervisor') {
            return response()->json(['message' => 'Solo un supervisor puede borrar roles.'], 403);
        }
        if (! RangoDeUsuarios::puedeTocarRoles($request->user())) {
            return response()->json(['message' => 'Los roles los crea y los cambia el super admin.'], 403);
        }

        $rol = Rol::findOrFail($id);
        $usados = Usuario::where('rol_id', $id)->count();

        if ($usados > 0) {
            $rol->update(['activo' => false]);

            return response()->json([
                'message' => "\"{$rol->nombre}\" lo tiene asignado {$usados} trabajador(es), así que no se borra: " .
                             'queda desactivado y deja de ofrecerse, pero conservan su asignación.',
                'desactivado' => true,
            ]);
        }

        $rol->delete();

        return response()->json(['message' => 'Rol eliminado.', 'desactivado' => false]);
    }

    private function reglasBanderas(bool $sometimes = false): array
    {
        $regla = $sometimes ? 'sometimes|boolean' : 'boolean';
        return [
            'acceso_redes'       => $regla,
            'acceso_comisiones'  => $regla,
            'recarga_telas'      => $regla,
            'acceso_surtir'      => $regla,
            'acceso_costos'      => $regla,
            'acceso_proveedores' => $regla,
            'acceso_despacho'    => $regla,
            'acceso_produccion'  => $regla,
            'acceso_reserva'     => $regla,
            'acceso_nomina'      => $regla,
            'acceso_compras'     => $regla,
        ];
    }

    /** Los módulos de venta que trae el rol (ModulosDeVenta). */
    private function reglasModulos(): array
    {
        return [
            'modulos_venta'   => 'sometimes|nullable|array',
            'modulos_venta.*' => ['string', Rule::in(array_keys(ModulosDeVenta::MODULOS))],
        ];
    }

    private function soloModulos(array $data): array
    {
        if (! array_key_exists('modulos_venta', $data) || ! Esquema::columna('roles', 'modulos_venta')) return [];

        return ['modulos_venta' => $data['modulos_venta'] === null ? null : array_values(array_unique($data['modulos_venta']))];
    }

    private function soloBanderas(array $data): array
    {
        return array_intersect_key($data, array_flip(array_keys($this->reglasBanderas())));
    }

    /** Una clave que no choque con otra existente. */
    private function claveLibre(string $nombre): string
    {
        $base  = Str::slug($nombre, '_') ?: 'rol';
        $base  = substr($base, 0, 54);
        $clave = $base;
        $n     = 2;
        while (Rol::where('clave', $clave)->exists()) {
            $clave = substr($base, 0, 54 - strlen((string) $n) - 1) . '_' . $n;
            $n++;
        }
        return $clave;
    }
}
