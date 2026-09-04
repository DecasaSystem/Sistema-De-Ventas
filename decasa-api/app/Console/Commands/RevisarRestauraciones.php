<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Busca órdenes cuya numeración dice una cosa y cuyos muebles dicen otra.
 *
 * De dónde sale el problema: "esto es una restauración" está guardado en dos
 * capas. Arriba, la numeración (`ordenes.serie` = R) y la etiqueta
 * (`ordenes.tipo`). Abajo, los muebles: `orden_items.es_restauracion` marca
 * los que son del cliente, y comisiones, reportes, stats y el taller deducen
 * de ahí —no de la serie— si la orden entera es una restauración
 * (Orden::sqlTipo(), ComisionController::idsDeRestauracion()).
 *
 * Mientras el corrector de numeración movió solo la capa de arriba, cada
 * corrección dejó una orden descuadrada: la #1242 quedó numerada como venta
 * normal de Pereira y cobrada como restauración —al 5% aparte y sin sumarle a
 * la meta de la tienda—. NumeracionOrdenes ya mueve las dos capas; esto
 * limpia las que quedaron de antes.
 *
 * Sin `--aplicar` no toca nada: solo lista. Y aun con `--aplicar` corrige
 * únicamente las que pasaron por el corrector (tienen la conversión en su
 * historial). Las demás pueden ser órdenes viejas legítimas —restauraciones
 * de cuando no existía la serie R, numeradas normal— y moverlas cambiaría una
 * comisión histórica que nadie pidió cambiar; se listan aparte y solo entran
 * con `--todas`.
 */
class RevisarRestauraciones extends Command
{
    protected $signature = 'ordenes:revisar-restauraciones
                            {--aplicar : Corrige de verdad. Sin esto solo muestra lo que encontró}
                            {--todas   : Incluye también las que nunca pasaron por el corrector de numeración}
                            {--orden=  : Corrige solo esta, por referencia (#1242, R-1095) o por id}';

    protected $description = 'Encuentra órdenes cuyo número dice venta y cuyos muebles dicen restauración (o al revés) y las cuadra';

    public function handle(): int
    {
        $descuadradas = $this->descuadradas();

        if ($descuadradas->isEmpty()) {
            $this->info('✓ Todas las órdenes están cuadradas: el número y los muebles dicen lo mismo.');
            return 0;
        }

        $convertidas = $this->idsQuePasaronPorElCorrector();

        [$porCorrector, $historicas] = $descuadradas->partition(
            fn ($o) => isset($convertidas[(int) $o->id])
        );

        $this->mostrar('Descuadradas por una corrección de numeración', $porCorrector);
        $this->mostrar('Descuadradas de antes (NO se tocan sin --todas)', $historicas);

        $aCorregir = $this->option('todas')
            ? $descuadradas
            : $porCorrector;

        // Cada orden descuadrada tiene su propia historia y cuadrarla mueve la
        // comisión de un mes que puede estar liquidado, así que se puede ir de
        // a una en vez de aceptar la lista entera.
        if ($sola = $this->option('orden')) {
            $sola      = trim($sola);
            $aCorregir = $descuadradas->filter(
                fn ($o) => $this->referencia($o) === $sola || (string) $o->id === $sola
            );

            if ($aCorregir->isEmpty()) {
                $this->error("No hay ninguna orden descuadrada que sea \"{$sola}\".");
                return 1;
            }
        }

        // Una restauración es el mueble del cliente: no sale de inventario. Si
        // la orden lleva productos del catálogo, marcarlos como del cliente
        // mentiría sobre un stock que ya se descontó. Esas se dejan quietas y
        // se reportan para que alguien las mire.
        [$conCatalogo, $aCorregir] = $aCorregir->partition(
            fn ($o) => $o->debe === 'restauracion' && $o->de_catalogo > 0
        );

        if ($conCatalogo->isNotEmpty()) {
            $this->warn("\n⚠  " . $conCatalogo->count() . ' orden(es) numeradas R llevan productos del inventario. '
                      . 'No se tocan: una restauración es el mueble del cliente. Hay que revisarlas a mano:');
            foreach ($conCatalogo as $o) {
                $this->line("   {$this->referencia($o)} · {$o->cliente} · {$o->de_catalogo} producto(s) de catálogo");
            }
        }

        if ($aCorregir->isEmpty()) {
            $this->line("\nNada que corregir con estas opciones.");
            return 0;
        }

        if (! $this->option('aplicar')) {
            $this->line("\n" . $aCorregir->count() . ' orden(es) se corregirían. Nada se ha tocado: '
                      . 'corre otra vez con --aplicar cuando estés de acuerdo con la lista.');
            return 0;
        }

        $this->aplicar($aCorregir);

        return 0;
    }

    /**
     * Las órdenes donde la numeración y los muebles no dicen lo mismo.
     *
     * `debe` es lo que la NUMERACIÓN dice que la orden es, que es la que manda:
     * el número está impreso en el talonario del cliente y no se puede
     * desdecir; la marca de los muebles sí.
     */
    private function descuadradas()
    {
        return DB::table('ordenes as o')
            ->join('orden_items as oi', 'oi.orden_id', '=', 'o.id')
            ->leftJoin('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('tiendas as t', 't.id', '=', 'o.tienda_id')
            ->groupBy('o.id')
            ->havingRaw("(o.serie = 'R' AND SUM(oi.es_restauracion) < COUNT(oi.id))
                      OR (COALESCE(o.serie, '') <> 'R' AND SUM(oi.es_restauracion) = COUNT(oi.id))")
            ->selectRaw("o.id, o.serie, o.serie_numero, o.numero_orden, o.tipo, o.estado, o.valor_total,
                         MAX(c.nombre) as cliente, MAX(t.nombre) as tienda,
                         COUNT(oi.id) as items,
                         SUM(oi.es_restauracion) as restauraciones,
                         SUM(CASE WHEN oi.producto_id IS NULL THEN 0 ELSE 1 END) as de_catalogo,
                         CASE WHEN o.serie = 'R' THEN 'restauracion' ELSE 'venta' END as debe")
            ->orderBy('o.id')
            ->get();
    }

    /**
     * Quiénes pasaron por el corrector de numeración.
     *
     * Se busca en el historial de la orden, que es donde el corrector deja el
     * rastro (`cambios[].campo = "numeracion"`). Con LIKE y no con las
     * funciones JSON para que corra igual en MySQL y en SQLite.
     *
     * Se busca la palabra suelta y no el par `"campo":"numeracion"` porque una
     * columna JSON de MySQL no guarda el texto tal como se escribió: lo
     * normaliza —le mete un espacio después de los dos puntos y reordena las
     * claves—, así que el par exacto no aparece nunca y el rastro de la #1242
     * pasaba de largo. Ningún otro campo del historial dice "numeracion".
     */
    private function idsQuePasaronPorElCorrector(): array
    {
        return DB::table('orden_ediciones')
            ->where('cambios', 'like', '%numeracion%')
            ->distinct()->pluck('orden_id')
            ->map(fn ($v) => (int) $v)->flip()->all();
    }

    private function mostrar(string $titulo, $ordenes): void
    {
        if ($ordenes->isEmpty()) {
            return;
        }

        $this->line("\n{$titulo} (" . $ordenes->count() . '):');
        $this->table(
            ['Orden', 'Cliente', 'Tienda', 'Estado', 'Valor', 'Hoy cobra como', 'Debería'],
            $ordenes->map(fn ($o) => [
                $this->referencia($o),
                $o->cliente ?: '—',
                $o->tienda ?: '—',
                $o->estado,
                number_format((float) $o->valor_total),
                $o->debe === 'venta' ? 'restauración' : 'venta',
                $o->debe === 'venta' ? 'venta' : 'restauración',
            ])->all()
        );
    }

    private function referencia($o): string
    {
        return $o->serie ? "{$o->serie}-{$o->serie_numero}" : "#{$o->numero_orden}";
    }

    private function aQuienSeLeAnota(int $ordenId): ?int
    {
        $ediciones = DB::table('orden_ediciones')->where('orden_id', $ordenId)->orderByDesc('id');

        return (clone $ediciones)->where('cambios', 'like', '%numeracion%')->value('usuario_id')
            ?? $ediciones->value('usuario_id')
            ?? DB::table('usuarios')->where('rol', 'supervisor')->orderBy('id')->value('id');
    }

    private function aplicar($ordenes): void
    {
        DB::transaction(function () use ($ordenes) {
            foreach ($ordenes as $o) {
                $aRestauracion = $o->debe === 'restauracion';

                $tocados = DB::table('orden_items')
                    ->where('orden_id', $o->id)
                    ->where('es_restauracion', $aRestauracion ? 0 : 1)
                    ->update(['es_restauracion' => $aRestauracion]);

                DB::table('ordenes')->where('id', $o->id)
                    ->update(['tipo' => $aRestauracion ? 'restauracion' : 'venta']);

                // Queda en el historial de la orden. La columna de usuario no
                // admite null, así que la edición se le atribuye a quien hizo
                // la conversión que dejó el descuadre —es la misma corrección,
                // terminada—; si la orden no tiene historial, a un supervisor.
                DB::table('orden_ediciones')->insert([
                    'orden_id'   => $o->id,
                    'usuario_id' => $this->aQuienSeLeAnota((int) $o->id),
                    'cambios'    => json_encode([[
                        'campo'   => 'items_restauracion',
                        'label'   => "Cuadrado con la numeración: {$tocados} mueble(s) corregidos",
                        'antes'   => $aRestauracion ? 'Venta' : 'Restauración',
                        'despues' => $aRestauracion ? 'Restauración' : 'Venta',
                    ]], JSON_UNESCAPED_UNICODE),
                    // La tabla solo lleva created_at (OrdenEdicion tiene
                    // $timestamps = false): una edición no se edita.
                    'created_at' => now(),
                ]);

                $this->line("  ✓ {$this->referencia($o)} · {$tocados} mueble(s) → "
                          . ($aRestauracion ? 'restauración' : 'venta'));
            }
        });

        $this->info("\n✓ " . $ordenes->count() . ' orden(es) cuadradas.');
        $this->warn('Ojo: esto cambia lo que esas órdenes comisionan. Hay que recalcular '
                  . 'las comisiones de los meses en que caen, desde el módulo de Comisiones.');
    }
}
