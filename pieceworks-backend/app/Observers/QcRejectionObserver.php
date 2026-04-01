<?php

namespace App\Observers;

use App\Models\PayrollException;
use App\Models\QcRejection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * QcRejectionObserver handles domain-specific side-effects.
 * General audit trail (created/updated/deleted) is already covered
 * by AuditObserver, which is also registered on this model.
 *
 * This observer adds:
 *  - Dispute notification → PayrollException to alert QC supervisor.
 */
class QcRejectionObserver
{
    /**
     * When a rejection transitions to 'disputed', create a PayrollException
     * so the QC supervisor sees it in the unresolved exceptions queue.
     */
    public function updated(QcRejection $rejection): void
    {
        if (! $rejection->wasChanged('status')) {
            return;
        }

        if ($rejection->status === 'disputed') {
            PayrollException::create([
                'payroll_run_id'            => null,
                'worker_id'                 => $rejection->worker_id,
                'worker_weekly_payroll_id'  => null,
                'exception_type'            => 'manual',
                'description'               => sprintf(
                    'QC Rejection #%d disputed by worker/contractor on %s. Defect: %s. Pairs rejected: %d. Reason: %s',
                    $rejection->id,
                    now()->toDateString(),
                    $rejection->defect_type ?? 'unspecified',
                    $rejection->pairs_rejected,
                    $rejection->dispute_reason ?? '(none provided)'
                ),
                'amount'          => $rejection->penalty_amount,
                'resolved_at'     => null,
                'resolved_by'     => null,
                'resolution_note' => null,
            ]);
        }

        if ($rejection->status === 'reversed') {
            // Log reversal as a note in audit_logs (AuditObserver handles the row-level diff,
            // but we add an explicit action entry for the financial reversal).
            DB::table('audit_logs')->insert([
                'user_id'    => \Auth::id(),
                'action'     => 'qc_rejection_reversed',
                'model_type' => QcRejection::class,
                'model_id'   => $rejection->id,
                'old_values' => json_encode(['status' => 'disputed', 'credit_created' => false]),
                'new_values' => json_encode([
                    'status'         => 'reversed',
                    'resolution'     => $rejection->resolution,
                    'credit_created' => $rejection->credit_created,
                ]),
                'ip_address' => Request::ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
