import { getToken } from './auth';
import type { DashboardSummary } from './dashboard';

// Never hardcoded — read from the environment so the same build can point at
// a different API without a code change. See vite.config.ts / .env.development.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? '';

export class UnauthorizedError extends Error {}

export async function fetchDashboardSummary(dateFrom?: string): Promise<DashboardSummary> {
  const token = getToken();
  if (!token) {
    throw new UnauthorizedError('Not logged in.');
  }

  const url = new URL('/api/dashboard', API_BASE_URL);
  if (dateFrom) {
    url.searchParams.set('date_from', dateFrom);
  }

  const response = await fetch(url, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (response.status === 401) {
    throw new UnauthorizedError('Session expired — please log in again.');
  }

  if (!response.ok) {
    const body: unknown = await response.json().catch(() => null);
    const message =
      body !== null && typeof body === 'object' && 'error' in body && typeof body.error === 'string'
        ? body.error
        : `Request failed with status ${response.status}`;
    throw new Error(message);
  }

  return (await response.json()) as DashboardSummary;
}
