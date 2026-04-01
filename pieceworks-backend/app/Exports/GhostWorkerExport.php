<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GhostWorkerExport implements FromCollection, WithHeadings, ShouldAutoSize
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
            'CNIC',
            'Contractor',
            'Flag Date',
            'Flag Type',
            'Absence Days',
            'Last Seen Date',
            'Override By',
            'Override Note',
            'Override At',
        ];
    }
}
