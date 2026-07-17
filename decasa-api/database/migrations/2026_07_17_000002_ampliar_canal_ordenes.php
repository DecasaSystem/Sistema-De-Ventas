<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // La columna era ENUM('fisica','whatsapp','red_social','otro'), pero el
        // formulario y la validación del backend ya ofrecen instagram/facebook/
        // pagina desde hace tiempo -- MySQL rechazaba el guardado con "Data
        // truncated for column 'canal'" al elegir cualquiera de esos 3.
        // Se cambia a VARCHAR para no repetir este problema si se agregan
        // canales nuevos (igual que la columna 'tipo').
        DB::statement("ALTER TABLE ordenes MODIFY COLUMN canal VARCHAR(20) NULL");
    }

    public function down(): void
    {
        // Solo revierte si no hay filas con valores fuera del enum viejo.
        $fueraDeRango = DB::table('ordenes')
            ->whereNotIn('canal', ['fisica', 'whatsapp', 'red_social', 'otro'])
            ->whereNotNull('canal')
            ->exists();

        if (! $fueraDeRango) {
            DB::statement("ALTER TABLE ordenes MODIFY COLUMN canal ENUM('fisica','whatsapp','red_social','otro') NULL");
        }
    }
};
