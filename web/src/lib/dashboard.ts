// Mirrors api/src/Dto/DashboardSummary.php and friends. All amounts are
// integer minor units (pence), matching the API throughout. Readonly to
// match the backend DTOs (all `final readonly class`) — these are read-only
// API responses, never built or mutated on this side.

export interface PendingBucket {
  readonly availableOn: string;
  readonly amount: number;
}

export interface SalesWindow {
  readonly day: number;
  readonly week: number;
  readonly month: number;
}

export interface DashboardSummary {
  readonly dateFrom: string;
  readonly grossSales: number;
  readonly platformFeeTaken: number;
  readonly platformFeeReversed: number;
  readonly platformFeeNet: number;
  readonly stripeFee: number;
  readonly refundsIssued: number;
  readonly disputesIssued: number;
  readonly netEarned: number;
  readonly paidOutToDate: number;
  readonly pending: readonly PendingBucket[];
  readonly salesWindow: SalesWindow;
}
