<?php

namespace App\Services;

use App\Jobs\GenerateStatementsJob;
use App\Models\Deduction;
use App\Models\ProductionRecord;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Models\WorkerStatement;
use App\Models\WorkerWeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WorkerStatementService
{
    // Dispute window given to workers after statement generation (calendar days).
    private const DISPUTE_WINDOW_DAYS = 3;

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Compile the full weekly breakdown for one worker and persist it.
     *
     * Returns the statement array (also stored as JSON in worker_statements).
     */
    public function generateStatement(int $workerId, int $payrollRunId): array
    {
        $run    = WeeklyPayrollRun::findOrFail($payrollRunId);
        $worker = Worker::with(['contractor:id,name'])->findOrFail($workerId);

        $wwp = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->where('worker_id', $workerId)
            ->firstOrFail();

        // ── Daily production breakdown ──────────────────────────────────────
        $records = ProductionRecord::where('worker_id', $workerId)
            ->whereBetween('work_date', [$run->start_date->toDateString(), $run->end_date->toDateString()])
            ->whereNotIn('validation_status', ['rejected', 'voided'])
            ->with([
                'line:id,name',
                'styleSku:id,style_code',
                'shiftAuthorizer:id,name',
            ])
            ->orderBy('work_date')
            ->orderBy('shift')
            ->get();

        $dailyBreakdown = [];
        foreach ($records as $rec) {
            $dailyBreakdown[] = [
                'date'             => $rec->work_date->toDateString(),
                'shift'            => $rec->shift,
                'line'             => $rec->line?->name,
                'task'             => $rec->task,
                'style'            => $rec->styleSku?->style_code,
                'pairs'            => $rec->pairs_produced,
                'rate'             => (float) $rec->rate_amount,
                'earnings'         => (float) $rec->gross_earnings,
                'shift_adjustment' => $rec->shift_adjustment != 0 ? [
                    'amount'        => (float) $rec->shift_adjustment,
                    'reason'        => $rec->shift_adj_reason,
                    'authorized_by' => $rec->shiftAuthorizer?->name,
                ] : null,
            ];
        }

        // ── Deduction lines ─────────────────────────────────────────────────
        $deductions = Deduction::where('worker_id', $workerId)
            ->where('payroll_run_id', $payrollRunId)
            ->where('status', 'applied')
            ->with('deductionType:id,code,name')
            ->orderBy('id')
            ->get();

        $deductionLines = $deductions->map(fn ($d) => [
            'type'             => $d->deductionType?->code   ?? 'other',
            'label'            => $d->deductionType?->name   ?? 'Deduction',
            'reference'        => $d->reference_type && $d->reference_id
                                    ? "{$d->reference_type}#{$d->reference_id}"
                                    : null,
            'week_ref'         => $d->week_ref,
            'carry_from_week'  => $d->carry_from_week,
            'amount'           => (float) $d->amount,
        ])->values()->all();

        // ── Assemble statement ───────────────────────────────────────────────
        $totalDeductions = round(
            (float) $wwp->advance_deductions
            + (float) $wwp->rejection_deductions
            + (float) $wwp->loan_deductions
            + (float) $wwp->other_deductions,
            2
        );

        $statement = [
            'worker' => [
                'id'             => $worker->id,
                'name'           => $worker->name,
                'cnic'           => $worker->cnic,
                'employee_id'    => $worker->biometric_id,
                'grade'          => $worker->grade,
                'contractor'     => $worker->contractor?->name,
                'payment_method' => $wwp->payment_method,
                'payment_number' => $worker->payment_number,
                'whatsapp'       => $worker->whatsapp,
            ],
            'payroll_run' => [
                'id'         => $run->id,
                'week_ref'   => $run->week_ref,
                'start_date' => $run->start_date->toDateString(),
                'end_date'   => $run->end_date->toDateString(),
            ],
            'daily_breakdown'  => $dailyBreakdown,
            'earnings_summary' => [
                'gross_earnings'       => (float) $wwp->gross_earnings,
                'ot_premium'           => (float) $wwp->ot_premium,
                'shift_allowance'      => (float) $wwp->shift_allowance,
                'holiday_pay'          => (float) $wwp->holiday_pay,
                'min_wage_supplement'  => (float) $wwp->min_wage_supplement,
                'total_gross'          => (float) $wwp->total_gross,
            ],
            'deductions'       => $deductionLines,
            'totals'           => [
                'advance_deductions'   => (float) $wwp->advance_deductions,
                'rejection_deductions' => (float) $wwp->rejection_deductions,
                'loan_deductions'      => (float) $wwp->loan_deductions,
                'other_deductions'     => (float) $wwp->other_deductions,
                'carry_forward_amount' => (float) $wwp->carry_forward_amount,
                'total_deductions'     => $totalDeductions,
                'net_pay'              => (float) $wwp->net_pay,
            ],
            'payment' => [
                'method' => $wwp->payment_method,
                'number' => $worker->payment_number,
                'status' => $wwp->payment_status,
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        // Persist / overwrite (idempotent: re-generating always reflects latest data)
        WorkerStatement::updateOrCreate(
            ['worker_id' => $workerId, 'payroll_run_id' => $payrollRunId],
            [
                'week_ref'                 => $run->week_ref,
                'statement_data'           => $statement,
                'generated_at'             => now(),
                'dispute_window_closes_at' => now()->addDays(self::DISPUTE_WINDOW_DAYS),
            ]
        );

        return $statement;
    }

    /**
     * Dispatch statement generation as background jobs for every worker in the run.
     */
    public function generateAllStatements(int $payrollRunId): int
    {
        $workerIds = WorkerWeeklyPayroll::where('payroll_run_id', $payrollRunId)
            ->pluck('worker_id');

        foreach ($workerIds as $workerId) {
            GenerateStatementsJob::dispatch($workerId, $payrollRunId)
                ->onQueue('statements');
        }

        return $workerIds->count();
    }

    /**
     * Send the compiled statement to the worker via WhatsApp (SMS fallback).
     * Updates whatsapp_sent, whatsapp_sent_at, whatsapp_status on success.
     */
    public function sendWhatsApp(int $workerStatementId): void
    {
        $stmt   = WorkerStatement::with('worker:id,name,whatsapp')->findOrFail($workerStatementId);
        $worker = $stmt->worker;

        $phone = $worker?->whatsapp;
        if (empty($phone)) {
            $stmt->update(['whatsapp_status' => 'no_number']);
            return;
        }

        $message = $this->formatStatementText($stmt->statement_data);

        $sent = false;

        // ── 1. Attempt WhatsApp delivery ────────────────────────────────────
        $twilioSid = config('services.twilio.sid');
        if (! empty($twilioSid)) {
            $sent = $this->sendViaTwilio($phone, $message, whatsapp: true);
        } elseif (! empty(config('services.whatsapp.api_url'))) {
            $sent = $this->sendViaWhatsAppCloud($phone, $message);
        }

        // ── 2. SMS fallback ─────────────────────────────────────────────────
        if (! $sent && ! empty($twilioSid)) {
            Log::warning('WhatsApp delivery failed for worker '.$worker->id.'; falling back to SMS.');
            $sent = $this->sendViaTwilio($phone, $message, whatsapp: false);
        }

        $stmt->update([
            'whatsapp_sent'    => $sent,
            'whatsapp_sent_at' => $sent ? now() : null,
            'whatsapp_status'  => $sent ? 'sent' : 'failed',
        ]);

        if (! $sent) {
            throw new \RuntimeException("Message delivery failed for worker {$worker->id} ({$phone}).");
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Render the statement array as a plain-text WhatsApp/SMS message.
     * Uses *bold* markdown that WhatsApp renders natively.
     */
    private function formatStatementText(array $s): string
    {
        $w   = $s['worker'];
        $run = $s['payroll_run'];
        $e   = $s['earnings_summary'];
        $t   = $s['totals'];
        $pay = $s['payment'];

        $lines = [];
        $lines[] = "*Pieceworks Payslip* — {$run['week_ref']}";
        $lines[] = "{$run['start_date']} to {$run['end_date']}";
        $lines[] = '';
        $lines[] = "Worker: {$w['name']}";
        if ($w['cnic'])        $lines[] = "CNIC: {$w['cnic']}";
        if ($w['employee_id']) $lines[] = "ID: {$w['employee_id']}";
        if ($w['contractor'])  $lines[] = "Contractor: {$w['contractor']}";
        $lines[] = '';

        // Daily breakdown
        $lines[] = '*Daily Production*';
        foreach ($s['daily_breakdown'] as $day) {
            $line = date('D d-M', strtotime($day['date']))
                . ' | ' . strtoupper($day['shift'])
                . ' | ' . ($day['line']  ?? '-')
                . ' | ' . ($day['task']  ?? '-')
                . ' | ' . ($day['style'] ?? '-')
                . ' | ' . $day['pairs'] . ' pairs'
                . ' @ Rs ' . number_format($day['rate'], 2)
                . ' = Rs ' . number_format($day['earnings'], 2);

            if ($day['shift_adjustment'] ?? null) {
                $adj   = $day['shift_adjustment'];
                $sign  = $adj['amount'] >= 0 ? '+' : '';
                $line .= " _(shift adj: {$sign}" . number_format($adj['amount'], 2) . ')_';
            }
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '*Earnings*';
        $lines[] = 'Production:        Rs ' . number_format($e['gross_earnings'],      2);
        if ($e['ot_premium']          > 0) $lines[] = 'OT Premium:        Rs ' . number_format($e['ot_premium'],          2);
        if ($e['shift_allowance']     > 0) $lines[] = 'Shift Allowance:   Rs ' . number_format($e['shift_allowance'],     2);
        if ($e['holiday_pay']         > 0) $lines[] = 'Holiday Pay:       Rs ' . number_format($e['holiday_pay'],         2);
        if ($e['min_wage_supplement'] > 0) $lines[] = 'Min Wage Top-up:   Rs ' . number_format($e['min_wage_supplement'], 2);
        $lines[] = 'Total Gross:       Rs ' . number_format($e['total_gross'], 2);

        if (! empty($s['deductions'])) {
            $lines[] = '';
            $lines[] = '*Deductions*';
            foreach ($s['deductions'] as $ded) {
                $ref  = $ded['reference'] ? " (Ref: {$ded['reference']})" : '';
                $cfw  = $ded['carry_from_week'] ? " [carried from {$ded['carry_from_week']}]" : '';
                $lines[] = "{$ded['label']}{$ref}{$cfw}: -Rs " . number_format($ded['amount'], 2);
            }
        }

        if ($t['carry_forward_amount'] > 0) {
            $lines[] = '_Carry-forward to next week: Rs ' . number_format($t['carry_forward_amount'], 2) . '_';
        }

        $lines[] = '';
        $lines[] = '*NET PAY: Rs ' . number_format($t['net_pay'], 2) . '*';
        $lines[] = 'Payment via: ' . strtoupper($pay['method']);
        if ($pay['number']) $lines[] = 'Account/Mobile: ' . $pay['number'];

        if (!empty($s['dispute_window_closes_at'])) {
            $lines[] = '';
            $lines[] = '_Dispute window closes: ' . Carbon::parse($s['generated_at'])->addDays(self::DISPUTE_WINDOW_DAYS)->toDateString() . '_';
        }

        return implode("\n", $lines);
    }

    /**
     * Send via Twilio (WhatsApp or SMS).
     * Requires: TWILIO_SID, TWILIO_AUTH_TOKEN, TWILIO_PHONE_FROM in .env.
     * For WhatsApp: TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
     */
    private function sendViaTwilio(string $to, string $message, bool $whatsapp): bool
    {
        $sid   = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if ($whatsapp) {
            $from = config('services.twilio.whatsapp_from');
            $to   = 'whatsapp:' . $to;
        } else {
            $from = config('services.twilio.from');
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To'   => $to,
                    'Body' => $message,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Twilio send failed', ['error' => $e->getMessage(), 'to' => $to]);
            return false;
        }
    }

    /**
     * Send via WhatsApp Business Cloud API.
     * Requires: WHATSAPP_API_URL, WHATSAPP_API_TOKEN, WHATSAPP_PHONE_ID in .env.
     */
    private function sendViaWhatsAppCloud(string $to, string $message): bool
    {
        $apiUrl   = config('services.whatsapp.api_url');
        $token    = config('services.whatsapp.api_token');
        $phoneId  = config('services.whatsapp.phone_id');

        // Normalize to E.164 without leading +
        $to = ltrim(preg_replace('/\D/', '', $to), '0');
        if (strlen($to) === 10) {
            $to = '92' . $to; // Pakistan default
        }

        try {
            $response = Http::withToken($token)
                ->post("{$apiUrl}/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to,
                    'type'              => 'text',
                    'text'              => ['body' => $message],
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('WhatsApp Cloud API send failed', ['error' => $e->getMessage(), 'to' => $to]);
            return false;
        }
    }
}
