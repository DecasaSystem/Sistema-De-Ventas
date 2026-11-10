<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación electrónica con Alegra (docs/plan-facturacion-alegra.md).
 *
 * Solo agrega: una tabla nueva y columnas opcionales en `clientes` (los datos
 * que la DIAN pide y el sistema no guardaba). Nada que lea el código viejo
 * cambia, y `clientes` no está en el contrato de los agentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facturas_electronicas')) {
            Schema::create('facturas_electronicas', function (Blueprint $t) {
                $t->id();
                // "orden:123" o "pago:456". Es lo que impide facturar dos veces lo
                // mismo: único y nunca NULL (en MySQL dos NULL no chocan en un
                // índice único; ver la trampa de wa_seguimientos en MEMORY.md).
                $t->string('clave', 40)->unique();
                $t->foreignId('orden_id')->constrained('ordenes');
                $t->unsignedBigInteger('pago_id')->nullable()->index();
                $t->string('tipo', 20)->default('factura');
                // enviando | emitida | pendiente_dian | borrador_alegra | rechazada
                // | error | incierta | externa
                $t->string('estado', 20)->index();
                $t->string('modo', 10)->default('emitir');
                $t->string('alegra_id', 40)->nullable()->index();
                $t->string('alegra_contacto_id', 40)->nullable();
                $t->string('numero', 40)->nullable();
                $t->string('cufe', 120)->nullable();
                $t->string('estado_dian', 50)->nullable();
                $t->string('pdf_url', 500)->nullable();
                $t->decimal('base', 14, 2)->default(0);
                $t->decimal('iva', 14, 2)->default(0);
                $t->decimal('total', 14, 2)->default(0);
                // Lo que valía la orden (o el pago) al facturar: si después cambia la
                // plata, la diferencia se ve contra esto.
                $t->decimal('valor_sistema', 14, 2)->default(0);
                $t->json('envio')->nullable();
                $t->json('respuesta')->nullable();
                $t->text('error')->nullable();
                // Las advertencias que tenía al facturar y que la persona aceptó.
                $t->json('advertencias_aceptadas')->nullable();
                $t->text('nota')->nullable();
                $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamp('emitida_at')->nullable();
                $t->timestamp('diferencia_resuelta_at')->nullable();
                $t->foreignId('diferencia_resuelta_por')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->text('diferencia_nota')->nullable();
                $t->timestamps();
            });
        }

        Schema::table('clientes', function (Blueprint $t) {
            // CC, NIT, CE, PP… (catálogo de Alegra para Colombia).
            if (! Schema::hasColumn('clientes', 'tipo_documento')) $t->string('tipo_documento', 12)->nullable();
            if (! Schema::hasColumn('clientes', 'dv'))             $t->string('dv', 2)->nullable();
            // natural | juridica
            if (! Schema::hasColumn('clientes', 'tipo_persona'))   $t->string('tipo_persona', 12)->nullable();
            // Régimen de IVA del cliente: no_responsable | responsable
            if (! Schema::hasColumn('clientes', 'regimen'))        $t->string('regimen', 20)->nullable();
            if (! Schema::hasColumn('clientes', 'ciudad'))         $t->string('ciudad', 80)->nullable();
            if (! Schema::hasColumn('clientes', 'departamento'))   $t->string('departamento', 80)->nullable();
            if (! Schema::hasColumn('clientes', 'alegra_contacto_id')) $t->string('alegra_contacto_id', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas_electronicas');
        Schema::table('clientes', function (Blueprint $t) {
            foreach (['tipo_documento', 'dv', 'tipo_persona', 'regimen', 'ciudad', 'departamento', 'alegra_contacto_id'] as $c) {
                if (Schema::hasColumn('clientes', $c)) $t->dropColumn($c);
            }
        });
    }
};
