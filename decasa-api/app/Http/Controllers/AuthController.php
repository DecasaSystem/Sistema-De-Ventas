<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $usuario = Usuario::where('email', $request->email)
                          ->where('activo', true)
                          ->first();

        if (! $usuario || ! Hash::check($request->password, $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        $token = $usuario->createToken('decasa-token')->plainTextToken;

        return response()->json([
            'token'             => $token,
            'id'                => $usuario->id,
            'nombre'            => $usuario->nombre,
            'rol'               => $usuario->rol,
            'es_tapicero'       => (bool) $usuario->es_tapicero,
            'facturacion'       => (bool) $usuario->facturacion,
            'acceso_redes'       => (bool) $usuario->acceso_redes,
            'acceso_comisiones'  => (bool) $usuario->acceso_comisiones,
            'recarga_telas'      => (bool) $usuario->recarga_telas,
            'tienda_default_id'  => $usuario->tienda_default_id,
            'firma_url'          => $usuario->firma_url,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request)
    {
        $usuario = $request->user()->load('tiendaDefault:id,nombre,ciudad');

        return response()->json([
            'id'                => $usuario->id,
            'nombre'            => $usuario->nombre,
            'email'             => $usuario->email,
            'rol'               => $usuario->rol,
            'es_tapicero'       => (bool) $usuario->es_tapicero,
            'facturacion'       => (bool) $usuario->facturacion,
            'acceso_redes'      => (bool) $usuario->acceso_redes,
            'acceso_comisiones' => (bool) $usuario->acceso_comisiones,
            'recarga_telas'     => (bool) $usuario->recarga_telas,
            'tienda_default_id' => $usuario->tienda_default_id,
            'tienda_default'    => $usuario->tiendaDefault,
            'firma_url'         => $usuario->firma_url,
        ]);
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
