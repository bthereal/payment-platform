import { useState } from 'react';
import type { FormEvent } from 'react';
import { login } from '../lib/auth';

interface LoginFormProps {
  onLoggedIn: () => void;
}

export function LoginForm({ onLoggedIn }: LoginFormProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    login(email, password)
      .then(onLoggedIn)
      .catch((err: unknown) => setError(err instanceof Error ? err.message : 'Login failed.'))
      .finally(() => setSubmitting(false));
  };

  return (
    <div className="flex min-h-screen flex-col lg:flex-row">
      {/* Branding panel — desktop only, this is the "flex model" split that
          collapses away on mobile rather than squeezing to fit. */}
      <div className="hidden flex-col justify-between bg-gradient-to-br from-brand-600 via-brand-700 to-slate-900 p-12 text-white lg:flex lg:w-1/2 xl:w-2/5">
        <span className="text-sm font-semibold tracking-wide text-brand-100 uppercase">Payments Dashboard</span>
        <div>
          <h1 className="text-4xl leading-tight font-semibold text-white">Payments in one place</h1>
          <p className="mt-4 max-w-sm text-brand-100">
            Gross sales, fees, payouts and what's still pending, without having to login into Stripe.
          </p>
        </div>
        <span className="text-xs text-brand-200">Everything with one login</span>
      </div>

      {/* Form panel — the typography treatment the request referred to:
          system-ui stack via body, generous line-height increased by 10px
          from the app-wide baseline (23px → 33px) for a more relaxed,
          confident feel on this one screen. */}
      <div className="flex flex-1 items-center justify-center p-6 sm:p-10">
        <form onSubmit={handleSubmit} className="w-full max-w-sm leading-[33px]">
          <h1 className="mb-1 text-2xl font-semibold text-slate-900 lg:hidden dark:text-white">Payments dashboard</h1>
          <p className="mb-8 text-sm text-slate-500 dark:text-slate-400">Login to see your dashboard.</p>

          <div className="flex flex-col gap-4">
            <label className="flex flex-col gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">
              Email
              <input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                autoComplete="username"
                required
                className="rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-base text-slate-900 shadow-sm outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-3 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-white"
              />
            </label>
            <label className="flex flex-col gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">
              Password
              <input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="current-password"
                required
                className="rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-base text-slate-900 shadow-sm outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-3 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-white"
              />
            </label>

            {error && <p className="text-sm text-rose-600 dark:text-rose-400">{error}</p>}

            <button
              type="submit"
              disabled={submitting}
              className="mt-2 inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 disabled:cursor-default disabled:opacity-60"
            >
              {submitting ? 'Logging in…' : 'Log in'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
