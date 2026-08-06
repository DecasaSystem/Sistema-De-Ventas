<?php

namespace App\Http\Controllers;

use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    /** Un solo sitio que define qué se devuelve de un usuario. */
    private function comoJson(Usuario $u): array
    {
        return [
            'id'                  => $u->id,
            'nombre'              => $u->nombre,
            'email'               => $u->email,
            'rol'                 => $u->rol,
            'facturacion'         => (bool) $u->facturacion,
            'es_tapicero'         => (bool) $u->es_tapicero,
            'independiente'       => (bool) $u->independiente,
            'notif_asignar_fecha' => (bool) $u->notif_asignar_fecha,
            'notif_stock'         => (bool) $u->notif_stock,
            'acceso_redes'        => (bool) $u->acceso_redes,
            'acceso_comisiones'   => (bool) $u->acceso_comisiones,
            'recarga_telas'       => (bool) $u->recarga_telas,
            'tienda_default_id'   => $u->tienda_default_id,
            'tienda_default'      => $u->relationLoaded('tiendaDefault') ? $u->tiendaDefault : null,
            'activo'              => (bool) $u->activo,
            'created_at'          => $u->created_at,
        ];
    }

    public function index(Request $request)
    {
        $query = Usuario::with('tiendaDefault:id,nombre,ciudad');

        if ($rol = $request->query('rol')) {
            $query->where('rol', $rol);
        }

        if ($search = $request->query('search')) {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
            });
        }

        return response()->json(
            $query->orderBy('nombre')->paginate(20)->through(fn($u) => $this->comoJson($u))
        );
    }

    public function show($id)
    {
        $usuario = Usuario::with('tiendaDefault:id,nombre,ciudad')->findOrFail($id);

        return response()->json($this->comoJson($usuario));
    }

    public function store(Request $request)
    {
        $rolesProduccion = ['ebanista', 'despachador', 'conductor', 'costurero'];

        $data = $request->validate([
            'nombre'            => 'required|string|max:100',
            'email'             => 'required|email|unique:usuarios,email',
            'password'          => 'required|string|min:8|confirmed',
            'rol'                 => ['required', Rule::in(['vendedor', 'supervisor', 'conductor', 'ebanista', 'despachador', 'costurero'])],
            'facturacion'         => 'boolean',
            'es_tapicero'         => 'boolean',
            // Vendedor por su cuenta: no pertenece a ninguna tienda
            'independiente'       => 'boolean',
            'notif_asignar_fecha' => 'boolean',
            'notif_stock'         => 'boolean',
            'acceso_redes'        => 'boolean',
            'acceso_comisiones'   => 'boolean',
            'recarga_telas'       => 'boolean',
            'tienda_default_id' => [
                // Un independiente no elige tienda: se le asigna la sede propia.
                Rule::requiredIf(fn () => ! in_array($request->rol, $rolesProduccion)
                    && ! $request->boolean('independiente')),
                'nullable',
                'exists:tiendas,id',
            ],
        ], [
            'nombre.required'            => 'El nombre es obligatorio.',
            'nombre.max'                 => 'El nombre no puede tener más de 100 caracteres.',
            'email.required'             => 'El email es obligatorio.',
            'email.email'                => 'El email debe ser una dirección válida.',
            'email.unique'               => 'Este email ya está registrado.',
            'password.required'          => 'La contraseña es obligatoria.',
            'password.min'               => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'         => 'Las contraseñas no coinciden.',
            'rol.required'               => 'El rol es obligatorio.',
            'rol.in'                     => 'El rol no es válido.',
            'tienda_default_id.required' => 'La tienda predeterminada es obligatoria.',
            'tienda_default_id.exists'   => 'La tienda seleccionada no existe.',
        ]);

        $esSupervisor = ($data['rol'] === 'supervisor');

        $puedeAccesoRedes = in_array($data['rol'], ['vendedor', 'supervisor']);
        $puedeRecargaTelas = in_array($data['rol'], ['vendedor', 'supervisor']);

        // Solo un vendedor puede ir por su cuenta: un supervisor o un conductor
        // independiente no significa nada.
        $independiente = ($data['rol'] === 'vendedor') && $request->boolean('independiente');

        $usuario = Usuario::create([
            'nombre'              => $data['nombre'],
            'email'               => $data['email'],
            'password'            => Hash::make($data['password']),
            'rol'                 => $data['rol'],
            'facturacion'         => ($data['rol'] === 'vendedor') && $request->boolean('facturacion'),
            'es_tapicero'         => $esSupervisor && $request->boolean('es_tapicero'),
            'independiente'       => $independiente,
            'notif_asignar_fecha' => $esSupervisor && $request->boolean('notif_asignar_fecha'),
            'notif_stock'         => $esSupervisor && $request->boolean('notif_stock'),
            'acceso_redes'        => $puedeAccesoRedes && $request->boolean('acceso_redes'),
            'acceso_comisiones'   => $esSupervisor && $request->boolean('acceso_comisiones'),
            'recarga_telas'       => $puedeRecargaTelas && $request->boolean('recarga_telas'),
            'tienda_default_id'   => $independiente
                ? Tienda::sedeIndependientes()?->id
                : ($data['tienda_default_id'] ?? null),
            'activo'              => true,
        ]);

        return response()->json($this->comoJson($usuario), 201);
    }

    public function update(Request $request, $id)
    {
        $usuario = Usuario::findOrFail($id);
        $rolesProduccion = ['ebanista', 'despachador', 'conductor', 'costurero'];

        $data = $request->validate([
            'nombre'            => 'sometimes|string|max:100',
            'email'             => ['sometimes', 'email', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'rol'                 => ['sometimes', Rule::in(['vendedor', 'supervisor', 'conductor', 'ebanista', 'despachador', 'costurero'])],
            'facturacion'         => 'nullable|boolean',
            'es_tapicero'         => 'nullable|boolean',
            'independiente'       => 'nullable|boolean',
            'notif_asignar_fecha' => 'nullable|boolean',
            'notif_stock'         => 'nullable|boolean',
            'acceso_redes'        => 'nullable|boolean',
            'acceso_comisiones'   => 'nullable|boolean',
            'recarga_telas'       => 'nullable|boolean',
            'tienda_default_id'   => 'sometimes|nullable|exists:tiendas,id',
        ], [
            'nombre.max'               => 'El nombre no puede tener más de 100 caracteres.',
            'email.email'              => 'El email debe ser una dirección válida.',
            'email.unique'             => 'Este email ya está registrado.',
            'rol.in'                   => 'El rol no es válido.',
            'tienda_default_id.exists' => 'La tienda seleccionada no existe.',
        ]);

        $rolFinal = $data['rol'] ?? $usuario->rol;

        if ($request->has('es_tapicero')) {
            $data['es_tapicero'] = ($rolFinal === 'supervisor') && $request->boolean('es_tapicero');
        }
        if ($request->has('notif_asignar_fecha')) {
            $data['notif_asignar_fecha'] = ($rolFinal === 'supervisor') && $request->boolean('notif_asignar_fecha');
        }
        if ($request->has('notif_stock')) {
            $data['notif_stock'] = ($rolFinal === 'supervisor') && $request->boolean('notif_stock');
        }
        if ($request->has('facturacion')) {
            $data['facturacion'] = ($rolFinal === 'vendedor') && $request->boolean('facturacion');
        }
        if ($request->has('acceso_redes')) {
            $data['acceso_redes'] = in_array($rolFinal, ['vendedor', 'supervisor']) && $request->boolean('acceso_redes');
        }
        if ($request->has('acceso_comisiones')) {
            $data['acceso_comisiones'] = ($rolFinal === 'supervisor') && $request->boolean('acceso_comisiones');
        }
        if ($request->has('recarga_telas')) {
            $data['recarga_telas'] = in_array($rolFinal, ['vendedor', 'supervisor']) && $request->boolean('recarga_telas');
        }

        if ($request->has('independiente')) {
            $data['independiente'] = ($rolFinal === 'vendedor') && $request->boolean('independiente');
        }

        if (isset($data['rol']) && in_array($data['rol'], $rolesProduccion)) {
            $data['tienda_default_id'] = null;
        }

        // Al volverlo independiente pasa a la sede propia: deja de pertenecer a
        // una tienda. Al dejar de serlo hay que volver a asignarle una.
        $independienteFinal = $data['independiente'] ?? (bool) $usuario->independiente;
        if ($independienteFinal) {
            $data['tienda_default_id'] = Tienda::sedeIndependientes()?->id;
        } elseif ($usuario->independiente && ! $independienteFinal && empty($data['tienda_default_id'])) {
            return response()->json([
                'message' => 'Si deja de ser independiente hay que asignarle una tienda.',
                'errors'  => ['tienda_default_id' => ['Elige la tienda a la que queda asignado.']],
            ], 422);
        }

        $usuario->update($data);
        $usuario->load('tiendaDefault:id,nombre,ciudad');

        return response()->json($this->comoJson($usuario));
    }

    public function toggleActivo($id)
    {
        $usuario = Usuario::findOrFail($id);

        if ($usuario->id === auth()->id()) {
            abort(403, 'No puedes desactivar tu propia cuenta.');
        }

        $usuario->activo = !$usuario->activo;
        $usuario->save();

        return response()->json([
            'id'     => $usuario->id,
            'activo' => $usuario->activo,
        ]);
    }

    public function resetPassword(Request $request, $id)
    {
        $data = $request->validate([
            'password' => 'required|string|min:8',
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.min'      => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $usuario = Usuario::findOrFail($id);

        $usuario->update([
            'password' => Hash::make($data['password']),
        ]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    // GET /api/asesores — lista liviana de vendedores/supervisores activos (para co-vendedor)
    public function asesores(Request $request)
    {
        $yo = $request->user();

        return response()->json(
            Usuario::whereIn('rol', ['vendedor', 'supervisor'])
                ->where('activo', true)
                ->where('id', '!=', $yo->id)
                ->with('tiendaDefault:id,nombre')
                ->select('id', 'nombre', 'tienda_default_id', 'independiente')
                ->orderBy('nombre')
                ->get()
                ->map(fn($u) => [
                    'id'     => $u->id,
                    'nombre' => $u->nombre,
                    'tienda' => $u->tiendaDefault?->nombre ?? '—',
                    // Lo necesita la pantalla para saber si puede compartir la
                    // venta con un almacen.
                    'independiente' => (bool) $u->independiente,
                ])
        );
    }
}
