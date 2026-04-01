<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EOBI Challan — {{ $summary['month'] }}/{{ $summary['year'] }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #222; margin: 20px; }
        h2 { font-size: 14px; margin-bottom: 4px; }
        .sub { font-size: 11px; color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #1a3c5e; color: #fff; padding: 5px 8px; text-align: left; font-size: 9px; }
        td { padding: 4px 8px; border-bottom: 1px solid #e0e0e0; }
        tr:nth-child(even) td { background: #f7f9fb; }
        tfoot td { font-weight: bold; background: #eef2f7; border-top: 2px solid #1a3c5e; }
        .right { text-align: right; }
        .summary-box { margin-top: 20px; border: 1px solid #ccc; padding: 10px; width: 280px; float: right; }
        .summary-box table { margin-top: 0; }
        .summary-box td { border: none; padding: 2px 6px; }
    </style>
</head>
<body>

<h2>EOBI Monthly Contribution Challan</h2>
<div class="sub">
    Period: {{ str_pad($summary['month'], 2, '0', STR_PAD_LEFT) }} / {{ $summary['year'] }}
    &nbsp;|&nbsp; Total Workers: {{ $summary['total_workers'] }}
    &nbsp;|&nbsp; Generated: {{ now()->format('d M Y H:i') }}
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>EOBI No.</th>
            <th>Worker Name</th>
            <th>CNIC</th>
            <th>Contractor</th>
            <th>Grade</th>
            <th class="right">Employer (PKR)</th>
            <th class="right">Employee (PKR)</th>
            <th class="right">Total (PKR)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $i => $row)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $row['eobi_number'] ?? '—' }}</td>
            <td>{{ $row['worker_name'] }}</td>
            <td>{{ $row['cnic'] }}</td>
            <td>{{ $row['contractor'] ?? '—' }}</td>
            <td>{{ $row['grade'] ?? '—' }}</td>
            <td class="right">{{ number_format($row['employer_contribution'], 2) }}</td>
            <td class="right">{{ number_format($row['employee_contribution'], 2) }}</td>
            <td class="right">{{ number_format($row['total'], 2) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6"><strong>TOTAL</strong></td>
            <td class="right">{{ number_format($summary['total_employer_contrib'], 2) }}</td>
            <td class="right">{{ number_format($summary['total_employee_contrib'], 2) }}</td>
            <td class="right">{{ number_format($summary['grand_total'], 2) }}</td>
        </tr>
    </tfoot>
</table>

<div class="summary-box">
    <strong>Summary</strong>
    <table>
        <tr><td>Employer Contributions</td><td class="right">PKR {{ number_format($summary['total_employer_contrib'], 2) }}</td></tr>
        <tr><td>Employee Contributions</td><td class="right">PKR {{ number_format($summary['total_employee_contrib'], 2) }}</td></tr>
        <tr><td><strong>Grand Total</strong></td><td class="right"><strong>PKR {{ number_format($summary['grand_total'], 2) }}</strong></td></tr>
    </table>
</div>

</body>
</html>
