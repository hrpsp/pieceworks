<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class AnnualPayrollSummaryExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Week Ref',
            'Week Start',
            'Week End',
            'Workers Paid',
            'Total Pieces',
            'Total Gross (PKR)',
            'Total Supplements (PKR)',
            'Total Deductions (PKR)',
            'Total Net (PKR)',
            'Advance Deductions (PKR)',
            'Loan Deductions (PKR)',
            'Rejection Deductions (PKR)',
            'Status',
        ];
    }
}
