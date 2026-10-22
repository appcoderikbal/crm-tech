import { AlertTriangle, Loader2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button, Field, Input } from '@/components/ui';
import { useAuth } from '@/features/auth/AuthContext';
import { ApiError } from '@/lib/api';

export function LoginPage() {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});
    setSubmitting(true);

    try {
      await login(email, password);
    } catch (caught) {
      if (caught instanceof ApiError) {
        setError(caught.message);
        setFieldErrors(caught.fieldErrors);
      } else {
        setError('Could not sign in. Please try again.');
      }
      setPassword('');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-sm font-bold text-white">
            T
          </span>
          <div>
            <p className="text-[15px] font-semibold text-ink">Techzapp CRM</p>
            <p className="text-[12px] text-ink-faint">Email marketing console</p>
          </div>
        </div>

        <div className="rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(16,24,40,.06)]">
          <h1 className="text-lg font-semibold text-ink">Sign in</h1>
          <p className="mt-1 text-[13px] text-ink-faint">Use the account created during installation.</p>

          <form onSubmit={onSubmit} className="mt-5 space-y-4" noValidate>
            {error && (
              <div
                role="alert"
                className="flex gap-2 rounded-lg border border-danger/25 bg-danger-soft px-3 py-2.5 text-[13px] text-danger"
              >
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                <span>{error}</span>
              </div>
            )}

            <Field label="Email address" htmlFor="email" error={fieldErrors.email}>
              <Input
                id="email"
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                autoComplete="username"
                autoFocus
                required
                invalid={Boolean(fieldErrors.email)}
                placeholder="you@company.com"
              />
            </Field>

            <Field label="Password" htmlFor="password" error={fieldErrors.password}>
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="current-password"
                required
                invalid={Boolean(fieldErrors.password)}
              />
            </Field>

            <Button type="submit" variant="primary" size="lg" className="w-full" loading={submitting}>
              {submitting ? 'Signing in…' : 'Sign in'}
            </Button>
          </form>
        </div>

        <p className="mt-4 text-center text-[12px] text-ink-faint">
          Repeated failed attempts temporarily lock the account.
        </p>
      </div>
    </div>
  );
}

export function AuthLoading() {
  return (
    <div className="flex min-h-screen items-center justify-center text-ink-faint">
      <Loader2 className="h-5 w-5 animate-spin" aria-hidden />
    </div>
  );
}
