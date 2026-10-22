/**
 * Reusable UI primitives.
 *
 * Hand-written in the shadcn/ui idiom — Tailwind utilities composed with
 * class-variance-authority, styled by the tokens in index.css. No runtime CSS
 * framework, which keeps the production bundle small for shared hosting.
 */
import { cva, type VariantProps } from 'class-variance-authority';
import {
  AlertTriangle,
  Check,
  ChevronDown,
  Info,
  Loader2,
  Search,
  X,
  XCircle,
} from 'lucide-react';
import {
  createContext,
  forwardRef,
  useCallback,
  useContext,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react';
import { cn } from '@/lib/utils';

/* ------------------------------------------------------------------ button */

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 rounded-lg font-medium whitespace-nowrap transition-colors ' +
    'disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2',
  {
    variants: {
      variant: {
        primary: 'bg-brand text-white hover:bg-brand-strong focus-visible:outline-brand',
        secondary: 'bg-surface text-ink border border-line-strong hover:bg-muted focus-visible:outline-brand',
        ghost: 'text-ink-soft hover:bg-muted hover:text-ink focus-visible:outline-brand',
        danger: 'bg-danger text-white hover:opacity-90 focus-visible:outline-danger',
        subtle: 'bg-muted text-ink-soft hover:bg-line hover:text-ink',
      },
      size: {
        sm: 'h-8 px-3 text-[13px]',
        md: 'h-9 px-3.5 text-sm',
        lg: 'h-10 px-5 text-sm',
        icon: 'h-8 w-8',
      },
    },
    defaultVariants: { variant: 'secondary', size: 'md' },
  },
);

export interface ButtonProps
  extends ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant, size, loading, children, disabled, ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      className={cn(buttonVariants({ variant, size }), className)}
      disabled={disabled || loading}
      {...props}
    >
      {loading && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden />}
      {children}
    </button>
  );
});

/* -------------------------------------------------------------------- card */

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <div className={cn('rounded-card border border-line bg-surface shadow-[0_1px_2px_rgba(16,24,40,.04)]', className)}>
      {children}
    </div>
  );
}

export function CardHeader({
  title,
  description,
  action,
  className,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4', className)}>
      <div className="min-w-0">
        <h2 className="text-[15px] font-semibold text-ink">{title}</h2>
        {description && <p className="mt-0.5 text-[13px] text-ink-faint">{description}</p>}
      </div>
      {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
    </div>
  );
}

export function CardBody({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn('px-5 py-4', className)}>{children}</div>;
}

/* ------------------------------------------------------------------- badge */

const badgeVariants = cva(
  'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium leading-5 whitespace-nowrap',
  {
    variants: {
      tone: {
        neutral: 'bg-muted text-ink-soft',
        brand: 'bg-brand-soft text-brand-strong',
        ok: 'bg-ok-soft text-ok',
        warn: 'bg-warn-soft text-warn',
        danger: 'bg-danger-soft text-danger',
        outline: 'border border-line-strong text-ink-soft',
      },
    },
    defaultVariants: { tone: 'neutral' },
  },
);

export function Badge({
  tone,
  className,
  children,
}: VariantProps<typeof badgeVariants> & { className?: string; children: ReactNode }) {
  return <span className={cn(badgeVariants({ tone }), className)}>{children}</span>;
}

/** Severity dot used in the sidebar and alert lists. */
export function StatusDot({ status, className }: { status: string; className?: string }) {
  const tone =
    status === 'red' || status === 'critical'
      ? 'bg-danger'
      : status === 'amber' || status === 'warning'
        ? 'bg-warn'
        : status === 'green' || status === 'ok'
          ? 'bg-ok'
          : 'bg-ink-faint';
  return <span className={cn('inline-block h-2 w-2 shrink-0 rounded-full', tone, className)} aria-hidden />;
}

/* ------------------------------------------------------------------ fields */

export function Field({
  label,
  htmlFor,
  hint,
  error,
  required,
  className,
  children,
}: {
  label?: ReactNode;
  htmlFor?: string;
  hint?: ReactNode;
  error?: string | null;
  required?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={cn('space-y-1.5', className)}>
      {label && (
        <label htmlFor={htmlFor} className="block text-[13px] font-medium text-ink">
          {label}
          {required && <span className="ml-0.5 text-danger">*</span>}
        </label>
      )}
      {children}
      {error ? (
        <p className="text-[12px] text-danger">{error}</p>
      ) : (
        hint && <p className="text-[12px] text-ink-faint">{hint}</p>
      )}
    </div>
  );
}

const controlClasses =
  'w-full rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink placeholder:text-ink-faint ' +
  'transition-colors focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 ' +
  'disabled:cursor-not-allowed disabled:bg-muted disabled:text-ink-faint';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(
  function Input({ className, invalid, ...props }, ref) {
    return (
      <input
        ref={ref}
        className={cn(controlClasses, 'h-9', invalid && 'border-danger focus:border-danger focus:ring-danger/15', className)}
        aria-invalid={invalid || undefined}
        {...props}
      />
    );
  },
);

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
  function Textarea({ className, ...props }, ref) {
    return <textarea ref={ref} className={cn(controlClasses, 'py-2 leading-relaxed', className)} {...props} />;
  },
);

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
  function Select({ className, children, ...props }, ref) {
    return (
      <div className="relative">
        <select ref={ref} className={cn(controlClasses, 'h-9 appearance-none pr-8', className)} {...props}>
          {children}
        </select>
        <ChevronDown
          className="pointer-events-none absolute top-1/2 right-2.5 h-4 w-4 -translate-y-1/2 text-ink-faint"
          aria-hidden
        />
      </div>
    );
  },
);

export function Checkbox({
  checked,
  indeterminate,
  onChange,
  label,
  disabled,
  className,
}: {
  checked: boolean;
  indeterminate?: boolean;
  onChange: (checked: boolean) => void;
  label?: ReactNode;
  disabled?: boolean;
  className?: string;
}) {
  const ref = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (ref.current) ref.current.indeterminate = Boolean(indeterminate) && !checked;
  }, [indeterminate, checked]);

  return (
    <label className={cn('inline-flex cursor-pointer items-center gap-2 select-none', disabled && 'cursor-not-allowed opacity-50', className)}>
      <input
        ref={ref}
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(event) => onChange(event.target.checked)}
        className="h-4 w-4 rounded border-line-strong text-brand accent-[var(--color-brand)] focus:ring-brand"
      />
      {label && <span className="text-sm text-ink">{label}</span>}
    </label>
  );
}

export function SearchInput({
  value,
  onChange,
  placeholder = 'Search…',
  className,
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  className?: string;
}) {
  return (
    <div className={cn('relative', className)}>
      <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-ink-faint" aria-hidden />
      <Input
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        className="pl-8"
        type="search"
      />
    </div>
  );
}

export function Switch({
  checked,
  onChange,
  label,
  description,
  disabled,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
}) {
  const id = useId();
  return (
    <div className="flex items-start justify-between gap-4 py-2.5">
      <div className="min-w-0">
        <label htmlFor={id} className="block text-sm font-medium text-ink">
          {label}
        </label>
        {description && <p className="mt-0.5 text-[12px] text-ink-faint">{description}</p>}
      </div>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={cn(
          'relative mt-0.5 h-5 w-9 shrink-0 rounded-full transition-colors disabled:opacity-50',
          checked ? 'bg-brand' : 'bg-line-strong',
        )}
      >
        <span
          className={cn(
            'absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform',
            checked && 'translate-x-4',
          )}
        />
      </button>
    </div>
  );
}

/* ------------------------------------------------------------------- table */

export function Table({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className="overflow-x-auto">
      <table className={cn('w-full border-collapse text-sm', className)}>{children}</table>
    </div>
  );
}

export function Th({
  children,
  className,
  sortable,
  active,
  direction,
  onSort,
}: {
  /** Optional: an action column has a blank header. */
  children?: ReactNode;
  className?: string;
  sortable?: boolean;
  active?: boolean;
  direction?: 'asc' | 'desc';
  onSort?: () => void;
}) {
  return (
    <th
      className={cn(
        'border-b border-line bg-muted/60 px-3 py-2 text-left text-[11px] font-semibold tracking-wide text-ink-soft uppercase',
        className,
      )}
    >
      {sortable ? (
        <button type="button" onClick={onSort} className="inline-flex items-center gap-1 hover:text-ink">
          {children}
          <ChevronDown
            className={cn(
              'h-3 w-3 transition-transform',
              !active && 'opacity-30',
              active && direction === 'asc' && 'rotate-180',
            )}
            aria-hidden
          />
        </button>
      ) : (
        children
      )}
    </th>
  );
}

export function Td({ children, className }: { children: ReactNode; className?: string }) {
  return <td className={cn('border-b border-line px-3 py-2.5 align-middle text-ink', className)}>{children}</td>;
}

export function EmptyState({
  title,
  description,
  action,
  icon,
}: {
  title: string;
  description?: ReactNode;
  action?: ReactNode;
  icon?: ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
      {icon && <div className="mb-3 text-ink-faint">{icon}</div>}
      <p className="text-sm font-medium text-ink">{title}</p>
      {description && <p className="mt-1 max-w-md text-[13px] text-ink-faint">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function Pagination({
  page,
  pages,
  total,
  perPage,
  onPage,
}: {
  page: number;
  pages: number;
  total: number;
  perPage: number;
  onPage: (page: number) => void;
}) {
  if (total === 0) return null;
  const from = (page - 1) * perPage + 1;
  const to = Math.min(total, page * perPage);

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3">
      <p className="text-[13px] text-ink-faint tabular">
        {from.toLocaleString()}–{to.toLocaleString()} of {total.toLocaleString()}
      </p>
      <div className="flex items-center gap-1.5">
        <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Previous
        </Button>
        <span className="px-2 text-[13px] text-ink-soft tabular">
          {page} / {Math.max(1, pages)}
        </span>
        <Button size="sm" variant="secondary" disabled={page >= pages} onClick={() => onPage(page + 1)}>
          Next
        </Button>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ dialog */

export function Dialog({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  width = 'md',
}: {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  children?: ReactNode;
  footer?: ReactNode;
  width?: 'sm' | 'md' | 'lg' | 'xl';
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-ink/35 p-0 sm:items-center sm:p-4">
      <div
        className="absolute inset-0"
        onClick={onClose}
        aria-hidden
      />
      <div
        role="dialog"
        aria-modal="true"
        className={cn(
          'relative z-10 w-full rounded-t-2xl border border-line bg-surface shadow-xl sm:rounded-card',
          widths[width],
        )}
      >
        <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
          <div className="min-w-0">
            <h2 className="text-[15px] font-semibold text-ink">{title}</h2>
            {description && <p className="mt-1 text-[13px] text-ink-faint">{description}</p>}
          </div>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X className="h-4 w-4" />
          </Button>
        </div>
        {children && <div className="max-h-[65vh] overflow-y-auto px-5 py-4">{children}</div>}
        {footer && <div className="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ alerts */

export function Callout({
  tone = 'info',
  title,
  children,
  className,
}: {
  tone?: 'info' | 'warn' | 'danger' | 'ok';
  title?: ReactNode;
  children?: ReactNode;
  className?: string;
}) {
  const styles = {
    info: 'border-brand/25 bg-brand-soft text-brand-strong',
    warn: 'border-warn/30 bg-warn-soft text-warn',
    danger: 'border-danger/25 bg-danger-soft text-danger',
    ok: 'border-ok/25 bg-ok-soft text-ok',
  }[tone];

  const Icon = { info: Info, warn: AlertTriangle, danger: XCircle, ok: Check }[tone];

  return (
    <div className={cn('flex gap-2.5 rounded-lg border px-3.5 py-3 text-[13px]', styles, className)}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
      <div className="min-w-0">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={cn(title && 'mt-0.5', 'text-ink-soft')}>{children}</div>}
      </div>
    </div>
  );
}

export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={cn('h-4 w-4 animate-spin text-ink-faint', className)} aria-hidden />;
}

export function LoadingPanel({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 px-6 py-14 text-[13px] text-ink-faint">
      <Spinner /> {label}
    </div>
  );
}

/* ------------------------------------------------------------------- toast */

interface Toast {
  id: number;
  tone: 'info' | 'ok' | 'danger';
  message: string;
}

const ToastContext = createContext<{
  notify: (message: string, tone?: Toast['tone']) => void;
} | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const counter = useRef(0);

  const notify = useCallback((message: string, tone: Toast['tone'] = 'info') => {
    const id = ++counter.current;
    setToasts((current) => [...current, { id, tone, message }]);
    setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 5200);
  }, []);

  const value = useMemo(() => ({ notify }), [notify]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-2">
        {toasts.map((toast) => (
          <div
            key={toast.id}
            role="status"
            className={cn(
              'pointer-events-auto flex items-start gap-2 rounded-lg border px-3.5 py-2.5 text-[13px] shadow-lg',
              toast.tone === 'danger'
                ? 'border-danger/25 bg-danger-soft text-danger'
                : toast.tone === 'ok'
                  ? 'border-ok/25 bg-ok-soft text-ok'
                  : 'border-line bg-surface text-ink',
            )}
          >
            <span className="min-w-0 flex-1">{toast.message}</span>
            <button
              type="button"
              onClick={() => setToasts((current) => current.filter((item) => item.id !== toast.id))}
              className="shrink-0 opacity-60 hover:opacity-100"
              aria-label="Dismiss"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const context = useContext(ToastContext);
  if (!context) throw new Error('useToast must be used inside ToastProvider');
  return context;
}

/* ------------------------------------------------------------------- misc */

export function PageHeader({
  title,
  description,
  actions,
}: {
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-xl font-semibold tracking-tight text-ink">{title}</h1>
        {description && <p className="mt-1 text-[13px] text-ink-faint">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function StatTile({
  label,
  value,
  hint,
  tone = 'neutral',
  onClick,
}: {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  tone?: 'neutral' | 'ok' | 'warn' | 'danger' | 'brand';
  onClick?: () => void;
}) {
  const accent = {
    neutral: 'text-ink',
    ok: 'text-ok',
    warn: 'text-warn',
    danger: 'text-danger',
    brand: 'text-brand',
  }[tone];

  const Wrapper = onClick ? 'button' : 'div';

  return (
    <Wrapper
      onClick={onClick}
      className={cn(
        'rounded-card border border-line bg-surface px-4 py-3.5 text-left',
        onClick && 'transition-colors hover:border-line-strong hover:bg-muted/40',
      )}
    >
      <p className="truncate text-[11px] font-medium tracking-wide text-ink-faint uppercase">{label}</p>
      <p className={cn('mt-1 text-2xl leading-tight font-semibold tabular', accent)}>{value}</p>
      {hint && <p className="mt-0.5 truncate text-[12px] text-ink-faint">{hint}</p>}
    </Wrapper>
  );
}
