<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class LineProductivityExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Line',
            'Contractor',
            'Active Workers',
            'Total Pieces',
            'Total Wage Cost (PKR)',
            'Target Pieces',
            'Attainment (%)',
            'Pieces / Worker',
            'Rejections',
            'Rejection Rate (%)',
        ];
    }
}
