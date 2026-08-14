<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use Illuminate\Http\Request;

/**
 * La libreta de proveedores: quién es, cómo se le contacta y qué provee.
 *
 * Es de todos. Cualquiera que haya iniciado sesión la lee y le puede sumar
 * uno nuevo o corregir un dato — no hay lógica de negocio ni permisos finos
 * de por medio, es una libreta compartida. Borrar sí queda para el
 * supervisor: es lo único que no se puede deshacer solo.
 */
class ProveedorController extends Controller
{
    /** GET /api/proveedores */
    public function index(Request $request)
    {
        $q = Proveedor::query()->orderBy('nombre');
        if (! $request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }

        return response()->json($q->get());
    }

    /** POST /api/proveedores */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'           => 'required|string|max:120',
            'contacto'         => 'nullable|string|max:120',
            'telefono'         => 'nullable|string|max:30',
            'usuario_whatsapp' => 'nullable|string|max:60',
            'correo'           => 'nullable|email|max:120',
            'productos'        => 'nullable|string|max:500',
            'direccion'        => 'nullable|string|max:200',
            'notas'            => 'nullable|string|max:500',
        ]);

        $proveedor = Proveedor::create($data + ['activo' => true]);

        // create() solo trae en memoria lo que se mandó en la petición: un
        // proveedor sin teléfono respondía sin la clave "telefono" siquiera,
        // a diferencia de update() que sí recarga la fila completa. fresh()
        // deja las dos respuestas con la misma forma.
        return response()->json($proveedor->fresh(), 201);
    }

    /** PATCH /api/proveedores/{id} */
    public function update(Request $request, int $id)
    {
        $proveedor = Proveedor::findOrFail($id);

        $data = $request->validate([
            'nombre'           => 'sometimes|required|string|max:120',
            'contacto'         => 'sometimes|nullable|string|max:120',
            'telefono'         => 'sometimes|nullable|string|max:30',
            'usuario_whatsapp' => 'sometimes|nullable|string|max:60',
            'correo'           => 'sometimes|nullable|email|max:120',
            'productos'        => 'sometimes|nullable|string|max:500',
            'direccion'        => 'sometimes|nullable|string|max:200',
            'notas'            => 'sometimes|nullable|string|max:500',
            'activo'           => 'sometimes|boolean',
        ]);

        $proveedor->update($data);

        return response()->json($proveedor->fresh());
    }

    /** DELETE /api/proveedores/{id}  (solo supervisor, vía middleware de la ruta) */
    public function destroy(int $id)
    {
        Proveedor::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}
