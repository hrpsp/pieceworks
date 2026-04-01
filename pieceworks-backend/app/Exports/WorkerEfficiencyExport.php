<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class WorkerEfficiencyExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Worker ID',
            'Worker Name',
            'Grade',
            'Contractor',
            'Days Worked',
            'Total Pieces',
            'Gross Earnings (PKR)',
            'Min Wage Supplement (PKR)',
            'Net Pay (PKR)',
            'Pieces / Day',
            'Earnings / Piece (PKR)',
        ];
    }
}
