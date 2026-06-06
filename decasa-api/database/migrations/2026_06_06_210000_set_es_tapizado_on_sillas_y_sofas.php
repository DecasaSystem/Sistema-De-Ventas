<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sillas (de comedor, de barra, auxiliares, etc.) y sofás/modulares
        DB::table('productos')
            ->where(function ($q) {
                $q->where('categoria', 'like', '%Silla%')
                  ->orWhere('categoria', 'like', '%silla%')
                  ->orWhere('categoria', 'like', '%Sofá%')
                  ->orWhere('categoria', 'like', '%Sofa%')
                  ->orWhere('categoria', 'like', '%sofá%')
                  ->orWhere('categoria', 'like', '%sofa%')
                  ->orWhere('categoria', 'like', '%Modular%')
                  ->orWhere('categoria', 'like', '%modular%');
            })
            ->update(['es_tapizado' => true]);
    }

    public function down(): void
    {
        // No revertimos — el usuario puede quitar el tapizado manualmente
    }
};
