/**
 * API envelope types for the PieceWorks backend.
 *
 * These match the shapes emitted by the ApiResponse trait:
 *   success()   → ApiResponse<T>
 *   paginated() → PaginatedResponse<T>
 */

// ── Response envelopes ────────────────────────────────────────────────────────

/** Single-resource response from ApiResponse::success() or ::created() */
export interface ApiResponse<T> {
  status:  'success' | 'error';
  message: string;
  data:    T;
}

/** Paginated list response from ApiResponse::paginated() */
export interface PaginatedResponse<T> {
  data:  T[];
  meta:  PaginationMeta;
  links: PaginationLinks;
}

export interface PaginationMeta {
  current_page: number;
  last_page:    number;
  per_page:     number;
  total:        number;
  from:         number | null;
  to:           number | null;
}

export interface PaginationLinks {
  first: string | null;
  last:  string | null;
  prev:  string | null;
  next:  string | null;
}

// ── Validation errors ─────────────────────────────────────────────────────────

/** Field-level validation errors from HTTP 422 responses. */
export type ValidationErrors = Record<string, string[]>;

// ── Error ─────────────────────────────────────────────────────────────────────

/**
 * Typed error thrown by apiClient on non-2xx responses.
 * Re-exported from lib/api-client for use with `instanceof` checks.
 */
export type { ApiError } from '@/lib/api-client';

/**
 * Shape of a structured error body from the backend.
 * Useful when writing custom error handlers without importing the class.
 */
export interface ApiErrorBody {
  status:  'error';
  message: string;
  errors?: ValidationErrors;
}

// ── Utility helpers ───────────────────────────────────────────────────────────

/** Unwrap the data from an ApiResponse envelope. */
export type UnwrapApiResponse<T> = T extends ApiResponse<infer D> ? D : never;

/** Unwrap the row type from a PaginatedResponse envelope. */
export type UnwrapPaginated<T> = T extends PaginatedResponse<infer D> ? D : never;
