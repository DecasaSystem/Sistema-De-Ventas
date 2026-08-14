<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Algunos proveedores solo se ubican por su usuario de WhatsApp, no por
 * número de teléfono, y otros prefieren el correo. Los dos son opcionales,
 * igual que el teléfono: se guarda lo que haya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('usuario_whatsapp')->nullable()->after('telefono');
            $table->string('correo')->nullable()->after('usuario_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn(['usuario_whatsapp', 'correo']);
        });
    }
};
