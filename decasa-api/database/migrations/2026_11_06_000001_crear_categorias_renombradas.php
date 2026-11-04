<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renombrar y juntar categorías de inventario (2026-10-29, pedido del usuario:
 * "¿qué pasa si me equivoco? No puedo editar los nombres de las categorías").
 *
 * La categoría sigue siendo el texto de `productos.categoria`: renombrar es
 * cambiar ese texto en todos los productos de una vez. Esta tabla solo guarda
 * de qué nombre a qué nombre se pasó, para dos cosas:
 *  - Los links de sección ya mandados a clientes (`/catalogo/<categoría>`) salen
 *    del nombre. Con el nombre viejo apuntando al nuevo siguen abriendo.
 *  - Los agentes de chat (Elena) conocen las categorías por su nombre exacto
 *    (`negocio.json → mapaCategoriasBD`). Con esto siguen reconociendo la
 *    categoría renombrada sin que alguien tenga que tocar su configuración.
 *
 * Solo crea la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categorias_renombradas')) {
            Schema::create('categorias_renombradas', function (Blueprint $t) {
                $t->id();
                $t->string('nombre_viejo', 80);
                // El nombre viejo en minúsculas, sin tildes ni separadores: lo
                // que se compara con la dirección del link.
                $t->string('clave_vieja', 100)->index();
                $t->string('nombre_nuevo', 80);
                $t->unsignedInteger('productos')->default(0);
                $t->boolean('fue_juntar')->default(false);
                $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_renombradas');
    }
};
