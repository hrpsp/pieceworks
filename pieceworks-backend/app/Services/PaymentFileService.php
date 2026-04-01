<?php

namespace App\Services;

use App\Models\PaymentFile;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class PaymentFileService
{
    // JazzCash single-transaction cap (PKR)
    private const JAZZCASH_LIMIT = 25_000.00;

    // PKR note/coin denominations, descending
    private const DENOMINATIONS = [5000, 1000, 500, 100, 50, 20, 10, 5, 2, 1];

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Generate JazzCash bulk-disbursement CSV for a payroll run.
     * Workers with net_pay > 25,000 are split into multiple rows.
     *
     * @return string  Storage-relative file path.
     */
    public function generateJazzCashBatch(int $payrollRunId): string
    {
        $run  = WeeklyPayrollRun::findOrFail($payrollRunId);
        $rows = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->where('payment_method', 'jazzcash')
            ->where('net_pay', '>', 0)
            ->with('worker:id,name,cnic,payment_number')
            ->get();

        $csvLines   = [];
        $csvLines[] = 'MSISDN,Amount,TransactionID,Reference,Description';

        $totalAmount  = 0.0;
        $workerCount  = 0;

        foreach ($rows as $wwp) {
            $worker  = $wwp->worker;
            $mobile  = $worker?->payment_number ?? '';
            $netPay  = (float) $wwp->net_pay;
            $seq     = 1;
            $remaining = $netPay;

            while ($remaining > 0) {
                $chunk   = min($remaining, self::JAZZCASH_LIMIT);
                $txnId   = sprintf('JC_%d_%d_%02d', $payrollRunId, $wwp->worker_id, $seq);
                $ref     = "PAY_{$run->week_ref}_{$wwp->worker_id}";
                $desc    = "Weekly wage {$run->week_ref} – " . ($worker?->name ?? "Worker#{$wwp->worker_id}");
                if ($seq > 1) {
                    $desc .= " (part {$seq})";
                }

                $csvLines[] = implode(',', [
                    $this->csvCell($mobile),
                    number_format($chunk, 2, '.', ''),
                    $this->csvCell($txnId),
                    $this->csvCell($ref),
                    $this->csvCell($desc),
                ]);

                $totalAmount += $chunk;
                $remaining   -= $chunk;
                $seq++;
            }

            $workerCount++;
        }

        $path = $this->writeCsv(
            $payrollRunId,
            'jazzcash_batch',
            $csvLines
        );

        $this->recordPaymentFile($payrollRunId, 'jazzcash_batch', $path, $totalAmount, $workerCount);

        return $path;
    }

    /**
     * Generate a bank ACH transfer CSV for workers paid via bank transfer.
     *
     * @return string  Storage-relative file path.
     */
    public function generateBankTransferFile(int $payrollRunId): string
    {
        $run  = WeeklyPayrollRun::findOrFail($payrollRunId);
        $rows = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->where('payment_method', 'bank')
            ->where('net_pay', '>', 0)
            ->with('worker:id,name,cnic,payment_number')
            ->get();

        $csvLines   = [];
        $csvLines[] = 'BankCode,AccountNumber,AccountTitle,CNIC,Amount,Reference,Remarks';

        $totalAmount = 0.0;
        $workerCount = 0;

        foreach ($rows as $wwp) {
            $worker  = $wwp->worker;
            $account = $worker?->payment_number ?? '';
            $netPay  = (float) $wwp->net_pay;
            $ref     = "PAY_{$run->week_ref}_{$wwp->worker_id}";
            $remarks = "Weekly wage – {$run->week_ref}";

            // Bank code defaults to HABIB (MCB / HBL known at setup);
            // extend with a workers.bank_code column as needed.
            $csvLines[] = implode(',', [
                $this->csvCell('HABIB'),
                $this->csvCell($account),
                $this->csvCell($worker?->name ?? ''),
                $this->csvCell($worker?->cnic ?? ''),
                number_format($netPay, 2, '.', ''),
                $this->csvCell($ref),
                $this->csvCell($remarks),
            ]);

            $totalAmount += $netPay;
            $workerCount++;
        }

        $path = $this->writeCsv(
            $payrollRunId,
            'bank_transfer',
            $csvLines
        );

        $this->recordPaymentFile($payrollRunId, 'bank_transfer', $path, $totalAmount, $workerCount);

        return $path;
    }

    /**
     * Generate a cash-disbursement PDF (requires barryvdh/laravel-dompdf).
     * Includes per-worker name/CNIC/net_pay/line/signature column,
     * plus a total denomination breakdown at the end.
     *
     * @return string  Storage-relative file path.
     */
    public function generateCashList(int $payrollRunId): string
    {
        // Requires: composer require barryvdh/laravel-dompdf
        $run  = WeeklyPayrollRun::findOrFail($payrollRunId);
        $rows = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->where('payment_method', 'cash')
            ->where('net_pay', '>', 0)
            ->with('worker:id,name,cnic,default_line_id', 'worker.defaultLine:id,name')
            ->orderBy('worker_id')
            ->get();

        $totalAmount = $rows->sum(fn ($r) => (float) $r->net_pay);
        $workerCount = $rows->count();
        $denomBreakdown = $this->denominationBreakdown($totalAmount);

        $html = $this->buildCashListHtml($run, $rows, $totalAmount, $denomBreakdown);

        $dir  = "payment-files/{$payrollRunId}";
        $file = "cash_list_{$run->week_ref}.pdf";
        $path = "{$dir}/{$file}";

        Storage::disk('local')->makeDirectory($dir);

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'portrait');
            Storage::disk('local')->put($path, $pdf->output());
        } else {
            // Fallback: save as HTML if dompdf is not installed.
            $htmlPath = "{$dir}/cash_list_{$run->week_ref}.html";
            Storage::disk('local')->put($htmlPath, $html);
            $path = $htmlPath;
        }

        $this->recordPaymentFile($payrollRunId, 'cash_list', $path, $totalAmount, $workerCount);

        return $path;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /** Write CSV lines to storage and return the relative path. */
    private function writeCsv(int $payrollRunId, string $type, array $lines): string
    {
        $run  = WeeklyPayrollRun::find($payrollRunId);
        $dir  = "payment-files/{$payrollRunId}";
        $file = "{$type}_{$run->week_ref}.csv";
        $path = "{$dir}/{$file}";

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));

        return $path;
    }

    /** Upsert a PaymentFile record (idempotent per run + type). */
    private function recordPaymentFile(
        int    $payrollRunId,
        string $type,
        string $path,
        float  $totalAmount,
        int    $workerCount,
    ): void {
        PaymentFile::updateOrCreate(
            ['payroll_run_id' => $payrollRunId, 'file_type' => $type],
            [
                'file_path'    => $path,
                'total_amount' => $totalAmount,
                'worker_count' => $workerCount,
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Return how many notes/coins of each denomination are needed.
     * Example: 5_720 → [5000 => 1, 500 => 1, 100 => 2, 20 => 1]
     *
     * @return array<int, int>  denomination => count
     */
    private function denominationBreakdown(float $totalAmount): array
    {
        $remaining  = (int) round($totalAmount);
        $breakdown  = [];

        foreach (self::DENOMINATIONS as $denom) {
            $count = (int) floor($remaining / $denom);
            if ($count > 0) {
                $breakdown[$denom] = $count;
                $remaining        -= $count * $denom;
            }
        }

        return $breakdown;
    }

    /** Build inline-HTML for the cash-list PDF. */
    private function buildCashListHtml(
        WeeklyPayrollRun $run,
        \Illuminate\Database\Eloquent\Collection $rows,
        float $totalAmount,
        array $denomBreakdown,
    ): string {
        $generatedAt  = Carbon::now()->format('d M Y, H:i');
        $weekRef      = $run->week_ref;
        $totalFormatted = 'Rs ' . number_format($totalAmount, 2);

        $rowsHtml = '';
        $srNo     = 1;
        foreach ($rows as $wwp) {
            $w    = $wwp->worker;
            $line = $w?->defaultLine?->name ?? '—';
            $net  = 'Rs ' . number_format((float) $wwp->net_pay, 2);

            $rowsHtml .= "
            <tr>
                <td>{$srNo}</td>
                <td>" . htmlspecialchars($w?->name ?? '') . "</td>
                <td>" . htmlspecialchars($w?->cnic ?? '') . "</td>
                <td>" . htmlspecialchars($line) . "</td>
                <td class='amount'>{$net}</td>
                <td class='sig'></td>
            </tr>";
            $srNo++;
        }

        $denomRows = '';
        foreach ($denomBreakdown as $denom => $count) {
            $value       = $denom * $count;
            $denomRows  .= "<tr>
                <td>Rs {$denom}</td>
                <td>{$count}</td>
                <td>Rs " . number_format($value, 2) . "</td>
            </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #222; }
  h1   { font-size: 16px; text-align: center; margin-bottom: 4px; }
  h2   { font-size: 12px; text-align: center; color: #555; margin-top: 0; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th, td { border: 1px solid #ccc; padding: 5px 7px; }
  th   { background: #f0f0f0; text-align: left; }
  td.amount { text-align: right; }
  td.sig { min-width: 90px; }
  .totals { font-weight: bold; }
  .section-title { font-size: 13px; font-weight: bold; margin: 14px 0 6px; }
  .footer { margin-top: 20px; font-size: 10px; color: #888; text-align: right; }
</style>
</head>
<body>
  <h1>Cash Disbursement List</h1>
  <h2>Week: {$weekRef} &nbsp;|&nbsp; Generated: {$generatedAt}</h2>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Worker Name</th>
        <th>CNIC</th>
        <th>Line</th>
        <th>Net Pay</th>
        <th>Signature</th>
      </tr>
    </thead>
    <tbody>
      {$rowsHtml}
      <tr class="totals">
        <td colspan="4" style="text-align:right">TOTAL</td>
        <td class="amount">{$totalFormatted}</td>
        <td></td>
      </tr>
    </tbody>
  </table>

  <div class="section-title">Denomination Breakdown (Total: {$totalFormatted})</div>
  <table style="width:40%">
    <thead>
      <tr><th>Denomination</th><th>Count</th><th>Value</th></tr>
    </thead>
    <tbody>
      {$denomRows}
    </tbody>
  </table>

  <div class="footer">Printed from PieceWorks &mdash; Confidential. For internal use only.</div>
</body>
</html>
HTML;
    }

    /** Wrap a value in CSV-safe double quotes, escaping internal quotes. */
    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
