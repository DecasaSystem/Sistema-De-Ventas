<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReporteExport implements FromCollection, WithStyles, WithTitle
{
    public function __construct(
        private Collection $rows,
        private array $headings,
        private string $title = '',
        private array $totals = [],
        private string $meta = '',
    ) {}

    public function collection(): Collection
    {
        $data = collect();

        // Fila 1: metadatos del reporte (período, tienda, fecha de exportación)
        if ($this->meta !== '') {
            $pad = array_fill(0, max(0, count($this->headings) - 1), '');
            $data->push(array_merge([$this->meta], $pad));
        }

        // Fila 2 (o 1 si no hay meta): encabezados de columna
        $data->push($this->headings);

        // Filas de datos
        foreach ($this->rows as $row) {
            $data->push(is_array($row) ? $row : (array) $row);
        }

        // Fila de totales
        if (! empty($this->totals)) {
            $data->push($this->totals);
        }

        return $data;
    }

    public function styles(Worksheet $sheet): array
    {
        $headingRow = $this->meta !== '' ? 2 : 1;
        $lastRow    = $sheet->getHighestRow();

        $styles = [
            $headingRow => ['font' => ['bold' => true]],
        ];

        // Fila de totales en negrita
        if (! empty($this->totals) && $lastRow > $headingRow) {
            $styles[$lastRow] = ['font' => ['bold' => true]];
        }

        // Estilo para la fila de metadatos
        if ($this->meta !== '') {
            $styles[1] = [
                'font' => [
                    'italic' => true,
                    'color'  => ['argb' => 'FF444444'],
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFEEF2FF'],
                ],
            ];
        }

        return $styles;
    }

    public function title(): string
    {
        return substr($this->title, 0, 31);
    }
}
