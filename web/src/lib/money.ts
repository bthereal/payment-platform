// Amounts throughout the API are integer minor units (pence) — only ever
// convert to major units here, at the presentation boundary.
export function formatMoney(minorUnits: number, currency = 'GBP'): string {
  return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(minorUnits / 100);
}
