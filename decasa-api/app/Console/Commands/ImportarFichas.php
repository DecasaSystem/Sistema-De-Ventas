<?php

namespace App\Console\Commands;

use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarFichas extends Command
{
    protected $signature = 'fichas:importar {carpeta? : Ruta base de la carpeta de materiales}';
    protected $description = 'Importa fichas técnicas desde archivos Excel';

    private const SECTION_KEYWORDS = [
        'ESQUELETERIA', 'TAPICERIA', 'CARPINTERIA', 'MATERIALES',
        'CORTE Y COSTURA', 'LACA', 'PINTURA', 'HERRAJES', 'ACABADOS',
    ];

    public function handle()
    {
        $basePath = $this->argument('carpeta') ?: 'C:\\Users\\Lenovo\\Desktop\\materiales';

        if (!is_dir($basePath)) {
            $this->error("Carpeta no encontrada: $basePath");
            return 1;
        }

        $categorias = array_filter(scandir($basePath), function ($name) use ($basePath) {
            return is_dir("$basePath\\$name") && !in_array($name, ['.', '..', 'LOST.DIR']);
        });

        $totalProductos = 0;
        $totalErrores   = 0;

        DB::statement('DELETE FROM ficha_tecnica_items');
        DB::statement('DELETE FROM fichas_tecnicas');

        foreach ($categorias as $categoria) {
            $catPath = "$basePath\\$categoria";
            $archivos = array_filter(glob("$catPath\\*.xlsx"), fn($f) => !str_starts_with(basename($f), '~$'));

            if (empty($archivos)) {
                continue;
            }

            $this->info("Importando: $categoria (" . count($archivos) . " archivos)");

            foreach ($archivos as $filePath) {
                try {
                    $productos = $this->parsearExcel($filePath, $categoria);

                    foreach ($productos as $producto) {
                        if (empty($producto['items'])) {
                            continue;
                        }

                        $ficha = FichaTecnica::create([
                            'nombre'           => $producto['nombre'],
                            'categoria'        => $categoria,
                            'costo_materiales' => $producto['costo_materiales'],
                            'costo_mano_obra'  => $producto['costo_mano_obra'],
                            'costo_total'      => $producto['costo_total'],
                            'ruta_excel'       => $filePath,
                        ]);

                        foreach ($producto['items'] as $i => $item) {
                            FichaTecnicaItem::create([
                                'ficha_tecnica_id' => $ficha->id,
                                'seccion'          => $item['seccion'],
                                'descripcion'      => $item['descripcion'],
                                'cantidad'         => $item['cantidad'],
                                'unidad'           => $item['unidad'],
                                'precio_unitario'  => $item['precio_unitario'],
                                'subtotal'         => $item['subtotal'],
                                'es_mano_obra'     => $item['es_mano_obra'],
                                'orden'            => $i,
                            ]);
                        }

                        $totalProductos++;
                    }
                } catch (\Throwable $e) {
                    $this->warn("  Error en " . basename($filePath) . ": " . $e->getMessage());
                    $totalErrores++;
                }
            }
        }

        $this->info("✓ Importados: $totalProductos productos. Errores: $totalErrores");
        return 0;
    }

    private function parsearExcel(string $filePath, string $categoria): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray();

        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        // Detect side-by-side format (two products in same rows, cols G+)
        $hasSideBySide = false;
        foreach ($rows as $row) {
            $col6 = trim((string)($row[6] ?? ''));
            if ($col6 !== '' && !in_array(strtoupper($col6), ['SI ES OTRA TELA', 'EXCEDENTE'])) {
                $hasSideBySide = true;
                break;
            }
        }

        $productos = [];
        $productos[] = $this->parsearProducto($rows, $filename, 0);

        if ($hasSideBySide) {
            $segundo = $this->parsearProducto($rows, $filename, 6);
            if (!empty($segundo['items'])) {
                $productos[] = $segundo;
            }
        }

        return $productos;
    }

    private function parsearProducto(array $rows, string $filename, int $offset): array
    {
        $nombre          = $filename;
        $nombreEncontrado = false;
        $currentSection  = null;
        $items           = [];
        $costoMateriales = 0;
        $costoManoObra   = 0;
        $costoTotal      = 0;
        $orden           = 0;

        foreach ($rows as $row) {
            $c0 = trim((string)($row[$offset]     ?? ''));
            $c1 = trim((string)($row[$offset + 1] ?? ''));
            $c2 = trim((string)($row[$offset + 2] ?? ''));
            $c3 = trim((string)($row[$offset + 3] ?? ''));
            $c4 = trim((string)($row[$offset + 4] ?? ''));

            // Skip completely empty rows for this offset
            if ($c0 === '' && $c1 === '' && $c2 === '' && $c3 === '' && $c4 === '') {
                continue;
            }

            // TOTAL row: col2 or col3 = "TOTAL"
            if (strtoupper($c2) === 'TOTAL' && $c4 !== '') {
                $costoTotal = $this->parsePrecio($c4);
                continue;
            }
            if (strtoupper($c3) === 'TOTAL' && $c4 !== '') {
                $costoTotal = $this->parsePrecio($c4);
                continue;
            }

            // Section header with product name: keyword in c0, product name in c2, NOT a column-header row
            if (
                $c0 !== '' &&
                in_array(strtoupper($c0), self::SECTION_KEYWORDS) &&
                $c2 !== '' &&
                strtoupper($c1) !== 'CANTID'
            ) {
                if (!$nombreEncontrado && $offset === 0) {
                    $nombre = $c2;
                    $nombreEncontrado = true;
                } elseif (!$nombreEncontrado && $offset > 0) {
                    $nombre = $c2;
                    $nombreEncontrado = true;
                }
                // For MATERIALES sections, use the part/product name (col C) as section label
                // For process sections (ESQUELETERIA, TAPICERIA, etc.), use the process name (col A)
                $currentSection = (strtoupper($c0) === 'MATERIALES') ? $c2 : $c0;
                continue;
            }

            // Section header with CANTID (e.g., "TAPICERIA CANTID DESCRIPCION VR UNIT TOTAL")
            if ($c0 !== '' && strtoupper($c1) === 'CANTID') {
                if (in_array(strtoupper($c0), self::SECTION_KEYWORDS)) {
                    $currentSection = $c0;
                }
                continue;
            }

            // Column headers row (null/CANTID/...)
            if (strtoupper($c1) === 'CANTID') {
                continue;
            }

            // Item row: c0 non-empty, c1 numeric, c4 has price
            $cantidadStr = str_replace(',', '.', $c1);
            if ($c0 !== '' && is_numeric($cantidadStr) && $c4 !== '') {
                $cantidad       = (float) $cantidadStr;
                $precioUnitario = $this->parsePrecio($c3);
                $subtotal       = $this->parsePrecio($c4);

                if ($subtotal <= 0) {
                    continue;
                }

                $esManoObra = stripos($c0, 'MANO DE OBRA') !== false;

                $items[] = [
                    'seccion'         => $currentSection,
                    'descripcion'     => $c0,
                    'cantidad'        => $cantidad,
                    'unidad'          => $c2,
                    'precio_unitario' => $precioUnitario,
                    'subtotal'        => $subtotal,
                    'es_mano_obra'    => $esManoObra,
                ];

                if ($esManoObra) {
                    $costoManoObra += $subtotal;
                } else {
                    $costoMateriales += $subtotal;
                }

                $orden++;
            }
        }

        // If no TOTAL row found in Excel, calculate from items
        if ($costoTotal <= 0) {
            $costoTotal = $costoMateriales + $costoManoObra;
        }

        return [
            'nombre'           => $nombre,
            'costo_materiales' => $costoMateriales,
            'costo_mano_obra'  => $costoManoObra,
            'costo_total'      => $costoTotal,
            'items'            => $items,
        ];
    }

    private function parsePrecio(string $value): float
    {
        // Colombian format: commas are thousands separators (87,500 = 87500)
        $clean = preg_replace('/[\$\s]/', '', $value);
        $clean = str_replace(',', '', $clean);
        return max(0, (float) $clean);
    }
}
