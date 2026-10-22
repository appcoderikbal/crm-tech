import { ArrowLeft, KeyRound, Save } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  Field,
  Input,
  PageHeader,
  Select,
  useToast,
} from '@/components/ui';
import { useAuth } from '@/features/auth/AuthContext';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, localTimezone } from '@/lib/utils';

export function ProfilePage() {
  const { user, refresh } = useAuth();
  const { notify } = useToast();

  const [name, setName] = useState(user?.name ?? '');
  const [timezone, setTimezone] = useState(user?.timezone ?? localTimezone());
  const [savingProfile, setSavingProfile] = useState(false);

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [changing, setChanging] = useState(false);

  const zones = (() => {
    try {
      return (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? ['UTC'];
    } catch {
      return ['UTC'];
    }
  })();

  async function saveProfile() {
    setSavingProfile(true);
    try {
      await api.patch('/auth/profile', { name, timezone });
      notify('Profile updated.', 'ok');
      void refresh();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not save your profile.', 'danger');
    } finally {
      setSavingProfile(false);
    }
  }

  async function changePassword() {
    setErrors({});

    if (next !== confirm) {
      setErrors({ confirm: 'The new passwords do not match.' });
      return;
    }

    setChanging(true);
    try {
      await api.post('/auth/change-password', { current_password: current, new_password: next });
      notify('Password changed.', 'ok');
      setCurrent('');
      setNext('');
      setConfirm('');
      void refresh();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors);
        if (Object.keys(error.fieldErrors).length === 0) notify(error.message, 'danger');
      } else {
        notify('Could not change your password.', 'danger');
      }
    } finally {
      setChanging(false);
    }
  }

  return (
    <div className="space-y-5">
      <Link to="/settings" className="inline-flex items-center gap-1 text-[13px] text-ink-faint hover:text-ink">
        <ArrowLeft className="h-3.5 w-3.5" /> Back to settings
      </Link>

      <PageHeader title="Your profile" description={user?.email} />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader
            title="Details"
            action={
              <Button variant="primary" size="sm" loading={savingProfile} onClick={() => void saveProfile()}>
                <Save className="h-3.5 w-3.5" /> Save
              </Button>
            }
          />
          <CardBody className="space-y-4">
            <Field label="Email address" hint="Contact an administrator to change this.">
              <Input value={user?.email ?? ''} disabled />
            </Field>
            <Field label="Display name">
              <Input value={name} onChange={(event) => setName(event.target.value)} maxLength={120} />
            </Field>
            <Field label="Timezone" hint="Used when scheduling campaigns and displaying times.">
              <Select value={timezone} onChange={(event) => setTimezone(event.target.value)}>
                {zones.map((zone) => (
                  <option key={zone} value={zone}>
                    {zone}
                  </option>
                ))}
              </Select>
            </Field>

            <div className="flex flex-wrap gap-x-6 gap-y-2 border-t border-line pt-3 text-[13px]">
              <span className="text-ink-faint">
                Role: <Badge tone="outline">{user?.role}</Badge>
              </span>
              <span className="text-ink-faint">Last sign-in: {formatDateTime(user?.last_login_at)}</span>
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <KeyRound className="h-4 w-4" aria-hidden /> Change password
              </span>
            }
            description="Signing out of other devices is not automatic — change it here, then review the audit log."
          />
          <CardBody className="space-y-4">
            <Field label="Current password" required error={errors.current_password}>
              <Input
                type="password"
                value={current}
                onChange={(event) => setCurrent(event.target.value)}
                autoComplete="current-password"
                invalid={Boolean(errors.current_password)}
              />
            </Field>
            <Field
              label="New password"
              required
              error={errors.new_password ?? errors.password}
              hint="At least 12 characters, with letters and numbers."
            >
              <Input
                type="password"
                value={next}
                onChange={(event) => setNext(event.target.value)}
                autoComplete="new-password"
                invalid={Boolean(errors.new_password ?? errors.password)}
              />
            </Field>
            <Field label="Confirm new password" required error={errors.confirm}>
              <Input
                type="password"
                value={confirm}
                onChange={(event) => setConfirm(event.target.value)}
                autoComplete="new-password"
                invalid={Boolean(errors.confirm)}
              />
            </Field>

            <Button
              variant="primary"
              loading={changing}
              disabled={!current || !next || !confirm}
              onClick={() => void changePassword()}
            >
              Change password
            </Button>

            <Callout tone="info" title="How passwords are stored">
              Hashed with Argon2id where the server supports it, bcrypt otherwise. The plaintext is never written to
              the database or the logs.
            </Callout>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
