<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Quién puede entrar, se entre como se entre.
     *
     * El trabajador de fábrica se guarda con email y password en null: los
     * whereNotNull dejan dicho que no entra, en vez de depender de que nadie
     * mande null. Vive aparte porque el candado tiene que ser el mismo para la
     * contraseña y para Google — si no, una puerta acaba siendo más ancha que
     * la otra sin que nadie se dé cuenta.
     */
    private function usuarioQuePuedeEntrar(string $email): ?Usuario
    {
        return Usuario::where('email', $email)
            ->whereNotNull('email')
            ->whereNotNull('password')
            ->where('no_usa_programa', false)
            ->where('activo', true)
            ->first();
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $usuario = $this->usuarioQuePuedeEntrar($request->email);

        if (! $usuario || ! Hash::check($request->password, $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        return response()->json($this->sesionIniciada($usuario));
    }

    /**
     * POST /api/auth/google
     *
     * Entrar con Google. Llega el ID token que devuelve el botón de Google y
     * se comprueba contra Google mismo: así la app no tiene que guardar ningún
     * secreto ni fiarse de lo que diga el navegador.
     *
     * NO crea cuentas. Aquí adentro hay caja, nómina y costos: se entra sólo
     * si ese correo ya es un usuario del sistema, con los mismos candados que
     * la contraseña. Tener una cuenta de Google no le abre la puerta a nadie.
     */
    public function loginGoogle(Request $request)
    {
        $request->validate(['credential' => 'required|string']);

        $clientId = config('services.google.client_id');
        if (! $clientId) {
            return response()->json([
                'message' => 'Entrar con Google no está configurado en este servidor.',
            ], 503);
        }

        $datos = $this->verificarTokenGoogle($request->input('credential'), $clientId);
        if (! $datos) {
            return response()->json([
                'message' => 'No se pudo verificar tu cuenta de Google. Intenta de nuevo.',
            ], 401);
        }

        $usuario = $this->usuarioQuePuedeEntrar($datos['email']);
        if (! $usuario) {
            return response()->json([
                'message' => "{$datos['email']} no tiene cuenta en el programa. Pídele al administrador que te la cree o entra con tu contraseña.",
            ], 403);
        }

        // La cuenta de Google queda anotada la primera vez. Si después llega
        // otra distinta con el mismo correo, no entra: un correo que cambió de
        // dueño no puede heredar la sesión del anterior.
        if ($usuario->google_id && $usuario->google_id !== $datos['sub']) {
            return response()->json([
                'message' => 'Este correo ya está enlazado a otra cuenta de Google. Entra con tu contraseña.',
            ], 403);
        }
        if (! $usuario->google_id) {
            $usuario->update(['google_id' => $datos['sub']]);
        }

        return response()->json($this->sesionIniciada($usuario));
    }

    /**
     * Comprueba el ID token con Google y devuelve de quién es.
     *
     * Se le pregunta a Google en vez de validar la firma aquí para no sumar
     * una dependencia entera por un puñado de entradas al día: Google revisa
     * firma y vencimiento, y de este lado queda comprobar que el token sea
     * para ESTA aplicación y que el correo esté verificado. Sin lo primero,
     * cualquiera podría entrar con un token sacado de otra app.
     */
    private function verificarTokenGoogle(string $credential, string $clientId): ?array
    {
        try {
            $res = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $credential,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $datos = $res->json();
        $verificado = $datos['email_verified'] ?? false;

        if (($datos['aud'] ?? null) !== $clientId
            || empty($datos['email'])
            || empty($datos['sub'])
            || ! in_array($verificado, [true, 'true'], true)) {
            return null;
        }

        return ['email' => $datos['email'], 'sub' => $datos['sub']];
    }

    /** Sesión nueva: el token del aparato más quién es y qué puede hacer. */
    private function sesionIniciada(Usuario $usuario): array
    {
        return array_merge(
            ['token' => $usuario->createToken('decasa-token')->plainTextToken],
            $this->datosDeSesion($usuario)
        );
    }

    /**
     * Quién es y qué puede hacer. Lo usan por igual el login con contraseña,
     * el de Google y /auth/me: si cada uno armara su propia lista, entrar por
     * una puerta u otra acabaría dando permisos distintos.
     */
    private function datosDeSesion(Usuario $usuario): array
    {
        return [
            'id'                => $usuario->id,
            'nombre'            => $usuario->nombre,
            'rol'               => $usuario->rol,
            'rol_id'            => $usuario->rol_id,
            'rol_nombre'        => $usuario->rolAsignado?->nombre,
            // Sin esto la pantalla no sabe que alguien es independiente: se
            // quedaba fuera del payload y todo lo que depende de ello —su
            // caja propia, compartir la venta con un almacen— no aparecia.
            'independiente'     => (bool) $usuario->independiente,
            'facturacion'       => (bool) $usuario->facturacion,
            'acceso_redes'       => (bool) $usuario->acceso_redes,
            'acceso_comisiones'  => (bool) $usuario->acceso_comisiones,
            'recarga_telas'      => (bool) $usuario->recarga_telas,
            'acceso_telas'       => (bool) $usuario->acceso_telas,
            'acceso_surtir'      => (bool) $usuario->acceso_surtir,
            'acceso_costos'      => (bool) $usuario->acceso_costos,
            'acceso_proveedores' => (bool) $usuario->acceso_proveedores,
            'acceso_despacho'    => (bool) $usuario->acceso_despacho,
            'gestiona_produccion'  => (bool) $usuario->gestiona_produccion,
            'acceso_produccion'  => (bool) $usuario->acceso_produccion,
            'acceso_reserva'     => (bool) $usuario->acceso_reserva,
            'acceso_nomina'      => (bool) $usuario->acceso_nomina,
            'acceso_compras'     => (bool) $usuario->acceso_compras,
            // Encargos: administrar el módulo. `lleva_encargos` viaja también
            // porque con esa sola —sin el permiso— la persona ya puede abrir
            // su propia ficha y ver de qué responde.
            'acceso_encargos'    => (bool) $usuario->acceso_encargos,
            'revisa_encargos'    => (bool) $usuario->revisa_encargos,
            'lleva_encargos'     => (bool) $usuario->lleva_encargos,
            've_todas_ordenes'   => (bool) $usuario->ve_todas_ordenes,
            // Si lleva algún paso del taller. Es lo único que decide si ve
            // "Mis pasos": ya no depende de qué rol tenga la persona.
            'tiene_pasos_produccion' => count($usuario->procesosQuePuedeTrabajar()) > 0,
            // Ver el taller: por permiso, o por llevar algún paso.
            've_produccion'      => $usuario->veProduccion(),
            'tienda_default_id'  => $usuario->tienda_default_id,
            // Con qué cuenta alterna. Viaja el QUIÉN, nunca su sesión: el otro
            // aparato tiene que escribir la contraseña igual.
            'perfil_alterno'     => $usuario->perfilAlterno
                ? ['id' => $usuario->perfilAlterno->id,
                   'nombre' => $usuario->perfilAlterno->nombre,
                   'email' => $usuario->perfilAlterno->email]
                : null,
            'firma_url'          => $usuario->firma_url,
        ];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request)
    {
        $usuario = $request->user()->load(['tiendaDefault:id,nombre,ciudad', 'rolAsignado', 'perfilAlterno:id,nombre,email']);

        // Lo mismo que se manda al entrar, más lo que sólo hace falta cuando ya
        // se está adentro: con qué correo y en qué tienda.
        return response()->json(array_merge($this->datosDeSesion($usuario), [
            'email'          => $usuario->email,
            'tienda_default' => $usuario->tiendaDefault,
        ]));
    }

    /**
     * PATCH /api/auth/mi-perfil-alterno
     * Deja anotado con qué cuenta alterna, para que el ajuste no se quede en
     * este aparato. Mandar null lo quita.
     */
    public function guardarPerfilAlterno(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => 'nullable|integer|exists:usuarios,id',
        ]);

        $otro = $data['usuario_id'] ?? null;
        if ($otro && (int) $otro === (int) $request->user()->id) {
            return response()->json(['message' => 'No puedes alternar contigo mismo.'], 422);
        }

        $request->user()->update(['perfil_alterno_id' => $otro]);

        return response()->json(['ok' => true]);
    }

    public function guardarFirma(Request $request)
    {
        $data = $request->validate(['firma_url' => ['required', 'string', 'max:500', 'url', 'regex:/^https:\/\//i']]);
        $request->user()->update(['firma_url' => $data['firma_url']]);
        return response()->json(['firma_url' => $data['firma_url']]);
    }

    public function actualizarCuenta(Request $request)
    {
        $usuario = $request->user();

        $data = $request->validate([
            'password_actual'          => 'required|string',
            'email'                    => 'nullable|email|max:200|unique:usuarios,email,' . $usuario->id,
            'password_nuevo'           => 'nullable|string|min:8|confirmed',
            'password_nuevo_confirmation' => 'nullable|string',
        ]);

        if (! Hash::check($data['password_actual'], $usuario->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta.',
                'errors'  => ['password_actual' => ['Contraseña incorrecta.']],
            ], 422);
        }

        $updates = [];
        if (! empty($data['email'])) {
            $updates['email'] = $data['email'];
        }
        if (! empty($data['password_nuevo'])) {
            $updates['password'] = Hash::make($data['password_nuevo']);
        }

        if (empty($updates)) {
            return response()->json(['message' => 'No ingresaste ningún cambio.'], 422);
        }

        $usuario->update($updates);

        return response()->json([
            'message' => 'Cuenta actualizada correctamente.',
            'email'   => $usuario->email,
        ]);
    }
}
