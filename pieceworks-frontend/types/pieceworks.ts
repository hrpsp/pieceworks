/**
 * Canonical domain types for the PieceWorks HRMS.
 *
 * These are the authoritative type definitions for all backend models.
 * Hook files may re-export a subset of these where needed.
 */

// ── Primitive union types ─────────────────────────────────────────────────────

export type ShiftType       = 'morning' | 'evening' | 'night';
export type PaymentMethod   = 'cash' | 'bank' | 'easypaisa' | 'jazzcash';
export type ValidationStatus= 'pending' | 'validated' | 'disputed' | 'rejected';
export type ProductionSource= 'bata_api' | 'manual_supervisor' | 'manual_backfill';

// ── Worker ────────────────────────────────────────────────────────────────────

export type WorkerGrade =
  | 'junior'
  | 'standard'
  | 'senior'
  | 'master';

export type WorkerType =
  | 'bata_direct'
  | 'contractor'
  | 'seasonal'
  | 'trainee';

export type WorkerStatus =
  | 'active'
  | 'inactive'
  | 'terminated'
  | 'seasonal_off';

export interface Worker {
  id:                        number;
  contractor_id:             number | null;
  name:                      string;
  cnic:                      string;
  photo_path:                string | null;
  biometric_id:              string | null;
  worker_type:               WorkerType;
  grade:                     WorkerGrade;
  default_shift:             ShiftType;
  default_line_id:           number | null;
  training_period:           number;
  training_end_date:         string | null;   // ISO date
  payment_method:            PaymentMethod;
  payment_number:            string | null;
  whatsapp:                  string | null;
  eobi_number:               string | null;
  pessi_number:              string | null;
  join_date:                 string;          // ISO date
  status:                    WorkerStatus;
  created_at:                string;
  updated_at:                string;
  deleted_at:                string | null;
  // Computed by backend
  is_in_training?:           boolean;
  // Eager-loaded relations
  contractor?:               Pick<Contractor, 'id' | 'name'>;
}

// ── Contractor ────────────────────────────────────────────────────────────────

export type ContractorStatus = 'active' | 'suspended' | 'terminated';

export interface Contractor {
  id:              number;
  name:            string;
  contact_name:    string | null;
  contact_phone:   string | null;
  contact_email:   string | null;
  address:         string | null;
  ntn:             string | null;
  portal_access:   boolean;
  status:          ContractorStatus;
  created_at:      string;
  updated_at:      string;
}

// ── Rate Card ─────────────────────────────────────────────────────────────────

export type ComplexityTier = 'basic' | 'standard' | 'premium';

export interface RateCardEntry {
  id:            number;
  rate_card_id:  number;
  grade:         WorkerGrade;
  tier:          ComplexityTier;
  task:          string;
  rate_per_pair: string;           // decimal string from Laravel
  effective_from: string;         // ISO date
}

export interface RateCard {
  id:              number;
  name:            string;
  description:     string | null;
  is_active:       boolean;
  activated_at:    string | null;
  activated_by:    number | null;
  created_at:      string;
  updated_at:      string;
  entries?:        RateCardEntry[];
}

// ── Production ────────────────────────────────────────────────────────────────

export interface ProductionRecord {
  id:                        number;
  worker_id:                 number;
  line_id:                   number;
  rate_card_entry_id:        number | null;
  work_date:                 string;          // ISO date
  shift:                     ShiftType;
  style_sku_id:              number | null;
  task:                      string;
  pairs_produced:            number;
  rate_amount:               string;          // decimal
  gross_earnings:            string;          // decimal
  source_tag:                ProductionSource;
  shift_adjustment:          string;          // decimal
  shift_adj_authorized_by:   number | null;
  shift_adj_reason:          string | null;
  supervisor_notes:          string | null;
  validation_status:         ValidationStatus;
  is_locked:                 boolean;
  ghost_risk_level:          string | null;
  ghost_flagged_at:          string | null;
  billing_contractor_id:     number | null;
  created_at:                string;
  updated_at:                string;
  // Relations
  worker?:  Pick<Worker, 'id' | 'name' | 'grade'>;
  line?:    { id: number; name: string };
}

// ── Attendance ────────────────────────────────────────────────────────────────

export type AttendanceStatus =
  | 'present'
  | 'absent'
  | 'half_day'
  | 'on_leave'
  | 'holiday';

export interface AttendanceRecord {
  id:               number;
  worker_id:        number;
  work_date:        string;          // ISO date
  shift:            ShiftType;
  status:           AttendanceStatus;
  biometric_in:     string | null;   // ISO datetime
  biometric_out:    string | null;
  hours_worked:     number | null;
  overtime_hours:   number | null;
  notes:            string | null;
  created_at:       string;
  updated_at:       string;
}

// ── Shift Adjustment ──────────────────────────────────────────────────────────

export type ShiftAdjustmentReason =
  | 'line_shortage'
  | 'skill_requirement'
  | 'worker_request';

export interface ShiftAdjustment {
  id:                         number;
  production_record_id:       number | null;
  worker_id:                  number;
  work_date:                  string;
  scheduled_shift:            ShiftType;
  actual_shift:               ShiftType;
  line_id:                    number | null;
  hours_gap_from_last_shift:  number | null;
  overtime_flagged:           boolean;
  authorized_by:              number | null;
  reason:                     ShiftAdjustmentReason;
  reason_text:                string | null;
  confirmed_at:               string | null;
  created_at:                 string;
  updated_at:                 string;
  // Relations
  worker?:     Pick<Worker, 'id' | 'name'>;
  authorizer?: { id: number; name: string } | null;
}

// ── Payroll ───────────────────────────────────────────────────────────────────

export type PayrollStatus =
  | 'open'
  | 'processing'
  | 'locked'
  | 'paid'
  | 'reversed';

export type PaymentStatus =
  | 'pending'
  | 'processing'
  | 'paid'
  | 'failed'
  | 'reversed';

export type ExceptionType =
  | 'min_wage_shortfall'
  | 'missing_rate'
  | 'negative_net_carry'
  | 'disputed_records'
  | 'manual'
  | 'wht_alert'
  | 'tenure_milestone'
  | 'compliance_gap'
  | 'dispute';

export interface WeeklyPayrollRun {
  id:                number;
  week_ref:          string;          // 'YYYY-W##'
  start_date:        string;
  end_date:          string;
  status:            PayrollStatus;
  total_gross:       string;
  total_topups:      string;
  total_deductions:  string;
  total_net:         string;
  locked_at:         string | null;
  locked_by:         number | null;
  released_at:       string | null;
  released_by:       number | null;
  created_at:        string;
  updated_at:        string;
  // Eager-loaded counts
  worker_payrolls_count?:          number;
  exceptions_count?:               number;
  unresolved_exceptions_count?:    number;
  // Eager-loaded relations
  locker?:   { id: number; name: string } | null;
  releaser?: { id: number; name: string } | null;
}

export interface PayrollException {
  id:                       number;
  payroll_run_id:           number;
  worker_id:                number;
  worker_weekly_payroll_id: number | null;
  exception_type:           ExceptionType;
  description:              string;
  amount:                   string | null;
  resolved_at:              string | null;
  resolved_by:              number | null;
  resolution_note:          string | null;
  created_at:               string;
  updated_at:               string;
  // Relations
  worker?:   Pick<Worker, 'id' | 'name' | 'grade'>;
  resolver?: { id: number; name: string } | null;
}

export interface WorkerWeeklyPayroll {
  id:                    number;
  payroll_run_id:        number;
  worker_id:             number;
  contractor_id:         number | null;
  gross_earnings:        string;
  ot_premium:            string;
  shift_allowance:       string;
  holiday_pay:           string;
  min_wage_supplement:   string;
  total_gross:           string;
  advance_deductions:    string;
  rejection_deductions:  string;
  loan_deductions:       string;
  other_deductions:      string;
  carry_forward_amount:  string;
  net_pay:               string;
  payment_method:        PaymentMethod;
  payment_status:        PaymentStatus;
  created_at:            string;
  updated_at:            string;
  // Relations
  worker?:  Pick<Worker, 'id' | 'name' | 'grade' | 'cnic' | 'contractor_id'> & {
    contractor?: Pick<Contractor, 'id' | 'name'>;
  };
}

// ── Advance ───────────────────────────────────────────────────────────────────

export type AdvanceStatus = 'pending' | 'approved' | 'deducted' | 'cancelled';

export interface Advance {
  id:              number;
  worker_id:       number;
  payroll_run_id:  number | null;
  amount:          string;
  reason:          string | null;
  status:          AdvanceStatus;
  approved_by:     number | null;
  approved_at:     string | null;
  deducted_at:     string | null;
  created_at:      string;
  updated_at:      string;
  // Relations
  worker?:   Pick<Worker, 'id' | 'name'>;
  approver?: { id: number; name: string } | null;
}

// ── Loan ──────────────────────────────────────────────────────────────────────

export type LoanStatus = 'active' | 'settled' | 'defaulted' | 'cancelled';

export interface Loan {
  id:                  number;
  worker_id:           number;
  principal_amount:    string;
  outstanding_balance: string;
  weekly_installment:  string;
  total_paid:          string;
  status:              LoanStatus;
  disbursed_at:        string | null;
  settled_at:          string | null;
  notes:               string | null;
  created_at:          string;
  updated_at:          string;
  worker?:             Pick<Worker, 'id' | 'name'>;
}

// ── Deduction ─────────────────────────────────────────────────────────────────

export type DeductionKind =
  | 'advance_recovery'
  | 'loan_installment'
  | 'rejection_penalty'
  | 'reversal_recovery'
  | 'tax_withholding'
  | 'other';

export interface DeductionType {
  id:          number;
  slug:        DeductionKind;
  label:       string;
  is_system:   boolean;
}

export interface Deduction {
  id:                 number;
  worker_id:          number;
  payroll_run_id:     number | null;
  deduction_type_id:  number;
  amount:             string;
  reference_id:       number | null;
  reference_type:     string | null;
  week_ref:           string;
  carry_from_week:    string | null;
  status:             'pending' | 'applied' | 'cancelled';
  created_at:         string;
  updated_at:         string;
}

// ── QC Rejection ──────────────────────────────────────────────────────────────

export type RejectionStatus =
  | 'pending'
  | 'confirmed'
  | 'disputed'
  | 'resolved'
  | 'waived';

export type PenaltyType =
  | 'deduction_from_earnings'
  | 'pieces_only';

export interface QCRejection {
  id:                    number;
  production_record_id:  number;
  worker_id:             number;
  work_date:             string;
  pairs_rejected:        number;
  defect_type:           string | null;
  penalty_mode:          string | null;
  penalty_type:          PenaltyType | null;
  penalty_amount:        string;
  pairs_deducted:        number;
  status:                RejectionStatus;
  disputed_at:           string | null;
  disputed_by:           number | null;
  dispute_reason:        string | null;
  resolved_by:           number | null;
  resolution:            string | null;
  resolution_notes:      string | null;
  resolved_at:           string | null;
  credit_created:        boolean;
  created_at:            string;
  updated_at:            string;
  // Relations
  worker?:           Pick<Worker, 'id' | 'name'>;
  productionRecord?: Pick<ProductionRecord, 'id' | 'line_id' | 'style_sku_id'>;
}

// ── Worker Statement ──────────────────────────────────────────────────────────

export interface WorkerDayBreakdown {
  date:      string;          // ISO date
  shift:     ShiftType;
  pairs:     number;
  rate:      number;
  earnings:  number;
  line:      string;
  tasks:     string[];
}

export interface WorkerStatement {
  id:                number;
  worker_id:         number;
  payroll_run_id:    number;
  week_ref:          string;
  statement_data: {
    daily_breakdown:   WorkerDayBreakdown[];
    earnings_summary: {
      gross_piece_earnings:  number;
      min_wage_supplement:   number;
      ot_premium:            number;
      shift_allowance:       number;
      holiday_pay:           number;
      total_gross:           number;
    };
    deduction_lines: Array<{
      label:  string;
      amount: number;
    }>;
    totals: {
      total_gross:      number;
      total_deductions: number;
      net_pay:          number;
    };
  };
  whatsapp_sent:     boolean;
  sent_at:           string | null;
  dispute_deadline:  string;
  status:            'generated' | 'sent' | 'disputed' | 'finalized';
  created_at:        string;
  updated_at:        string;
  worker?:           Pick<Worker, 'id' | 'name' | 'cnic' | 'whatsapp'>;
}

// ── Contractor Settlement ─────────────────────────────────────────────────────

export interface ContractorSettlement {
  id:               number;
  contractor_id:    number;
  payroll_run_id:   number;
  week_ref:         string;
  bata_owes:        string;
  workers_paid:     string;
  margin:           string;
  settlement_date:  string | null;
  notes:            string | null;
  created_at:       string;
  updated_at:       string;
  contractor?:      Pick<Contractor, 'id' | 'name'>;
}

// ── Bata Integration ──────────────────────────────────────────────────────────

export type SyncRunStatus = 'idle' | 'syncing' | 'success' | 'failed';
export type StagingStatus  = 'pending' | 'accepted' | 'held' | 'conflicted' | 'manual_entered';

export interface BataApiSyncStatus {
  last_sync_at:             string | null;
  last_success_at:          string | null;
  status:                   SyncRunStatus;
  records_pulled:           number;
  records_mapped:           number;
  records_pending:          number;
  consecutive_failures:     number;
  next_scheduled_at:        string | null;
}

export interface StagingRecord {
  id:                       number;
  bata_worker_id:           string;
  pieceworks_worker_id:     number | null;
  work_date:                string;
  pairs_produced:           number;
  style_code:               string;
  sku_code:                 string | null;
  source_shift:             ShiftType | null;
  status:                   StagingStatus;
  conflict_reason:          string | null;
  accepted_at:              string | null;
  accepted_by:              number | null;
  created_at:               string;
  updated_at:               string;
  worker?:                  Pick<Worker, 'id' | 'name' | 'cnic'> | null;
}

// ── Compliance ────────────────────────────────────────────────────────────────

export interface ComplianceRecord {
  id:                   number;
  worker_id:            number;
  eobi_number:          string | null;
  pessi_number:         string | null;
  eobi_registered_at:   string | null;  // ISO date
  pessi_registered_at:  string | null;
  ntn_number:           string | null;
  tax_status:           'filer' | 'non_filer' | 'exempt' | null;
  wht_applicable:       boolean;
  created_at:           string;
  updated_at:           string;
  worker?:              Pick<Worker, 'id' | 'name' | 'cnic'>;
}

export interface TenureMilestone {
  id:            number;
  worker_id:     number;
  milestone_days: 90 | 365 | 1095 | 1825;
  reached_at:    string;    // ISO date
  alerted:       boolean;
  action_taken:  string | null;
  created_at:    string;
  updated_at:    string;
  worker?:       Pick<Worker, 'id' | 'name' | 'join_date'>;
}

// ── RBAC ──────────────────────────────────────────────────────────────────────

export interface Permission {
  slug:   string;
  label:  string;
  group:  string;
}

export interface Role {
  id:           number;
  name:         string;
  slug:         string;
  permissions:  string[];   // array of permission slugs
  created_at:   string;
  updated_at:   string;
}
