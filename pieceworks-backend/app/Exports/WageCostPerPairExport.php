<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class WageCostPerPairExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Style / SKU',
            'Tier',
            'Total Pieces',
            'Total Wage Cost (PKR)',
            'Wage Cost / Pair (PKR)',
            'Workers',
            'Avg Pieces / Worker',
            'Week Ref',
        ];
    }
}
