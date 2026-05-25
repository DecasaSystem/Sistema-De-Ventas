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
            'tienda_default_id' => $usuario->tienda_default_id,
            'firma_url'         => $usuario->firma_url,
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
}
