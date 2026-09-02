<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Que cada tienda diga si sus comisiones son del equipo o de cada quien.
 *
 * Hasta ahora eso se deducía de tener meta: si la tienda tenía meta se
 * repartía el pool, y desde el cambio de las restauraciones también se
 * repartía el 5% de una restauración. Son dos cosas distintas metidas en una
 * sola: la meta es una cifra de ventas, y compartir es un acuerdo con la
 * gente.
 *
 * Se notó con Tienda Virtual: tiene cuatro personas registradas como equipo y
 * no tiene meta, así que había que adivinar qué hacer con una restauración de
 * allá. Ahora se dice, no se adivina.
 *
 * Encendido  -> el pool se parte entre el equipo, las restauraciones también,
 *               y quien no vendió cobra su parte igual.
 * Apagado    -> cada uno cobra el 5% de lo suyo y las restauraciones enteras,
 *               tenga la tienda meta o no.
 *
 * Arranca encendido en las que hoy comparten —las que tienen meta— para que
 * nada cambie el día que esto entre.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La columna puede venir ya puesta: se creó a mano probando esto
        // contra la base de producción —un ALTER TABLE en MySQL se confirma
        // solo y no lo deshace ninguna transacción—. Se comprueba para que el
        // despliegue no se caiga al encontrarla, y para que la migración quede
        // registrada igual.
        if (! Schema::hasColumn('tiendas', 'comisiones_compartidas')) {
            Schema::table('tiendas', function (Blueprint $t) {
                $t->boolean('comisiones_compartidas')->default(false)->after('activa');
            });
        }

        // Las que ya comparten: las que tienen alguna meta puesta. Se mira el
        // histórico entero y no el mes en curso, para no dejar fuera una que
        // esté entre metas.
        $conMeta = DB::table('metas_tienda')->where('meta', '>', 0)
            ->distinct()->pluck('tienda_id');

        if ($conMeta->isNotEmpty()) {
            DB::table('tiendas')->whereIn('id', $conMeta)
                ->update(['comisiones_compartidas' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('tiendas', function (Blueprint $t) {
            $t->dropColumn('comisiones_compartidas');
        });
    }
};
