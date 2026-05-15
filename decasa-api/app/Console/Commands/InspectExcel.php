<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class InspectExcel extends Command
{
    protected $signature = 'excel:inspect {path}';
    protected $description = 'Inspects an Excel file and prints its contents';

    public function handle()
    {
        $path = $this->argument('path');

        if (!file_exists($path)) {
            $this->error("File not found: $path");
            return 1;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $this->info("Total rows: " . count($rows));
        $this->line('');

        foreach ($rows as $i => $row) {
            $nonEmpty = array_filter($row, fn($v) => $v !== null && $v !== '');
            if (empty($nonEmpty)) continue;

            $this->line("Row " . ($i + 1) . ": " . json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }
}
