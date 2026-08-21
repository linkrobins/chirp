/**
 * "2d 4h" / "2h 13m" / "45m" — the coarse countdown both the schedule bar
 * and the list chip render. Null inside the final minute: that's "any
 * moment" territory, where a ticking number would overpromise precision.
 */
export default function formatCountdown(ms: number): string | null {
  if (ms <= 60000) return null;
  const mins = Math.floor(ms / 60000);
  const d = Math.floor(mins / 1440);
  const h = Math.floor((mins % 1440) / 60);
  const min = mins % 60;
  if (d > 0) return `${d}d ${h}h`;
  if (h > 0) return `${h}h ${min}m`;
  return `${min}m`;
}
