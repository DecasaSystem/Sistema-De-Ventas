<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReporteExport implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private Collection $rows,
        private array $headings,
        private string $title = '',
        private array $totals = []
    ) {}

    public function collection(): Collection
    {
        $data = $this->rows;

        if (!empty($this->totals)) {
            $data = $data->push($this->totals);
        }

        return $data;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true]],
        ];

        $lastRow = $sheet->getHighestRow();
        if ($lastRow > 1) {
            $styles[$lastRow] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    public function title(): string
    {
        return substr($this->title, 0, 31);
    }
}
