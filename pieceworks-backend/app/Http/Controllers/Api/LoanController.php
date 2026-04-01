<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function __construct(private LoanService $loanService) {}

    /**
     * POST /api/loans
     *
     * Create a new loan. Calculates total_weeks automatically.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id'   => ['required', 'integer', 'exists:workers,id'],
            'amount'      => ['required', 'numeric', 'min:1000', 'max:999999'],
            'weekly_emi'  => ['required', 'numeric', 'min:100'],
            'disbursed_by'=> ['nullable', 'integer', 'exists:users,id'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        // Prevent creating a new loan if one is already active
        $hasActive = Loan::where('worker_id', $data['worker_id'])
            ->where('status', 'active')
            ->exists();

        if ($hasActive) {
            return $this->error('Worker already has an active loan. Settle it before creating a new one.', 409);
        }

        if ((float) $data['weekly_emi'] > (float) $data['amount']) {
            return $this->error('Weekly EMI cannot exceed loan amount.', 422);
        }

        $totalWeeks = (int) ceil((float) $data['amount'] / (float) $data['weekly_emi']);

        $loan = Loan::create([
            'worker_id'           => $data['worker_id'],
            'amount'              => $data['amount'],
            'weekly_emi'          => $data['weekly_emi'],
            'disbursed_by'        => $data['disbursed_by'] ?? $request->user()->id,
            'outstanding_balance' => $data['amount'],
            'notes'               => $data['notes'] ?? null,
            'total_weeks'         => $totalWeeks,
            'disbursed_at'        => now()->toDateString(),
            'status'              => 'active',
        ]);

        return $this->created([
            'loan'        => $loan->load('worker:id,name,grade', 'disburser:id,name'),
            'total_weeks' => $totalWeeks,
        ], "Loan created. EMI of PKR {$data['weekly_emi']} over {$totalWeeks} weeks.");
    }

    /**
     * GET /api/loans?worker_id=&status=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'worker_id' => ['nullable', 'integer'],
            'status'    => ['nullable', 'in:active,fully_paid,written_off,cancelled'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Loan::with(['worker:id,name,grade', 'disburser:id,name'])
            ->orderByDesc('id');

        if ($workerId = $request->integer('worker_id')) {
            $query->where('worker_id', $workerId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $perPage   = $request->integer('per_page', 50);
        $paginated = $query->paginate($perPage);

        return $this->success([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ], 'Loans retrieved.');
    }

    /**
     * GET /api/loans/{id}
     *
     * Loan detail with full repayment schedule starting from current week.
     */
    public function show(int $id): JsonResponse
    {
        $loan = Loan::with(['worker:id,name,grade', 'disburser:id,name'])->findOrFail($id);

        $schedule = $loan->status === 'active'
            ? $this->loanService->getRepaymentSchedule($loan)
            : [];

        $paid       = round((float) $loan->amount - (float) $loan->outstanding_balance, 2);
        $paidPct    = (float) $loan->amount > 0
            ? round(($paid / (float) $loan->amount) * 100, 1)
            : 0;

        return $this->success([
            'loan'              => $loan,
            'paid_to_date'      => $paid,
            'paid_pct'          => $paidPct,
            'repayment_schedule'=> $schedule,
        ], 'Loan details retrieved.');
    }

    /**
     * POST /api/loans/{id}/early-settle
     *
     * Settle a loan (fully or partially) outside the regular payroll cycle.
     * If settle_amount >= outstanding_balance → loan marked fully_paid.
     */
    public function earlySettle(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'settle_amount' => ['required', 'numeric', 'min:1'],
        ]);

        $loan = Loan::findOrFail($id);

        if ($loan->status !== 'active') {
            return $this->error("Loan is already '{$loan->status}'.", 409);
        }

        $result = $this->loanService->earlySettle($loan, (float) $request->settle_amount);

        return $this->success([
            'loan'   => $loan->fresh(),
            'result' => $result,
        ], $result['fully_settled']
            ? 'Loan fully settled.'
            : "Partial settlement applied. Remaining balance: PKR {$result['new_balance']}."
        );
    }
}
