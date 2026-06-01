<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sofás, sofacamas, modulares y sillas son tapizados por defecto.
        // Se puede desactivar individualmente desde el panel Gestionar.
        DB::table('productos')
            ->where(function ($q) {
                $q->where('categoria', 'like', '%sofa%')
                  ->orWhere('categoria', 'like', '%sofá%')
                  ->orWhere('categoria', 'like', '%silla%')
                  ->orWhere('categoria', 'like', '%modular%')
                  ->orWhere('nombre',    'like', '%sofa%')
                  ->orWhere('nombre',    'like', '%sofá%')
                  ->orWhere('nombre',    'like', '%silla%')
                  ->orWhere('nombre',    'like', '%modular%');
            })
            ->update(['es_tapizado' => true]);
    }

    public function down(): void
    {
        // No revertible de forma segura sin conocer el estado previo.
    }
};
