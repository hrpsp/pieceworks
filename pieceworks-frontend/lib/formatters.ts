/**
 * Display formatters for the PieceWorks HRMS.
 *
 * All functions are pure (no side-effects, no imports from React).
 * Safe to call from both client and server components.
 */

// ── Currency ──────────────────────────────────────────────────────────────────

/**
 * Format a number as Pakistani Rupees using South Asian lakh notation.
 *
 * @example
 * formatPKR(346080)  → "PKR 3,46,080"
 * formatPKR(37000)   → "PKR 37,000"
 * formatPKR(100)     → "PKR 100"
 * formatPKR(-1500)   → "-PKR 1,500"
 */
export function formatPKR(amount: number): string {
  const n    = Math.round(Math.abs(amount));
  const sign = amount < 0 ? '-' : '';
  const str  = String(n);

  if (str.length <= 3) {
    return `${sign}PKR ${str}`;
  }

  const last3  = str.slice(-3);
  const rest   = str.slice(0, -3);

  // Split remaining digits into groups of 2 from the right (lakh system)
  const groups: string[] = [];
  let i = rest.length;
  while (i > 0) {
    const start = Math.max(0, i - 2);
    groups.unshift(rest.slice(start, i));
    i = start;
  }

  return `${sign}PKR ${groups.join(',')},${last3}`;
}

// ── Identity ──────────────────────────────────────────────────────────────────

/**
 * Format a raw 13-digit CNIC string as the official dashed format.
 *
 * @example
 * formatCNIC('3520212345671')   → "35202-1234567-1"
 * formatCNIC('35202-1234567-1') → "35202-1234567-1"  (already formatted)
 */
export function formatCNIC(cnic: string): string {
  const digits = cnic.replace(/\D/g, '');
  if (digits.length !== 13) return cnic;          // not a valid CNIC — return as-is
  return `${digits.slice(0, 5)}-${digits.slice(5, 12)}-${digits.slice(12)}`;
}

/**
 * Format a Pakistani mobile number as the 0XXX-XXXXXXX pattern.
 *
 * Accepts 11-digit numbers (with leading 0) or 10-digit (without).
 *
 * @example
 * formatPhone('03121234567')  → "0312-1234567"
 * formatPhone('+923121234567')→ "0312-1234567"
 */
export function formatPhone(phone: string): string {
  const digits = phone.replace(/\D/g, '');

  if (digits.length === 12 && digits.startsWith('92')) {
    // +92 country code prefix
    return `0${digits.slice(2, 5)}-${digits.slice(5)}`;
  }
  if (digits.length === 11 && digits.startsWith('0')) {
    return `${digits.slice(0, 4)}-${digits.slice(4)}`;
  }
  if (digits.length === 10) {
    return `0${digits.slice(0, 3)}-${digits.slice(3)}`;
  }
  return phone;
}

// ── Week / Date ───────────────────────────────────────────────────────────────

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
] as const;

/**
 * Format a Date into a human-readable ISO week label.
 *
 * Uses ISO 8601 week numbering — the year belongs to the week
 * containing the Thursday of that week.
 *
 * @example
 * formatWeekRef(new Date('2026-02-16')) → "Week 8, February 2026"
 */
export function formatWeekRef(date: Date): string {
  // Find the Thursday of the same ISO week to determine year/month
  const thursday = new Date(date);
  const dayOfWeek = date.getDay() || 7;            // Mon=1 … Sun=7
  thursday.setDate(date.getDate() + (4 - dayOfWeek));

  // ISO week number
  const jan4    = new Date(thursday.getFullYear(), 0, 4);
  const jan4Day = jan4.getDay() || 7;
  const week1Mon = new Date(jan4);
  week1Mon.setDate(jan4.getDate() - jan4Day + 1);

  const diffMs      = thursday.getTime() - week1Mon.getTime();
  const weekNumber  = Math.floor(diffMs / (7 * 24 * 60 * 60 * 1000)) + 1;
  const monthName   = MONTHS[thursday.getMonth()];
  const year        = thursday.getFullYear();

  return `Week ${weekNumber}, ${monthName} ${year}`;
}

/**
 * Return the Monday–Saturday date range for an ISO week reference.
 *
 * @param weekRef  e.g. "2026-W08"
 * @returns { start: Date (Monday 00:00), end: Date (Saturday 23:59:59) }
 *
 * @example
 * getWeekDates('2026-W08') → { start: Mon 16 Feb 2026, end: Sat 21 Feb 2026 }
 */
export function getWeekDates(weekRef: string): { start: Date; end: Date } {
  const [yearStr, weekStr] = weekRef.split('-W');
  const year = parseInt(yearStr, 10);
  const week = parseInt(weekStr, 10);

  // ISO week 1 starts on the Monday that contains Jan 4
  const jan4    = new Date(year, 0, 4);
  const jan4Day = jan4.getDay() || 7;              // Mon=1 … Sun=7
  const week1Mon = new Date(jan4);
  week1Mon.setDate(jan4.getDate() - jan4Day + 1);

  // Monday of the target week
  const start = new Date(week1Mon);
  start.setDate(week1Mon.getDate() + (week - 1) * 7);
  start.setHours(0, 0, 0, 0);

  // Saturday (6-day factory week)
  const end = new Date(start);
  end.setDate(start.getDate() + 5);
  end.setHours(23, 59, 59, 999);

  return { start, end };
}

// ── Production ────────────────────────────────────────────────────────────────

/**
 * Format a piece-rate calculation as a human-readable string.
 *
 * @example
 * formatPairsRate(88, 35) → "88 pairs × PKR 35 = PKR 3,080"
 */
export function formatPairsRate(pairs: number, ratePerPair: number): string {
  const earnings = pairs * ratePerPair;
  return `${pairs} pairs × PKR ${ratePerPair} = ${formatPKR(earnings)}`;
}

// ── Minimum Wage ──────────────────────────────────────────────────────────────

/** Monthly minimum wages by province (PKR, FY 2024-25). */
const MIN_WAGE_MONTHLY: Record<string, number> = {
  punjab:      37_000,
  sindh:       37_000,
  kpk:         36_000,
  balochistan: 32_000,
  federal:     37_000,
};

/**
 * Check whether a worker's weekly earnings meet the provincial minimum wage.
 *
 * Converts monthly minimum to weekly: monthly × 12 ÷ 52
 *
 * @param weeklyEarnings  Net piece earnings for the week (PKR)
 * @param province        Province slug (default: 'punjab')
 */
export function isAboveMinWage(
  weeklyEarnings: number,
  province = 'punjab'
): boolean {
  const monthly = MIN_WAGE_MONTHLY[province.toLowerCase()] ?? 37_000;
  const weekly  = (monthly * 12) / 52;
  return weeklyEarnings >= weekly;
}

// ── Name ──────────────────────────────────────────────────────────────────────

/**
 * Extract initials from a full name (first letter of first + last word).
 *
 * @example
 * getInitials('Ahmed Ali Khan') → "AK"
 * getInitials('Farida')        → "F"
 */
export function getInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0][0].toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
