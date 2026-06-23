<?php

namespace App\Http\Controllers;

use App\Models\Tienda;

class TiendaController extends Controller
{
    public function index()
    {
        return response()->json(Tienda::where('activa', true)->where('es_fabrica', false)->get());
    }
}
