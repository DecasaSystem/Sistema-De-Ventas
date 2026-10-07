<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca los pagos viejos que se cobraron en una entrega, para que su efectivo
 * salga de la caja de la tienda (ver la migración pagos_cobrados_en_entrega).
 *
 * Un cobro de entrega guarda la misma foto del comprobante en el pago y en la
 * entrega (`despacho_items.foto_pago`), así que se reconocen sin adivinar. Los
 * anteriores a junio de 2026, cuando el pago aún no guardaba la foto, no se
 * pueden reconocer y se quedan como están.
 *
 *   php artisan caja:cobros-de-entrega            → solo muestra qué cambiaría
 *   php artisan caja:cobros-de-entrega --aplicar  → los marca
 *
 * Ojo: el saldo de cada caja baja en el efectivo que se marque.
 */
class MarcarCobrosDeEntrega extends Command
{
    protected $signature   = 'caja:cobros-de-entrega {--aplicar : Marcar los pagos (sin esto solo muestra el resumen)}';
    protected $description = 'Marca los pagos viejos cobrados en una entrega para que no cuenten en la caja';

    public function handle(): int
    {
        $candidatos = DB::table('pagos as p')
            ->join('despacho_items as d', function ($j) {
                $j->on('d.orden_id', '=', 'p.orden_id')->on('d.foto_pago', '=', 'p.comprobante_url');
            })
            ->leftJoin('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->leftJoin('tiendas as t', 't.id', '=', DB::raw('COALESCE(p.tienda_id, o.tienda_id)'))
            ->whereNull('p.despacho_item_id')
            ->whereIn('p.tipo', ['abono', 'saldo_final'])
            ->whereNotNull('p.comprobante_url')
            ->select('p.id', 'p.monto', 'p.metodo', 'd.id as despacho_item_id', 't.nombre as tienda')
            ->get()
            // Una foto por entrega: si por algo coincidieran dos, se toma la primera
            ->unique('id');

        if ($candidatos->isEmpty()) {
            $this->info('No hay cobros de entrega sin marcar.');
            return self::SUCCESS;
        }

        $efectivo = $candidatos->where('metodo', 'efectivo');

        $this->info("Cobros de entrega reconocidos: {$candidatos->count()} ({$efectivo->count()} en efectivo).");
        $this->line('Lo que saldría de cada caja (solo el efectivo):');
        $this->table(
            ['Tienda', 'Pagos en efectivo', 'Total'],
            $efectivo->groupBy(fn ($p) => $p->tienda ?? 'Sin tienda')
                ->map(fn ($g, $tienda) => [$tienda, $g->count(), '$ ' . number_format($g->sum('monto'), 0, ',', '.')])
                ->values()
                ->all(),
        );

        if (! $this->option('aplicar')) {
            $this->warn('No se cambió nada. Corre con --aplicar para marcarlos.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($candidatos) {
            foreach ($candidatos as $p) {
                DB::table('pagos')->where('id', $p->id)->whereNull('despacho_item_id')
                    ->update(['despacho_item_id' => $p->despacho_item_id]);
            }
        });

        $this->info("✓ Marcados {$candidatos->count()} pagos. Las cajas ya no cuentan ese efectivo.");
        return self::SUCCESS;
    }
}
