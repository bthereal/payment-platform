import { formatMoney } from '../lib/money';

interface StatCardProps {
  label: string;
  amount: number;
  hint?: string;
}

export function StatCard({ label, amount, hint }: StatCardProps) {
  const isNegative = amount < 0;

  return (
    <div className="flex min-w-[180px] flex-1 flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
      <div className="flex items-center gap-2">
        <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" />
        <span className="text-xs font-medium tracking-wide text-slate-500 uppercase dark:text-slate-400">{label}</span>
      </div>
      <span
        className={`text-2xl font-semibold ${isNegative ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white'}`}
      >
        {formatMoney(amount)}
      </span>
      {hint && <span className="text-xs text-slate-400 dark:text-slate-500">{hint}</span>}
    </div>
  );
}
