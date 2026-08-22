<?php

namespace App\Http\Controllers;

use App\Models\Rol;
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
            'cedula'              => $u->cedula,
            'email'               => $u->email,
            // Trabajador de fábrica: sin login, sin tienda, sin permisos. Su
            // rol hace de oficio (Lijador, Laquero...) y aparece en Nómina.
            'no_usa_programa'     => (bool) $u->no_usa_programa,
            'apto_comisiones'     => (bool) $u->apto_comisiones,
            'periodicidad'        => $u->periodicidad,
            'nomina_sueldo_id'    => $u->nomina_sueldo_id,
            'rol'                 => $u->rol,
            'rol_id'              => $u->rol_id,
            'rol_nombre'          => $u->relationLoaded('rolAsignado') ? $u->rolAsignado?->nombre : null,
            'arquetipo'           => $u->relationLoaded('rolAsignado') ? $u->rolAsignado?->arquetipo : null,
            'facturacion'         => (bool) $u->facturacion,
            'independiente'       => (bool) $u->independiente,
            'notif_asignar_fecha' => (bool) $u->notif_asignar_fecha,
            'notif_stock'         => (bool) $u->notif_stock,
            'acceso_redes'        => (bool) $u->acceso_redes,
            'acceso_comisiones'   => (bool) $u->acceso_comisiones,
            'recarga_telas'       => (bool) $u->recarga_telas,
            'acceso_surtir'       => (bool) $u->acceso_surtir,
            'acceso_costos'       => (bool) $u->acceso_costos,
            'acceso_proveedores'  => (bool) $u->acceso_proveedores,
            'acceso_despacho'     => (bool) $u->acceso_despacho,
            'acceso_produccion'   => (bool) $u->acceso_produccion,
            'acceso_reserva'      => (bool) $u->acceso_reserva,
            'acceso_nomina'       => (bool) $u->acceso_nomina,
            'acceso_compras'      => (bool) $u->acceso_compras,
            've_todas_ordenes'    => (bool) $u->ve_todas_ordenes,
            'tienda_default_id'   => $u->tienda_default_id,
            'tienda_default'      => $u->relationLoaded('tiendaDefault') ? $u->tiendaDefault : null,
            'activo'              => (bool) $u->activo,
            'created_at'          => $u->created_at,
            // Cómo le va en el taller. Va aquí para que al buscar a alguien se
            // vea de una su puntuación y se sepa a quién conviene darle trabajo.
            'desempeno'           => $u->pasos_taller === null ? null : [
                'pasos'            => (int) $u->pasos_taller,
                'calificaciones'   => (int) $u->calificaciones_taller,
                'calidad_promedio' => $u->calidad_promedio !== null ? round((float) $u->calidad_promedio, 2) : null,
                'horas_totales'    => round((float) ($u->horas_taller ?? 0), 2),
            ],
        ];
    }

    /**
     * Los números del taller, resueltos en la misma consulta.
     *
     * Calcularlos usuario por usuario dispararía veinte consultas por página
     * sólo para pintar unas estrellas.
     */
    private function conDesempeno($query)
    {
        return $query
            ->withCount([
                'participacionesPaso as pasos_taller',
                'participacionesPaso as calificaciones_taller' => fn ($q) => $q->whereNotNull('calidad'),
            ])
            ->withAvg(['participacionesPaso as calidad_promedio' => fn ($q) => $q->whereNotNull('calidad')], 'calidad')
            ->withSum('participacionesPaso as horas_taller', 'horas');
    }

    public function index(Request $request)
    {
        $query = $this->conDesempeno(
            Usuario::with(['tiendaDefault:id,nombre,ciudad', 'rolAsignado'])
        );

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
        $usuario = $this->conDesempeno(
            Usuario::with(['tiendaDefault:id,nombre,ciudad', 'rolAsignado'])
        )->findOrFail($id);

        return response()->json($this->comoJson($usuario));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'            => 'required|string|max:100',
            // Quien no usa el programa no lleva correo ni contraseña: no
            // tiene con qué iniciar sesión, y pedírselos obligaría a
            // inventarle un correo falso a cada persona de fábrica.
            'no_usa_programa'   => 'boolean',
            'email'             => 'required_if:no_usa_programa,false,0|nullable|email|unique:usuarios,email',
            'password'          => 'required_if:no_usa_programa,false,0|nullable|string|min:8|confirmed',
            'cedula'            => 'nullable|string|max:20|unique:usuarios,cedula',
            'apto_comisiones'   => 'boolean',
            'periodicidad'      => ['nullable', Rule::in(['diario', 'semanal', 'quincenal', '20_dias', 'mensual'])],
            'nomina_sueldo_id'  => 'nullable|exists:nomina_sueldos,id',
            'rol_id'              => ['required', 'exists:roles,id'],
            'facturacion'         => 'boolean',
            // Vendedor por su cuenta: no pertenece a ninguna tienda
            'independiente'       => 'boolean',
            'notif_asignar_fecha' => 'boolean',
            'notif_stock'         => 'boolean',
            'acceso_redes'        => 'boolean',
            'acceso_comisiones'   => 'boolean',
            'recarga_telas'       => 'boolean',
            'acceso_surtir'       => 'boolean',
            'acceso_costos'       => 'boolean',
            'acceso_proveedores'  => 'boolean',
            'acceso_despacho'     => 'boolean',
            'acceso_produccion'   => 'boolean',
            'acceso_reserva'      => 'boolean',
            'acceso_nomina'       => 'boolean',
            'acceso_compras'      => 'boolean',
            've_todas_ordenes'    => 'boolean',
            'tienda_default_id' => [
                // Un independiente no elige tienda: se le asigna la sede propia.
                // Un supervisor tampoco es obligatorio: varios son jefes que no
                // pertenecen a ninguna tienda en particular, y forzarles una
                // los metía de cabeza extra en el reparto del 5% de almacén de
                // esa tienda, diluyendo lo que le tocaba a cada vendedor real.
                // Solo el arquetipo "vendedor" la necesita de verdad.
                Rule::requiredIf(function () use ($request) {
                    $arquetipo = Rol::find($request->input('rol_id'))?->arquetipo;
                    return $arquetipo === 'vendedor' && ! $request->boolean('independiente');
                }),
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
            'rol_id.required'            => 'El rol es obligatorio.',
            'rol_id.exists'              => 'El rol no es válido.',
            'tienda_default_id.required' => 'La tienda predeterminada es obligatoria.',
            'tienda_default_id.exists'   => 'La tienda seleccionada no existe.',
        ]);

        $arquetipo = Rol::findOrFail($data['rol_id'])->arquetipo;

        $esSupervisor = ($arquetipo === 'supervisor');

        $puedeAccesoRedes = in_array($arquetipo, ['vendedor', 'supervisor']);
        $puedeRecargaTelas = in_array($arquetipo, ['vendedor', 'supervisor']);

        // Solo un vendedor puede ir por su cuenta: un supervisor o un conductor
        // independiente no significa nada.
        $independiente = ($arquetipo === 'vendedor') && $request->boolean('independiente');

        $noUsaPrograma = $request->boolean('no_usa_programa');

        $usuario = Usuario::create([
            'nombre'              => $data['nombre'],
            'cedula'              => $data['cedula'] ?? null,
            // El de fábrica queda sin credenciales: el login busca por correo,
            // así que sin correo no hay forma de que entre ni por accidente.
            'email'               => $noUsaPrograma ? null : $data['email'],
            'password'            => $noUsaPrograma ? null : Hash::make($data['password']),
            'no_usa_programa'     => $noUsaPrograma,
            // Quien no usa el programa no hace ventas: no puede ser apto.
            'apto_comisiones'     => ! $noUsaPrograma && $request->boolean('apto_comisiones'),
            'periodicidad'        => $data['periodicidad'] ?? 'quincenal',
            'nomina_sueldo_id'    => $data['nomina_sueldo_id'] ?? null,
            'rol_id'              => $data['rol_id'],
            'facturacion'         => ($arquetipo === 'vendedor') && $request->boolean('facturacion'),
            // Sin restricción de rol: es justo lo que se pidió, que un perfil
            // de producción se le pueda asignar a cualquier trabajador.
            'independiente'       => $independiente,
            'notif_asignar_fecha' => $esSupervisor && $request->boolean('notif_asignar_fecha'),
            'notif_stock'         => $esSupervisor && $request->boolean('notif_stock'),
            'acceso_redes'        => $puedeAccesoRedes && $request->boolean('acceso_redes'),
            'acceso_comisiones'   => $esSupervisor && $request->boolean('acceso_comisiones'),
            'recarga_telas'       => $puedeRecargaTelas && $request->boolean('recarga_telas'),
            // Igual que acceso_surtir: estos módulos ya no van atados al rol,
            // así que se guardan tal cual se pidan, sin restricción — es
            // justo lo que se pidió, poder dárselos a cualquiera.
            'acceso_surtir'       => $request->boolean('acceso_surtir'),
            'acceso_costos'       => $request->boolean('acceso_costos'),
            'acceso_proveedores'  => $request->boolean('acceso_proveedores'),
            'acceso_despacho'     => $request->boolean('acceso_despacho'),
            'acceso_produccion'   => $request->boolean('acceso_produccion'),
            'acceso_reserva'      => $request->boolean('acceso_reserva'),
            'acceso_nomina'       => $request->boolean('acceso_nomina'),
            'acceso_compras'      => $request->boolean('acceso_compras'),
            've_todas_ordenes'    => $request->boolean('ve_todas_ordenes'),
            'tienda_default_id'   => $independiente
                ? Tienda::sedeIndependientes()?->id
                : ($data['tienda_default_id'] ?? null),
            'activo'              => true,
        ]);

        return response()->json($this->comoJson($usuario->load(['rolAsignado'])), 201);
    }

    public function update(Request $request, $id)
    {
        $usuario = Usuario::findOrFail($id);

        $data = $request->validate([
            'nombre'            => 'sometimes|string|max:100',
            'email'             => ['sometimes', 'nullable', 'email', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'cedula'            => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('usuarios', 'cedula')->ignore($usuario->id)],
            'apto_comisiones'   => 'nullable|boolean',
            'periodicidad'      => ['sometimes', 'required', Rule::in(['diario', 'semanal', 'quincenal', '20_dias', 'mensual'])],
            'nomina_sueldo_id'  => 'sometimes|nullable|exists:nomina_sueldos,id',
            'rol_id'              => ['sometimes', 'exists:roles,id'],
            'facturacion'         => 'nullable|boolean',
            'independiente'       => 'nullable|boolean',
            'notif_asignar_fecha' => 'nullable|boolean',
            'notif_stock'         => 'nullable|boolean',
            'acceso_redes'        => 'nullable|boolean',
            'acceso_comisiones'   => 'nullable|boolean',
            'recarga_telas'       => 'nullable|boolean',
            'acceso_surtir'       => 'nullable|boolean',
            'acceso_costos'       => 'nullable|boolean',
            'acceso_proveedores'  => 'nullable|boolean',
            'acceso_despacho'     => 'nullable|boolean',
            'acceso_produccion'   => 'nullable|boolean',
            'acceso_reserva'      => 'nullable|boolean',
            'acceso_nomina'       => 'nullable|boolean',
            'acceso_compras'      => 'nullable|boolean',
            've_todas_ordenes'    => 'nullable|boolean',
            'tienda_default_id'   => 'sometimes|nullable|exists:tiendas,id',
        ], [
            'nombre.max'               => 'El nombre no puede tener más de 100 caracteres.',
            'email.email'              => 'El email debe ser una dirección válida.',
            'email.unique'             => 'Este email ya está registrado.',
            'rol_id.exists'            => 'El rol no es válido.',
            'tienda_default_id.exists' => 'La tienda seleccionada no existe.',
        ]);

        $arquetipoFinal = isset($data['rol_id'])
            ? Rol::findOrFail($data['rol_id'])->arquetipo
            : $usuario->rolAsignado?->arquetipo;

        if ($request->has('notif_asignar_fecha')) {
            $data['notif_asignar_fecha'] = ($arquetipoFinal === 'supervisor') && $request->boolean('notif_asignar_fecha');
        }
        if ($request->has('notif_stock')) {
            $data['notif_stock'] = ($arquetipoFinal === 'supervisor') && $request->boolean('notif_stock');
        }
        if ($request->has('facturacion')) {
            $data['facturacion'] = ($arquetipoFinal === 'vendedor') && $request->boolean('facturacion');
        }
        if ($request->has('acceso_redes')) {
            $data['acceso_redes'] = in_array($arquetipoFinal, ['vendedor', 'supervisor']) && $request->boolean('acceso_redes');
        }
        if ($request->has('acceso_comisiones')) {
            $data['acceso_comisiones'] = ($arquetipoFinal === 'supervisor') && $request->boolean('acceso_comisiones');
        }
        if ($request->has('recarga_telas')) {
            $data['recarga_telas'] = in_array($arquetipoFinal, ['vendedor', 'supervisor']) && $request->boolean('recarga_telas');
        }
        // Sin restricción de rol: es lo que se pidió, poder asignárselo a
        // cualquiera.
        if ($request->has('acceso_surtir')) {
            $data['acceso_surtir'] = $request->boolean('acceso_surtir');
        }
        if ($request->has('acceso_costos')) {
            $data['acceso_costos'] = $request->boolean('acceso_costos');
        }
        if ($request->has('acceso_proveedores')) {
            $data['acceso_proveedores'] = $request->boolean('acceso_proveedores');
        }
        if ($request->has('acceso_despacho')) {
            $data['acceso_despacho'] = $request->boolean('acceso_despacho');
        }
        if ($request->has('acceso_produccion')) {
            $data['acceso_produccion'] = $request->boolean('acceso_produccion');
        }
        if ($request->has('acceso_reserva')) {
            $data['acceso_reserva'] = $request->boolean('acceso_reserva');
        }
        if ($request->has('acceso_nomina')) {
            $data['acceso_nomina'] = $request->boolean('acceso_nomina');
        }
        if ($request->has('acceso_compras')) {
            $data['acceso_compras'] = $request->boolean('acceso_compras');
        }
        // Se respeta lo que ya es: quien no usa el programa nunca puede ser
        // apto para comisiones, porque no hace ventas.
        if ($request->has('apto_comisiones')) {
            $data['apto_comisiones'] = ! $usuario->no_usa_programa && $request->boolean('apto_comisiones');
        }
        if ($request->has('ve_todas_ordenes')) {
            $data['ve_todas_ordenes'] = $request->boolean('ve_todas_ordenes');
        }

        if ($request->has('independiente')) {
            $data['independiente'] = ($arquetipoFinal === 'vendedor') && $request->boolean('independiente');
        }

        // Al pasar a un arquetipo que nunca elige tienda (taller/despachador/
        // conductor), se limpia la que tuviera. Supervisor y vendedor sí
        // pueden conservarla (para supervisor es opcional, no forzada).
        if (isset($data['rol_id']) && in_array($arquetipoFinal, ['taller', 'despachador', 'conductor'])) {
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
        $usuario->load(['tiendaDefault:id,nombre,ciudad', 'rolAsignado']);

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
