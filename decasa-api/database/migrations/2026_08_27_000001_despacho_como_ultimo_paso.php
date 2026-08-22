<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El despacho es el último paso del taller, no un módulo aparte.
 *
 * Estaba montado como una pantalla propia: cuando se terminaban los pasos, la
 * pieza salía del flujo normal y aparecía en "Despacho de producción", una
 * bandeja separada con sus propios botones. El resultado es que al ponerle ese
 * trabajo a alguien se le llenaba el menú de un módulo entero para una sola
 * cosa, en vez de verlo en "Mis pasos" junto a lo demás, que es donde uno lo
 * busca.
 *
 * Ahora "Despacho" es un proceso más del catálogo, y se le engancha a cada
 * producción como el último paso. Con eso hereda todo lo que ya funciona:
 * aparece en "Mis pasos", se le asignan trabajadores, se registran horas y
 * calidad, y se puede devolver a un paso anterior si algo llegó mal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `tipo_proceso` era un ENUM con la lista escrita a mano, aunque los
        // procesos se crean desde el programa: cualquier proceso nuevo reventaba
        // al guardarse un paso con él. Pasa a texto, que es lo que corresponde
        // cuando el catálogo lo mantiene el usuario.
        DB::statement('ALTER TABLE produccion_pasos MODIFY tipo_proceso VARCHAR(60) NOT NULL');

        $despacho = DB::table('tipos_proceso')->where('clave', 'despacho')->first();

        if (! $despacho) {
            // Va de último en el catálogo de procesos.
            DB::table('tipos_proceso')->insert([
                'clave'       => 'despacho',
                'nombre'      => 'Despacho',
                'descripcion' => 'Revisión final y salida del taller',
                'color'       => 'purple',
                'orden'       => 9000,
                'activo'      => true,
            ]);
            $despacho = DB::table('tipos_proceso')->where('clave', 'despacho')->first();
        }

        // Lo hace quien ya tenía el permiso de despacho. De aquí en adelante se
        // cambia como cualquier otro paso, desde Producción → Procesos.
        $encargados = DB::table('usuarios')
            ->where('acceso_despacho', true)->where('activo', true)->pluck('id');

        foreach ($encargados as $uid) {
            DB::table('proceso_trabajadores')->insertOrIgnore([
                'usuario_id' => $uid, 'tipo_proceso_id' => $despacho->id,
            ]);
        }

        // ── Las producciones que ya existen se enganchan al paso nuevo ────────
        // Sin esto, lo que hoy está esperando despacho se quedaría sin pantalla
        // donde aparecer: la vieja se va y la nueva no lo incluiría.
        $conPasos = DB::table('produccion_pasos')
            ->select('produccion_id')
            ->distinct()
            ->pluck('produccion_id');

        foreach ($conPasos as $prodId) {
            $yaTiene = DB::table('produccion_pasos')
                ->where('produccion_id', $prodId)->where('tipo_proceso', 'despacho')->exists();
            if ($yaTiene) continue;

            $estadoProd = DB::table('produccion')->where('id', $prodId)->value('estado');
            $ultimoOrden = (int) DB::table('produccion_pasos')
                ->where('produccion_id', $prodId)->max('orden');

            // El estado del paso nuevo sale de dónde venía la pieza:
            //  - esperando despacho -> el paso arranca activo, y se ve ya mismo
            //  - ya despachada      -> el paso nace cerrado, no se pide dos veces
            //  - todavía en taller  -> pendiente, le llegará a su turno
            $estadoPaso = match ($estadoProd) {
                'pendiente_despachador'  => 'en_proceso',
                'listo', 'entregado'     => 'completado',
                default                  => 'pendiente',
            };

            DB::table('produccion_pasos')->insert([
                'produccion_id'  => $prodId,
                'tipo_proceso'   => 'despacho',
                'orden'          => $ultimoOrden + 1,
                'estado'         => $estadoPaso,
                'iniciado_at'    => $estadoPaso === 'en_proceso' ? now() : null,
                // Quien despachó ya quedaba anotado en la producción; se
                // arrastra para que el histórico no aparezca sin responsable.
                'completado_por' => $estadoPaso === 'completado'
                    ? DB::table('produccion')->where('id', $prodId)->value('despachado_por')
                    : null,
                'completado_at'  => $estadoPaso === 'completado'
                    ? DB::table('produccion')->where('id', $prodId)->value('fecha_real')
                    : null,
                'created_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('produccion_pasos')->where('tipo_proceso', 'despacho')->delete();
        DB::table('tipos_proceso')->where('clave', 'despacho')->delete();
    }
};
