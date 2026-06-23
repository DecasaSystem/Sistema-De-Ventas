<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Tienda que estaba mal marcada como fábrica
            $viejaId = DB::table('tiendas')
                ->where('es_fabrica', true)
                ->value('id');

            if (! $viejaId) return; // ya corregido

            // Crear la Bodega Fábrica como entidad interna (no es una tienda real)
            $nuevaId = DB::table('tiendas')->insertGetId([
                'nombre'     => 'Bodega Fábrica',
                'ciudad'     => null,
                'direccion'  => null,
                'telefono'   => null,
                'activa'     => true,
                'es_fabrica' => true,
                'created_at' => now(),
            ]);

            // Migrar todo el inventario de la bodega al nuevo ID
            DB::table('inventario')
                ->where('tienda_id', $viejaId)
                ->update(['tienda_id' => $nuevaId]);

            DB::table('inventario_variantes')
                ->where('tienda_id', $viejaId)
                ->update(['tienda_id' => $nuevaId]);

            DB::table('inventario_variante_combinaciones')
                ->where('tienda_id', $viejaId)
                ->update(['tienda_id' => $nuevaId]);

            DB::table('inventario_movimientos')
                ->where('tienda_id', $viejaId)
                ->update(['tienda_id' => $nuevaId]);

            // Items de órdenes sourced desde la bodega
            DB::table('orden_items')
                ->where('tienda_origen_id', $viejaId)
                ->update(['tienda_origen_id' => $nuevaId]);

            // Decasa Bolívar vuelve a ser una tienda normal
            DB::table('tiendas')
                ->where('id', $viejaId)
                ->update(['es_fabrica' => false]);
        });
    }

    public function down(): void
    {
        // Revertir: mover inventario de vuelta y eliminar Bodega Fábrica
        DB::transaction(function () {
            $bodegaId = DB::table('tiendas')->where('nombre', 'Bodega Fábrica')->value('id');
            $bolivarId = DB::table('tiendas')->where('nombre', 'Decasa Bolívar')->value('id');

            if (! $bodegaId || ! $bolivarId) return;

            DB::table('inventario')->where('tienda_id', $bodegaId)->update(['tienda_id' => $bolivarId]);
            DB::table('inventario_variantes')->where('tienda_id', $bodegaId)->update(['tienda_id' => $bolivarId]);
            DB::table('inventario_variante_combinaciones')->where('tienda_id', $bodegaId)->update(['tienda_id' => $bolivarId]);
            DB::table('inventario_movimientos')->where('tienda_id', $bodegaId)->update(['tienda_id' => $bolivarId]);
            DB::table('orden_items')->where('tienda_origen_id', $bodegaId)->update(['tienda_origen_id' => $bolivarId]);

            DB::table('tiendas')->where('id', $bolivarId)->update(['es_fabrica' => true]);
            DB::table('tiendas')->where('id', $bodegaId)->delete();
        });
    }
};
