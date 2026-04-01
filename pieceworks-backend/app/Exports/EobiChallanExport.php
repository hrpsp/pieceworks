<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class EobiChallanExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'EOBI #',
            'Worker Name',
            'CNIC',
            'Contractor',
            'Grade',
            'Employer Contribution (PKR)',
            'Employee Contribution (PKR)',
            'Total (PKR)',
            'Month',
            'Year',
        ];
    }
}
