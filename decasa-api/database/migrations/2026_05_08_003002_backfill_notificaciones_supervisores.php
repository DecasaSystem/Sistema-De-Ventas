<?php

use App\Models\Notificacion;
use App\Models\Usuario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $globales = Notificacion::whereNull('usuario_id')->get();
        $supervisores = Usuario::where('rol', 'supervisor')
            ->where('activo', true)
            ->pluck('id');

        foreach ($globales as $n) {
            foreach ($supervisores as $supId) {
                $existe = Notificacion::where('usuario_id', $supId)
                    ->where('tipo', $n->tipo)
                    ->where('created_at', $n->created_at)
                    ->exists();

                if (! $existe) {
                    Notificacion::create([
                        'usuario_id' => $supId,
                        'tipo'       => $n->tipo,
                        'titulo'     => $n->titulo,
                        'mensaje'    => $n->mensaje,
                        'datos'      => $n->datos,
                        'leida'      => false,
                        'created_at' => $n->created_at,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // No se puede revertir fácilmente
    }
};
