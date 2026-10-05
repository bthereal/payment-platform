import { useEffect, useState } from 'react';
import { fetchDashboardSummary, UnauthorizedError } from '../lib/apiClient';
import type { DashboardSummary } from '../lib/dashboard';
import { formatMoney } from '../lib/money';
import { logout } from '../lib/auth';
import { BreakdownBar } from './charts/BreakdownBar';
import { PendingBarChart } from './charts/PendingBarChart';
import { PendingTable } from './PendingTable';
import { SalesWindowCard } from './SalesWindowCard';
import { StatCard } from './StatCard';

interface DashboardProps {
  onSessionExpired: () => void;
}

export function Dashboard({ onSessionExpired }: DashboardProps) {
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    fetchDashboardSummary()
      .then((data) => {
        if (!cancelled) setSummary(data);
      })
      .catch((err: unknown) => {
        if (cancelled) return;

        if (err instanceof UnauthorizedError) {
          onSessionExpired();
          return;
        }

        setError(err instanceof Error ? err.message : 'Failed to load the dashboard.');
      });

    return () => {
      cancelled = true;
    };
  }, [onSessionExpired]);

  if (error) {
    return (
      <main className="flex min-h-screen items-center justify-center p-6 text-center">
        <p className="text-slate-600 dark:text-slate-300">Couldn't load the dashboard: {error}</p>
      </main>
    );
  }

  if (!summary) {
    return (
      <main className="flex min-h-screen items-center justify-center">
        <p className="text-sm text-slate-500 dark:text-slate-400">Loading…</p>
      </main>
    );
  }

  return (
    <main className="mx-auto flex min-h-screen max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-2xl">Payments dashboard</h1>
        <div className="flex items-center gap-4">
          <span className="text-sm text-slate-500 dark:text-slate-400">From {summary.dateFrom}</span>
          <button
            type="button"
            onClick={() => {
              logout();
              onSessionExpired();
            }}
            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
          >
            Log out
          </button>
        </div>
      </header>

      {/* Flex-wrap, not a grid — cards reflow from a single mobile column
          up to a full desktop row on their own as space allows. */}
      <section className="flex flex-wrap gap-4">
        <StatCard label="Gross sales" amount={summary.grossSales} />
        <StatCard
          label="Platform fee"
          amount={summary.platformFeeNet}
          hint={`${formatMoney(summary.platformFeeTaken)} taken, ${formatMoney(summary.platformFeeReversed)} returned on refunds`}
        />
        <StatCard label="Stripe fee" amount={summary.stripeFee} />
        <StatCard label="Refunds issued" amount={summary.refundsIssued} />
        <StatCard label="Disputes issued" amount={summary.disputesIssued} />
        <StatCard label="Net earned" amount={summary.netEarned} />
        <StatCard
          label="Paid out to date"
          amount={summary.paidOutToDate}
          hint="Already sent to your bank — see Pending below for what's still on its way"
        />
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 className="mb-1 text-lg">Where your sales went</h2>
        <p className="mb-5 text-sm text-slate-500 dark:text-slate-400">
          Every pound of gross sales, split into what you keep and the tech that powers your sales.
        </p>
        <BreakdownBar
          grossSales={summary.grossSales}
          netEarned={summary.netEarned}
          platformFeeNet={summary.platformFeeNet}
          stripeFee={summary.stripeFee}
          refundsIssued={summary.refundsIssued}
        />
      </section>

      {/* Stacks on mobile, sits side by side from lg up — same flex model. */}
      <section className="flex flex-col gap-6 lg:flex-row">
        <div className="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h2 className="mb-4 text-lg">How much have we made?</h2>
          <SalesWindowCard salesWindow={summary.salesWindow} />
        </div>

        <div className="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-lg">Pending</h2>
          <p className="mt-1 mb-4 text-sm text-slate-500 dark:text-slate-400">
            Money not yet paid out. These funds are in a pending state until it becomes available. A negative value means refunds were issued after
            a payment was already paid out, they will be deducted from a future payout.
          </p>
          <PendingBarChart buckets={summary.pending} />
          <div className="mt-5">
            <PendingTable buckets={summary.pending} />
          </div>
        </div>
      </section>
    </main>
  );
}
