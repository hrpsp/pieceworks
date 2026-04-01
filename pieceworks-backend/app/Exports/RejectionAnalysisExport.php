<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RejectionAnalysisExport implements FromCollection, WithHeadings, ShouldAutoSize
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
            'Contractor',
            'Line',
            'Style / SKU',
            'Rejection Date',
            'Pieces Rejected',
            'Deduction (PKR)',
            'Reason',
            'Status',
            'Disputed At',
        ];
    }
}
