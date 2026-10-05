// Session-only on purpose — a lost tab shouldn't leave a JWT sitting around
// indefinitely for a dashboard this simple. Re-login is one form submit away.
const TOKEN_KEY = 'payments_dashboard_token';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? '';

export class InvalidCredentialsError extends Error {}

export async function login(email: string, password: string): Promise<void> {
  const response = await fetch(new URL('/api/login', API_BASE_URL), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });

  if (!response.ok) {
    throw new InvalidCredentialsError('Incorrect email or password.');
  }

  const body = (await response.json()) as { token: string };
  sessionStorage.setItem(TOKEN_KEY, body.token);
}

export function logout(): void {
  sessionStorage.removeItem(TOKEN_KEY);
}

export function getToken(): string | null {
  return sessionStorage.getItem(TOKEN_KEY);
}
