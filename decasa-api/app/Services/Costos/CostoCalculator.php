<?php

namespace App\Services\Costos;

use Illuminate\Support\Facades\DB;

/**
 * Aritmética del cotizador. Sin IA.
 *
 * Recibe la receta (BOM) que armó el modelo — material_id + cantidad, cargo + horas —
 * y calcula el costo trayendo los precios de la BD por ID. El modelo nunca escribe un
 * precio: si un material_id no existe o un cargo es desconocido, la línea se descarta
 * y queda registrada en las notas.
 */
class CostoCalculator
{
    /**
     * @param array $bom       Receta devuelta por BomBuilder.
     * @param array $opciones  multiplicador, regla_venta, precio_catalogo, cantidad.
     */
    public function calcular(array $bom, array $opciones = []): array
    {
        $multiplicador  = (float) ($opciones['multiplicador'] ?? 2.2);
        $reglaVenta     = $opciones['regla_venta'] ?? 'multiplicador';
        $precioCatalogo = isset($opciones['precio_catalogo']) ? (float) $opciones['precio_catalogo'] : null;
        $cantidad       = max(1, (int) ($opciones['cantidad'] ?? 1));

        $componentes = $bom['componentes'] ?? [];

        // Precios reales desde la BD — nunca los del modelo
        $ids = [];
        foreach ($componentes as $c) {
            foreach (($c['materiales'] ?? []) as $m) {
                if (! empty($m['material_id'])) $ids[] = (int) $m['material_id'];
            }
        }

        $materiales = empty($ids)
            ? collect()
            : DB::table('materiales')->whereIn('id', array_unique($ids))
                ->get(['id', 'nombre', 'unidad', 'precio_unitario'])->keyBy('id');

        $cargos = DB::table('salarios_cargo')->get(['cargo', 'tarifa_hora'])->keyBy('cargo');

        $desgloseMat = [];
        $desgloseMo  = [];
        $descartes   = [];

        $multiComponente = count($componentes) > 1;

        foreach ($componentes as $comp) {
            $prefijo = $multiComponente && ! empty($comp['nombre'])
                ? trim($comp['nombre']) . ' · '
                : '';

            foreach (($comp['materiales'] ?? []) as $m) {
                $id  = (int) ($m['material_id'] ?? 0);
                $qty = (float) ($m['cantidad'] ?? 0);
                $mat = $materiales->get($id);

                if (! $mat) {
                    $descartes[] = "Material no reconocido (id {$id}) — línea omitida del cálculo.";
                    continue;
                }
                if ($qty <= 0) {
                    $descartes[] = "Cantidad inválida para {$mat->nombre} — línea omitida.";
                    continue;
                }

                $precio = (float) $mat->precio_unitario;

                $desgloseMat[] = [
                    'descripcion'     => $prefijo . $mat->nombre . ' — ' . $this->num($qty) . ' '
                                       . ($mat->unidad ?: 'und') . ' × ' . $this->cop($precio),
                    'subtotal'        => round($qty * $precio, 2),
                    'material_id'     => $id,
                    'cantidad'        => $qty,
                    'precio_unitario' => $precio,
                ];
            }

            foreach (($comp['mano_obra'] ?? []) as $mo) {
                $cargo = mb_strtolower(trim($mo['cargo'] ?? ''));
                $horas = (float) ($mo['horas'] ?? 0);
                $ref   = $cargos->get($cargo);

                if (! $ref) {
                    $descartes[] = "Cargo no reconocido ('{$cargo}') — mano de obra omitida.";
                    continue;
                }
                if ($horas <= 0) {
                    $descartes[] = "Horas inválidas para {$cargo} — línea omitida.";
                    continue;
                }

                $tarifaHora = (float) $ref->tarifa_hora;
                $proceso    = ! empty($mo['proceso']) ? ' — ' . str_replace('_', ' ', $mo['proceso']) : '';

                $desgloseMo[] = [
                    'descripcion'     => $prefijo . ucfirst($cargo) . $proceso . ': '
                                       . $this->num($horas) . ' h × ' . $this->cop($tarifaHora) . '/h',
                    'subtotal'        => round($horas * $tarifaHora, 2),
                    'cargo'           => $cargo,
                    'horas'           => $horas,
                    'precio_unitario' => $tarifaHora,
                ];
            }
        }

        $totalMateriales = round(array_sum(array_column($desgloseMat, 'subtotal')), 2);
        $totalManoObra   = round(array_sum(array_column($desgloseMo,  'subtotal')), 2);

        // El desglose es por unidad; la cantidad multiplica el total
        $precioFabricacion = (int) round(($totalMateriales + $totalManoObra) * $cantidad);

        // Regla de venta
        if ($reglaVenta === 'catalogo_mas_costo' && $precioCatalogo) {
            // Retapizado / ajuste sobre mueble ya fabricado: el cliente paga el producto
            // más el costo del servicio. Sin multiplicador de fabricación.
            $precioSugerido = (int) round(($precioCatalogo * $cantidad) + $precioFabricacion);
        } else {
            $precioSugerido = (int) round($precioFabricacion * $multiplicador);
            if ($precioCatalogo) {
                $precioSugerido = max($precioSugerido, (int) round($precioCatalogo * $cantidad));
            }
        }

        $sinDatos = empty($desgloseMat) && empty($desgloseMo);

        return [
            'precio_fabricacion'    => $precioFabricacion,
            'precio_sugerido_venta' => $precioSugerido,
            'costo_materiales'      => (int) round($totalMateriales * $cantidad),
            'costo_mano_obra'       => (int) round($totalManoObra   * $cantidad),
            'desglose_materiales'   => $desgloseMat,
            'desglose_mano_obra'    => $desgloseMo,
            'notas'                 => $this->notas($bom, $descartes, $sinDatos),
            'requiere_revision'     => $sinDatos,
            'multiplicador_usado'   => $reglaVenta === 'multiplicador' ? $multiplicador : null,
            'regla_venta'           => $reglaVenta,
        ];
    }

    /**
     * Notas para el vendedor. Las líneas con ⚠️ las resalta el front.
     */
    private function notas(array $bom, array $descartes, bool $sinDatos): string
    {
        $lineas = [];

        foreach (($bom['supuestos'] ?? []) as $s) {
            if (is_string($s) && trim($s) !== '') $lineas[] = trim($s);
        }

        foreach (($bom['consultar'] ?? []) as $c) {
            if (is_string($c) && trim($c) !== '') {
                $c = trim($c);
                $lineas[] = str_starts_with($c, '⚠️') ? $c : "⚠️ CONSULTAR: {$c}";
            }
        }

        foreach (array_unique($descartes) as $d) {
            $lineas[] = "⚠️ {$d}";
        }

        if ($sinDatos) {
            $lineas[] = '⚠️ No se pudo construir el desglose con materiales del catálogo. '
                      . 'Envía una consulta de costo al ebanista.';
        }

        return implode("\n", $lineas);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }

    private function cop(float $n): string
    {
        return '$' . number_format($n, 0, ',', '.');
    }
}
