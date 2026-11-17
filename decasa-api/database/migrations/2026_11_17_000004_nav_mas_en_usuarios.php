<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué módulos van en el menú "Más" de la barra de abajo, en su orden
 * (usuario, 2026-11-17: que también se puedan quitar, para dejarlo más vacío,
 * o cambiar por otros). Null = lo de siempre (lo que no cupo en la barra).
 * Va aparte de nav_favoritos (la barra) para no cambiarle la forma a esa
 * columna que leen las apps ya abiertas. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'nav_mas')) return;

        Schema::table('usuarios', function (Blueprint $table) {
            $table->json('nav_mas')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('usuarios', 'nav_mas')) {
            Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('nav_mas'));
        }
    }
};
