import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}

/** Formats an integer with thousands separators; "—" when unknown. */
export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  return new Intl.NumberFormat().format(value);
}

/**
 * Formats a rate. Null means "not calculable" (no denominator) and must render
 * as a dash, never as 0% — the difference matters on a deliverability screen.
 */
export function formatPercent(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  return `${value.toFixed(digits)}%`;
}

export function formatBytes(bytes: number): string {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  return `${(bytes / 1024 ** index).toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
}

/** The API returns UTC "Y-m-d H:i:s"; parse it as UTC, not as local time. */
export function parseUtc(value: string | null | undefined): Date | null {
  if (!value) return null;
  const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value: string | null | undefined, timeZone?: string): string {
  const date = parseUtc(value);
  if (!date) return '—';
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone,
  }).format(date);
}

export function formatDate(value: string | null | undefined, timeZone?: string): string {
  const date = parseUtc(value);
  if (!date) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone }).format(date);
}

export function formatRelative(value: string | null | undefined): string {
  const date = parseUtc(value);
  if (!date) return '—';

  const seconds = Math.round((date.getTime() - Date.now()) / 1000);
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31536000],
    ['month', 2592000],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ];

  const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size) {
      return formatter.format(Math.round(seconds / size), unit);
    }
  }
  return formatter.format(seconds, 'second');
}

/** Duration in minutes as a human phrase ("about 3 hours"). */
export function formatDuration(minutes: number): string {
  if (minutes <= 0) return 'less than a minute';
  if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'}`;
  const hours = Math.round((minutes / 60) * 10) / 10;
  if (hours < 24) return `about ${hours} hour${hours === 1 ? '' : 's'}`;
  return `about ${Math.round(hours / 24)} day(s)`;
}

export function initials(name: string, fallback = '?'): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return fallback;
  return (parts[0][0] + (parts[1]?.[0] ?? '')).toUpperCase();
}

/** The browser's IANA timezone, used as the default for scheduling. */
export function localTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch {
    return 'UTC';
  }
}

/** "2026-10-21T14:30" for a datetime-local input, in the given timezone. */
export function toDateTimeLocalValue(date: Date, timeZone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);

  const get = (type: string) => parts.find((part) => part.type === type)?.value ?? '00';
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

export function debounce<T extends (...args: never[]) => void>(fn: T, delay = 300) {
  let timer: ReturnType<typeof setTimeout>;
  return (...args: Parameters<T>) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}
