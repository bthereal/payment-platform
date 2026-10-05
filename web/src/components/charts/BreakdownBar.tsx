import { useState } from 'react';
import { formatMoney } from '../../lib/money';

interface Segment {
  key: string;
  label: string;
  amount: number;
}

/**
 * Validated default categorical palette, first four slots (blue/orange/aqua/
 * yellow) — light/dark pairs. Written as literal Tailwind classes (not built
 * from interpolated hex) since Tailwind's build-time scanner needs the full
 * class string present verbatim in source to generate it.
 */
const SEGMENT_FILL: Record<string, string> = {
  net: 'bg-[#2a78d6] dark:bg-[#3987e5]',
  platform: 'bg-[#eb6834] dark:bg-[#d95926]',
  stripe: 'bg-[#1baf7a] dark:bg-[#199e70]',
  refunds: 'bg-[#eda100] dark:bg-[#c98500]',
};

const SEGMENT_DOT: Record<string, string> = {
  net: 'bg-[#2a78d6]',
  platform: 'bg-[#eb6834]',
  stripe: 'bg-[#1baf7a]',
  refunds: 'bg-[#eda100]',
};

interface BreakdownBarProps {
  grossSales: number;
  netEarned: number;
  platformFeeNet: number;
  stripeFee: number;
  refundsIssued: number;
}

/**
 * Part-to-whole job → stacked bar, not a pie (dataviz skill: "A donut/pie for
 * comparing close values" is an anti-pattern — length beats angle for close
 * magnitudes). Palette validated with the skill's own script
 * (`validate_palette.js "#2a78d6,#eb6834,#1baf7a,#eda100"`): all adjacent-CVD
 * and normal-vision checks pass; light-mode aqua/stripe and yellow/refunds
 * WARN on raw fill contrast, which is why every segment also gets a direct
 * legend label — the required "relief" — rather than relying on the fill
 * color alone.
 */
export function BreakdownBar({ grossSales, netEarned, platformFeeNet, stripeFee, refundsIssued }: BreakdownBarProps) {
  const [hovered, setHovered] = useState<string | null>(null);

  const segments: Segment[] = [
    { key: 'net', label: 'Net earned', amount: netEarned },
    { key: 'platform', label: 'Platform fee', amount: platformFeeNet },
    { key: 'stripe', label: 'Stripe fee', amount: stripeFee },
    { key: 'refunds', label: 'Refunds issued', amount: refundsIssued },
  ].filter((segment) => segment.amount > 0);

  if (grossSales <= 0) {
    return <p className="text-sm text-slate-500 dark:text-slate-400">No sales yet.</p>;
  }

  return (
    <div>
      {/* The bar: a single 24px-thick band, each segment separated by a 2px
          surface gap (never a border — see marks-and-anatomy.md). */}
      <div className="flex h-6 w-full gap-0.5 overflow-hidden rounded-md">
        {segments.map((segment, index) => {
          const pct = (segment.amount / grossSales) * 100;

          return (
            <div
              key={segment.key}
              tabIndex={0}
              role="img"
              aria-label={`${segment.label}: ${formatMoney(segment.amount)}, ${pct.toFixed(1)}% of gross sales`}
              onMouseEnter={() => setHovered(segment.key)}
              onMouseLeave={() => setHovered(null)}
              onFocus={() => setHovered(segment.key)}
              onBlur={() => setHovered(null)}
              className={`relative outline-none transition-opacity ${SEGMENT_FILL[segment.key]} ${
                index === 0 ? 'rounded-l-md' : ''
              } ${index === segments.length - 1 ? 'rounded-r-md' : ''} ${
                hovered !== null && hovered !== segment.key ? 'opacity-50' : 'opacity-100'
              }`}
              style={{ width: `${pct}%` }}
            >
              {hovered === segment.key && (
                <div className="absolute bottom-full left-1/2 z-10 mb-2 w-max -translate-x-1/2 rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs text-white shadow-lg dark:bg-slate-700">
                  <span className="font-semibold">{formatMoney(segment.amount)}</span>{' '}
                  <span className="text-slate-300">({pct.toFixed(1)}%)</span>
                  <div className="text-slate-300">{segment.label}</div>
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* Legend — always present for ≥2 series, and doubles as the direct
          value label every segment needs (dataviz skill: never make the
          reader rely on color-matching or a tooltip alone). */}
      <ul className="mt-4 flex flex-wrap gap-x-6 gap-y-2">
        {segments.map((segment) => (
          <li key={segment.key} className="flex items-center gap-2 text-sm">
            <span aria-hidden="true" className={`h-2.5 w-2.5 shrink-0 rounded-full ${SEGMENT_DOT[segment.key]}`} />
            <span className="text-slate-600 dark:text-slate-400">{segment.label}</span>
            <span className="font-medium text-slate-900 dark:text-white">{formatMoney(segment.amount)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
