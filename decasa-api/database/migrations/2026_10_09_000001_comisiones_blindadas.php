<?php

use App\Services\ComisionIndependientes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que salió de la auditoría de comisiones del 2 de octubre de 2026.
 *
 *  1. Que una tienda sea trimestral queda GUARDADO en la tienda
 *     (`comision_periodicidad`). Antes se reconocía por el nombre escrito en
 *     el código: renombrar "Decasa Circunvalar" la volvía mensual sin avisar.
 *
 *  2. `clave_unica`: el renglón de "no vendió" (parte_pool) sin pagar es uno
 *     solo por persona, tienda y mes, y lo garantiza la base. Se abría al
 *     mirar la pantalla sin candado: dos pestañas a la vez dejaban dos, y los
 *     dos se pagaban. Al pagarse se suelta la clave (la columna admite varios
 *     NULL), porque en el libro del pool un renglón pagado y uno nuevo por la
 *     diferencia sí pueden convivir.
 *
 *  3. `forma_pago_pagada`: por qué camino se pagó (pool, 5% directo, bolsón…).
 *     El libro del pool necesita saber qué fue pago del pool aunque después la
 *     orden se cancele: si no, lo olvidaba y lo volvía a pagar.
 *
 *  4. `comisiones_bitacora`: quién cambió una meta, un equipo, un reemplazo,
 *     el reparto de una tienda o marcó/deshizo un pago, y cuándo.
 *
 *  5. Borrar una orden ya no se lleva por delante sus comisiones pagadas: la
 *     llave pasa de "en cascada" a "no deja". La aplicación ya borraba antes
 *     las que no estaban pagadas y frenaba si había una pagada; ahora también
 *     lo frena la base.
 *
 *  6. Independientes pagados con la cuenta vieja: la pantalla mostraba (y se
 *     les pagaba) el bolsón de restauraciones, pero no quedaba registrado en
 *     ninguna parte. Se deja un renglón del bolsón por cada mes que ya se les
 *     pagó, para que la cuenta nueva —lo que se puede pagar menos lo pagado—
 *     no se lo vuelva a ofrecer.
 */
return new class extends Migration
{
    private const TRIMESTRALES = ['Decasa Unicentro Pereira', 'Decasa Circunvalar'];

    public function up(): void
    {
        if (! Schema::hasColumn('tiendas', 'comision_periodicidad')) {
            Schema::table('tiendas', function (Blueprint $t) {
                $t->string('comision_periodicidad', 12)->default('mensual')->after('comisiones_compartidas');
            });
        }
        DB::table('tiendas')->whereIn('nombre', self::TRIMESTRALES)
            ->update(['comision_periodicidad' => 'trimestral']);

        Schema::table('comisiones', function (Blueprint $t) {
            if (! Schema::hasColumn('comisiones', 'forma_pago_pagada')) {
                $t->string('forma_pago_pagada', 30)->nullable()->after('estado');
            }
            if (! Schema::hasColumn('comisiones', 'clave_unica')) {
                $t->string('clave_unica', 80)->nullable()->after('origen');
            }
        });

        // Antes de poner la llave única: los renglones repetidos que ya haya.
        // Se queda el más viejo sin pagar de cada persona y tienda.
        $repetidos = DB::table('comisiones')
            ->where('origen', 'parte_pool')->where('estado', '!=', 'pagada')
            ->orderBy('id')->get(['id', 'vendedor_id', 'tienda_id', 'mes_venta'])
            ->groupBy(fn ($c) => $c->vendedor_id . '_' . $c->tienda_id . '_' . $c->mes_venta);

        foreach ($repetidos as $grupo) {
            $primero = $grupo->shift();
            if ($grupo->isNotEmpty()) {
                DB::table('comisiones')->whereIn('id', $grupo->pluck('id'))->delete();
            }
            DB::table('comisiones')->where('id', $primero->id)->update([
                'clave_unica' => "parte_pool:{$primero->vendedor_id}:{$primero->tienda_id}:{$primero->mes_venta}",
            ]);
        }

        Schema::table('comisiones', function (Blueprint $t) {
            $t->unique('clave_unica');
        });

        Schema::table('comisiones', function (Blueprint $t) {
            $t->dropForeign(['orden_id']);
        });
        Schema::table('comisiones', function (Blueprint $t) {
            $t->foreign('orden_id')->references('id')->on('ordenes')->restrictOnDelete();
        });

        if (! Schema::hasTable('comisiones_bitacora')) {
            Schema::create('comisiones_bitacora', function (Blueprint $t) {
                $t->id();
                $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $t->string('accion', 40);
                $t->unsignedBigInteger('tienda_id')->nullable();
                $t->char('mes', 7)->nullable();
                $t->json('detalle')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['tienda_id', 'mes']);
                $t->index('created_at');
            });
        }

        $this->registrarBolsonesYaPagados();
    }

    private function registrarBolsonesYaPagados(): void
    {
        $sede = DB::table('tiendas')->where('es_independientes', true)->value('id');

        $pagados = DB::table('comisiones as c')
            ->join('usuarios as u', 'u.id', '=', 'c.vendedor_id')
            ->where('u.independiente', true)
            ->where('c.estado', 'pagada')
            ->whereNotNull('c.orden_id')
            ->select('c.vendedor_id', 'c.mes_venta', 'c.tienda_id', DB::raw('MAX(c.fecha_pago) as fecha_pago'),
                     DB::raw('MAX(c.pagada_por) as pagada_por'))
            ->groupBy('c.vendedor_id', 'c.mes_venta', 'c.tienda_id')
            ->get()
            ->unique(fn ($r) => $r->vendedor_id . '_' . $r->mes_venta);

        $porMes = [];
        foreach ($pagados as $r) {
            $porMes[$r->mes_venta] ??= ComisionIndependientes::delMes($r->mes_venta);
            // El bolsón es el mismo para cada independiente: el 5% de todo.
            $monto = (float) ($porMes[$r->mes_venta]['comision_restauraciones_lista'] ?? 0);
            if ($monto <= 0) continue;

            $finDeMes = \Carbon\Carbon::parse($r->mes_venta . '-01')->endOfMonth()->toDateString();
            DB::table('comisiones')->insert([
                'orden_id'          => null,
                'vendedor_id'       => $r->vendedor_id,
                'tienda_id'         => $sede ?? $r->tienda_id,
                'origen'            => 'bolson_indep',
                'mes_venta'         => $r->mes_venta,
                'valor_orden'       => 0,
                'fecha_venta'       => $finDeMes,
                'fecha_disponible'  => \Carbon\Carbon::parse($r->mes_venta . '-01')->addMonth()->day(20)->toDateString(),
                'estado'            => 'pagada',
                'forma_pago_pagada' => 'bolson_restauraciones',
                'monto_comision'    => round($monto),
                'fecha_pago'        => $r->fecha_pago,
                'pagada_por'        => $r->pagada_por,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('comisiones')->where('origen', 'bolson_indep')->delete();

        Schema::dropIfExists('comisiones_bitacora');

        Schema::table('comisiones', function (Blueprint $t) {
            $t->dropForeign(['orden_id']);
        });
        Schema::table('comisiones', function (Blueprint $t) {
            $t->foreign('orden_id')->references('id')->on('ordenes')->cascadeOnDelete();
            $t->dropUnique(['clave_unica']);
            $t->dropColumn(['clave_unica', 'forma_pago_pagada']);
        });

        Schema::table('tiendas', function (Blueprint $t) {
            $t->dropColumn('comision_periodicidad');
        });
    }
};
