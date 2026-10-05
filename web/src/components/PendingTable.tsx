import type { PendingBucket } from '../lib/dashboard';
import { formatMoney } from '../lib/money';

interface PendingTableProps {
  buckets: readonly PendingBucket[];
}

export function PendingTable({ buckets }: PendingTableProps) {
  if (buckets.length === 0) {
    return (
      <p className="text-sm text-slate-500 dark:text-slate-400">
        Nothing outstanding — everything available has been paid out.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b border-slate-200 dark:border-slate-800">
            <th className="pb-2 pr-3 font-medium text-slate-500 dark:text-slate-400">Available on</th>
            <th className="pb-2 pr-3 font-medium text-slate-500 dark:text-slate-400">Amount</th>
            <th className="pb-2 font-medium text-slate-500 dark:text-slate-400"></th>
          </tr>
        </thead>
        <tbody>
          {buckets.map((bucket) => {
            const negative = bucket.amount < 0;

            return (
              <tr key={bucket.availableOn} className="border-b border-slate-100 last:border-none dark:border-slate-800/60">
                <td className="py-2.5 pr-3 whitespace-nowrap text-slate-700 dark:text-slate-300">{bucket.availableOn}</td>
                <td
                  className={`py-2.5 pr-3 whitespace-nowrap font-medium ${negative ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white'}`}
                >
                  {formatMoney(bucket.amount)}
                </td>
                <td className="py-2.5 text-xs text-rose-600 dark:text-rose-400">
                  {negative ? 'Refund issued after payout - will be deducted in the next payout window.' : ''}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
