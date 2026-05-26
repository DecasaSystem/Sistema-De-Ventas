<?php

namespace App\Http\Controllers;

use App\Services\AgentService;
use Illuminate\Http\Request;

class PrecioItemController extends Controller
{
    public function __construct(private AgentService $agent) {}

    public function calcular(Request $request)
    {
        $data = $request->validate([
            'producto_id'  => 'nullable|integer|exists:productos,id',
            'nombre'       => 'required|string|max:200',
            'categoria'    => 'nullable|string|max:100',
            'descripcion'  => 'nullable|string|max:2000',
            'precio_base'  => 'nullable|numeric|min:0',
            'largo_cm'     => 'nullable|numeric|min:1|max:2000',
            'ancho_cm'     => 'nullable|numeric|min:1|max:2000',
            'alto_cm'      => 'nullable|numeric|min:1|max:2000',
            'num_puestos'  => 'nullable|integer|min:1|max:20',
            'boceto_url'   => 'nullable|string|max:500',
        ]);

        $resultado = $this->agent->calcularPrecioItem($data, $request->user());

        return response()->json($resultado);
    }
}
