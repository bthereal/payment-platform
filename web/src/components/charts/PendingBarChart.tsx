import { useState } from 'react';
import type { PendingBucket } from '../../lib/dashboard';
import { formatMoney } from '../../lib/money';

interface PendingBarChartProps {
  buckets: readonly PendingBucket[];
}

const PLOT_HEIGHT = 176; // px — bars only, split proportionally above/below the zero baseline
const LABEL_ROW_HEIGHT = 18; // px — a fixed row per side, so every column's label sits on the same line rather than riding its own bar's tip
const BAR_WIDTH = 28; // px, under the 24px+ mark-spec cap for a column mark
const COLUMN_WIDTH = 84; // px — wider than the bar so its whitespace-nowrap value/date labels have room and don't bleed into the next column

/**
 * "Above/below a baseline" is the diverging-bar job (dataviz skill). One
 * shared scale for both directions — a bucket's height is honestly
 * proportional to its magnitude relative to every other bucket, positive or
 * negative, never a separately-scaled "the negative one is exaggerated so
 * you notice it." The color already does that job: blue↔red is the skill's
 * documented diverging pair (warm/cool opposites), reused here from the same
 * validated categorical slots (1 and 8) rather than a one-off red.
 *
 * Each side's value label lives in its own fixed-height row (above the
 * positive bar area, below the negative one) rather than riding the bar's
 * own tip, so labels line up on the same row across every column instead of
 * bobbing up and down with each bar's height — and the reserved row means a
 * near-maximum bar can never grow tall enough to collide with it.
 */
export function PendingBarChart({ buckets }: PendingBarChartProps) {
  const [hovered, setHovered] = useState<string | null>(null);

  if (buckets.length === 0) {
    return null;
  }

  const maxPositive = Math.max(0, ...buckets.map((b) => b.amount));
  const maxNegativeAbs = Math.max(0, ...buckets.map((b) => (b.amount < 0 ? Math.abs(b.amount) : 0)));
  const totalRange = maxPositive + maxNegativeAbs || 1;

  const aboveHeight = (maxPositive / totalRange) * PLOT_HEIGHT;
  const belowHeight = PLOT_HEIGHT - aboveHeight;

  return (
    <div className="overflow-x-auto">
      <div className="flex min-w-max gap-6 sm:gap-8">
        {buckets.map((bucket) => {
          const isNegative = bucket.amount < 0;
          const barHeight = Math.max(
            isNegative ? (Math.abs(bucket.amount) / totalRange) * PLOT_HEIGHT : (bucket.amount / totalRange) * PLOT_HEIGHT,
            2,
          );
          const isHovered = hovered === bucket.availableOn;

          return (
            <div key={bucket.availableOn} className="flex shrink-0 flex-col items-center" style={{ width: COLUMN_WIDTH }}>
              {/* Positive label row — same fixed height/position for every column. */}
              <div style={{ height: LABEL_ROW_HEIGHT }} className="flex w-full items-end justify-center">
                {!isNegative && (
                  <span
                    className={`text-center text-xs font-medium whitespace-nowrap text-slate-700 transition-opacity dark:text-slate-300 ${isHovered ? 'opacity-100' : 'opacity-80'}`}
                  >
                    {formatMoney(bucket.amount)}
                  </span>
                )}
              </div>

              {/* Above-zero bar area: bar anchored to the bottom (the zero line). */}
              <div style={{ height: aboveHeight }} className="flex w-full items-end justify-center">
                {!isNegative && (
                  <button
                    type="button"
                    onMouseEnter={() => setHovered(bucket.availableOn)}
                    onMouseLeave={() => setHovered(null)}
                    onFocus={() => setHovered(bucket.availableOn)}
                    onBlur={() => setHovered(null)}
                    aria-label={`${bucket.availableOn}: ${formatMoney(bucket.amount)} pending`}
                    style={{ height: barHeight, width: BAR_WIDTH }}
                    className={`rounded-t-md bg-[#2a78d6] outline-none transition-opacity dark:bg-[#3987e5] ${isHovered ? 'opacity-100' : 'opacity-90 hover:opacity-100'}`}
                  />
                )}
              </div>

              {/* Zero baseline — a solid hairline, never dashed. */}
              <div className="h-px w-full bg-slate-300 dark:bg-slate-700" />

              {/* Below-zero bar area: bar anchored to the top (the zero line). */}
              <div style={{ height: belowHeight }} className="flex w-full items-start justify-center">
                {isNegative && (
                  <button
                    type="button"
                    onMouseEnter={() => setHovered(bucket.availableOn)}
                    onMouseLeave={() => setHovered(null)}
                    onFocus={() => setHovered(bucket.availableOn)}
                    onBlur={() => setHovered(null)}
                    aria-label={`${bucket.availableOn}: ${formatMoney(bucket.amount)} — a refund issued after the payment was already paid out`}
                    style={{ height: barHeight, width: BAR_WIDTH }}
                    className={`rounded-b-md bg-[#e34948] outline-none transition-opacity dark:bg-[#e66767] ${isHovered ? 'opacity-100' : 'opacity-90 hover:opacity-100'}`}
                  />
                )}
              </div>

              {/* Negative label row — same fixed height/position for every column. */}
              <div style={{ height: LABEL_ROW_HEIGHT }} className="flex w-full items-start justify-center">
                {isNegative && (
                  <span
                    className={`text-center text-xs font-medium whitespace-nowrap text-rose-600 transition-opacity dark:text-rose-400 ${isHovered ? 'opacity-100' : 'opacity-80'}`}
                  >
                    {formatMoney(bucket.amount)}
                  </span>
                )}
              </div>

              <span className="mt-2 text-center text-xs whitespace-nowrap text-slate-500 dark:text-slate-400">
                {bucket.availableOn}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
