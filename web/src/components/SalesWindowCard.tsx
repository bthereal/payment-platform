import { useState } from 'react';
import type { SalesWindow } from '../lib/dashboard';
import { formatMoney } from '../lib/money';

type WindowKey = keyof SalesWindow;

const LABELS: Record<WindowKey, string> = {
  day: 'Last day',
  week: 'Last week',
  month: 'Last month',
};

interface SalesWindowCardProps {
  salesWindow: SalesWindow;
}

export function SalesWindowCard({ salesWindow }: SalesWindowCardProps) {
  const [selected, setSelected] = useState<WindowKey>('week');

  return (
    <div className="flex flex-col gap-5">
      <div className="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800" role="tablist">
        {(Object.keys(LABELS) as WindowKey[]).map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={selected === key}
            onClick={() => setSelected(key)}
            className={`flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition ${
              selected === key
                ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white'
                : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
            }`}
          >
            {LABELS[key]}
          </button>
        ))}
      </div>
      <div>
        <div className="text-3xl font-semibold text-slate-900 dark:text-white">{formatMoney(salesWindow[selected])}</div>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Gross sales made in the {LABELS[selected].toLowerCase()}
        </p>
      </div>
    </div>
  );
}
