import { KeyRound, Power, Save, ScrollText, Server, Siren, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  Dialog,
  Field,
  Input,
  LoadingPanel,
  PageHeader,
  StatusDot,
  Switch,
  Table,
  Td,
  Textarea,
  Th,
  useToast,
} from '@/components/ui';
import { useAuth } from '@/features/auth/AuthContext';
import { useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { SettingsPayload } from '@/lib/types';
import { formatDateTime, formatNumber, formatRelative } from '@/lib/utils';

type SettingValue = string | number | boolean;

export function SettingsPage() {
  const { isAdmin, refresh: refreshAuth } = useAuth();
  const { notify } = useToast();

  const settings = useResource<SettingsPayload>('/settings');
  const [form, setForm] = useState<Record<string, SettingValue>>({});
  const [saving, setSaving] = useState(false);
  const [killOpen, setKillOpen] = useState(false);
  const [killReason, setKillReason] = useState('');

  useEffect(() => {
    if (!settings.data) return;
    const next: Record<string, SettingValue> = {};
    for (const key of settings.data.editable) {
      const value = settings.data.settings[key];
      if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        next[key] = value;
      }
    }
    setForm(next);
  }, [settings.data]);

  if (settings.loading && !settings.data) return <LoadingPanel />;
  if (settings.error) return <Callout tone="danger" title="Could not load settings">{settings.error}</Callout>;
  if (!settings.data) return null;

  const { provider, tracking, runtime, cron, health } = settings.data;
  const killSwitchOn = Boolean(form.kill_switch_engaged);

  function update(key: string, value: SettingValue) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function save(keys: string[]) {
    setSaving(true);
    try {
      const payload: Record<string, SettingValue> = {};
      for (const key of keys) {
        if (key in form) payload[key] = form[key];
      }
      await api.patch('/settings', payload);
      notify('Settings saved.', 'ok');
      void settings.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not save the settings.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  async function toggleKillSwitch(engage: boolean) {
    setSaving(true);
    try {
      await api.post('/settings/kill-switch', { engage, reason: killReason });
      notify(engage ? 'Sending stopped.' : 'Sending re-enabled.', engage ? 'danger' : 'ok');
      setKillOpen(false);
      setKillReason('');
      void settings.reload();
      void refreshAuth();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not change the kill switch.', 'danger');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Settings"
        description="Operational controls and environment information. Secrets live in the protected config file, never here."
        actions={
          <>
            <Link to="/settings/profile">
              <Button variant="secondary">
                <User className="h-3.5 w-3.5" /> Profile
              </Button>
            </Link>
            {isAdmin && (
              <Link to="/settings/audit">
                <Button variant="secondary">
                  <ScrollText className="h-3.5 w-3.5" /> Audit log
                </Button>
              </Link>
            )}
          </>
        }
      />

      {!isAdmin && (
        <Callout tone="info" title="Read-only">
          Only administrators can change these settings.
        </Callout>
      )}

      {/* -------------------------------------------------------- kill switch */}
      <Card className={killSwitchOn ? 'border-danger/40' : undefined}>
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Power className={killSwitchOn ? 'h-4 w-4 text-danger' : 'h-4 w-4 text-ok'} aria-hidden />
              Global sending kill switch
            </span>
          }
          description="Stops the cron worker from sending anything, immediately."
          action={
            isAdmin && (
              <Button
                variant={killSwitchOn ? 'primary' : 'danger'}
                onClick={() => (killSwitchOn ? void toggleKillSwitch(false) : setKillOpen(true))}
                loading={saving}
              >
                {killSwitchOn ? 'Resume sending' : 'Stop all sending'}
              </Button>
            )
          }
        />
        <CardBody>
          {killSwitchOn ? (
            <Callout tone="danger" title="Sending is stopped">
              {String(settings.data.settings.kill_switch_reason || 'No reason recorded.')}
            </Callout>
          ) : (
            <Callout tone="ok" title="Sending is enabled">
              Campaigns will send according to their schedules and the rate limits below.
            </Callout>
          )}
        </CardBody>
      </Card>

      {/* ----------------------------------------------------- sending limits */}
      <Card>
        <CardHeader
          title="Sending limits"
          description="Hard caps the cron worker respects, independent of what a campaign asks for."
          action={
            isAdmin && (
              <Button
                variant="primary"
                size="sm"
                loading={saving}
                onClick={() =>
                  void save([
                    'sending_enabled',
                    'max_send_rate_per_minute',
                    'max_sends_per_day',
                    'max_sends_per_cron_run',
                    'recipient_build_batch',
                    'soft_bounce_max_attempts',
                  ])
                }
              >
                <Save className="h-3.5 w-3.5" /> Save
              </Button>
            )
          }
        />
        <CardBody className="space-y-4">
          <Switch
            checked={Boolean(form.sending_enabled)}
            onChange={(value) => update('sending_enabled', value)}
            disabled={!isAdmin}
            label="Sending enabled"
            description="A softer control than the kill switch; both must be on for anything to send."
          />

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Max messages per minute" hint="Account-wide ceiling.">
              <Input
                type="number"
                min={1}
                value={String(form.max_send_rate_per_minute ?? '')}
                onChange={(event) => update('max_send_rate_per_minute', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Max messages per day" hint="0 disables the daily cap.">
              <Input
                type="number"
                min={0}
                value={String(form.max_sends_per_day ?? '')}
                onChange={(event) => update('max_sends_per_day', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Max messages per cron run" hint="Keeps each run inside the host's time limit.">
              <Input
                type="number"
                min={1}
                value={String(form.max_sends_per_cron_run ?? '')}
                onChange={(event) => update('max_sends_per_cron_run', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Recipient build batch" hint="Rows inserted per queue-building pass.">
              <Input
                type="number"
                min={100}
                value={String(form.recipient_build_batch ?? '')}
                onChange={(event) => update('recipient_build_batch', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Retry attempts" hint="Transient failures before a recipient is marked failed.">
              <Input
                type="number"
                min={1}
                max={10}
                value={String(form.soft_bounce_max_attempts ?? '')}
                onChange={(event) => update('soft_bounce_max_attempts', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
          </div>
        </CardBody>
      </Card>

      {/* --------------------------------------------------------- thresholds */}
      <Card>
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Siren className="h-4 w-4" aria-hidden /> Deliverability thresholds
            </span>
          }
          description="Alert levels for this application — not guaranteed-safe limits for your SES account."
          action={
            isAdmin && (
              <Button
                variant="primary"
                size="sm"
                loading={saving}
                onClick={() =>
                  void save([
                    'bounce_rate_warning_pct',
                    'bounce_rate_critical_pct',
                    'complaint_rate_warning_pct',
                    'complaint_rate_critical_pct',
                    'complaint_spike_auto_pause',
                    'alert_rolling_window_hours',
                    'alert_min_sample_size',
                    'cron_stale_minutes',
                    'campaign_overdue_minutes',
                  ])
                }
              >
                <Save className="h-3.5 w-3.5" /> Save
              </Button>
            )
          }
        />
        <CardBody className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field label="Bounce warning %">
              <Input
                type="number"
                step="0.01"
                value={String(form.bounce_rate_warning_pct ?? '')}
                onChange={(event) => update('bounce_rate_warning_pct', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Bounce critical %">
              <Input
                type="number"
                step="0.01"
                value={String(form.bounce_rate_critical_pct ?? '')}
                onChange={(event) => update('bounce_rate_critical_pct', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Complaint warning %">
              <Input
                type="number"
                step="0.001"
                value={String(form.complaint_rate_warning_pct ?? '')}
                onChange={(event) => update('complaint_rate_warning_pct', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Complaint critical %">
              <Input
                type="number"
                step="0.001"
                value={String(form.complaint_rate_critical_pct ?? '')}
                onChange={(event) => update('complaint_rate_critical_pct', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Rolling window (hours)">
              <Input
                type="number"
                min={1}
                value={String(form.alert_rolling_window_hours ?? '')}
                onChange={(event) => update('alert_rolling_window_hours', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Minimum sample size" hint="Below this, status is 'unknown' rather than healthy.">
              <Input
                type="number"
                min={1}
                value={String(form.alert_min_sample_size ?? '')}
                onChange={(event) => update('alert_min_sample_size', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Cron stale after (minutes)">
              <Input
                type="number"
                min={5}
                value={String(form.cron_stale_minutes ?? '')}
                onChange={(event) => update('cron_stale_minutes', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
            <Field label="Campaign overdue after (minutes)">
              <Input
                type="number"
                min={1}
                value={String(form.campaign_overdue_minutes ?? '')}
                onChange={(event) => update('campaign_overdue_minutes', Number(event.target.value))}
                disabled={!isAdmin}
              />
            </Field>
          </div>

          <Switch
            checked={Boolean(form.complaint_spike_auto_pause)}
            onChange={(value) => update('complaint_spike_auto_pause', value)}
            disabled={!isAdmin}
            label="Pause sending automatically on a complaint spike"
            description="Engages the kill switch as soon as the complaint WARNING level is reached, not the critical one."
          />
        </CardBody>
      </Card>

      {/* ----------------------------------------------------- campaign setup */}
      <Card>
        <CardHeader
          title="Campaign defaults"
          action={
            isAdmin && (
              <Button
                variant="primary"
                size="sm"
                loading={saving}
                onClick={() =>
                  void save([
                    'default_timezone',
                    'tracking_base_url',
                    'company_postal_address',
                    'import_retention_hours',
                    'import_max_file_mb',
                    'import_batch_rows',
                  ])
                }
              >
                <Save className="h-3.5 w-3.5" /> Save
              </Button>
            )
          }
        />
        <CardBody className="grid gap-4 sm:grid-cols-2">
          <Field label="Default timezone">
            <Input
              value={String(form.default_timezone ?? '')}
              onChange={(event) => update('default_timezone', event.target.value)}
              disabled={!isAdmin}
            />
          </Field>
          <Field label="Tracking base URL" hint="Leave blank to use the application URL from the config file.">
            <Input
              value={String(form.tracking_base_url ?? '')}
              onChange={(event) => update('tracking_base_url', event.target.value)}
              disabled={!isAdmin}
              placeholder={tracking.base_url}
            />
          </Field>
          <Field
            label="Company postal address"
            hint="Added to the unsubscribe footer. Required by CAN-SPAM and similar laws."
            className="sm:col-span-2"
          >
            <Textarea
              rows={2}
              value={String(form.company_postal_address ?? '')}
              onChange={(event) => update('company_postal_address', event.target.value)}
              disabled={!isAdmin}
            />
          </Field>
          <Field label="Import file retention (hours)">
            <Input
              type="number"
              min={1}
              value={String(form.import_retention_hours ?? '')}
              onChange={(event) => update('import_retention_hours', Number(event.target.value))}
              disabled={!isAdmin}
            />
          </Field>
          <Field label="Max import file size (MB)">
            <Input
              type="number"
              min={1}
              value={String(form.import_max_file_mb ?? '')}
              onChange={(event) => update('import_max_file_mb', Number(event.target.value))}
              disabled={!isAdmin}
            />
          </Field>
        </CardBody>
      </Card>

      {/* ------------------------------------------------------------ provider */}
      <Card>
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Server className="h-4 w-4" aria-hidden /> Amazon SES
            </span>
          }
          description="Configured in the protected config file outside public_html."
        />
        <CardBody>
          <dl className="grid gap-x-8 gap-y-3 text-[13px] sm:grid-cols-2">
            <Row label="Transport">
              <Badge tone={provider.transport === 'ses' ? 'ok' : 'warn'}>{provider.transport}</Badge>
              {provider.transport !== 'ses' && (
                <span className="ml-2 text-[12px] text-warn">No mail is actually being delivered.</span>
              )}
            </Row>
            <Row label="Region">{provider.region || '—'}</Row>
            <Row label="Credentials">
              {provider.credentials_configured ? (
                <Badge tone="ok">Configured</Badge>
              ) : (
                <Badge tone="danger">Missing</Badge>
              )}
            </Row>
            <Row label="Configuration set">{provider.configuration_set || <span className="text-warn">Not set — no event publishing</span>}</Row>
            <Row label="Verified identities" className="sm:col-span-2">
              {provider.verified_identities.length === 0 ? (
                <span className="text-danger">None configured — sending will be refused.</span>
              ) : (
                <span className="flex flex-wrap gap-1.5">
                  {provider.verified_identities.map((identity) => (
                    <Badge key={identity} tone="outline">
                      {identity}
                    </Badge>
                  ))}
                </span>
              )}
            </Row>
            {provider.account && (
              <>
                <Row label="SES sending">
                  <Badge tone={provider.account.sending_enabled ? 'ok' : 'danger'}>
                    {provider.account.sending_enabled ? 'Enabled' : 'Disabled'}
                  </Badge>
                </Row>
                <Row label="24-hour quota">
                  {formatNumber(Math.round(provider.account.sent_last_24_hours))} /{' '}
                  {formatNumber(Math.round(provider.account.max_24_hour))}
                </Row>
              </>
            )}
          </dl>

          <div className="mt-4 rounded-lg border border-line bg-muted/40 p-3">
            <p className="mb-2 text-[13px] font-medium text-ink">Endpoints to configure in AWS</p>
            <dl className="space-y-1.5 text-[12px]">
              <Row label="SNS webhook" mono>
                {tracking.sns_webhook}
              </Row>
              <Row label="Open pixel" mono>
                {tracking.base_url}
                {tracking.open_pixel_path}.php
              </Row>
              <Row label="Click redirect" mono>
                {tracking.base_url}
                {tracking.click_path}.php
              </Row>
              <Row label="Unsubscribe" mono>
                {tracking.base_url}
                {tracking.unsubscribe_path}
              </Row>
            </dl>
          </div>
        </CardBody>
      </Card>

      {/* ---------------------------------------------------------------- cron */}
      <Card>
        <CardHeader title="Background workers" description="Each cron job reports in after every run." />
        {cron.length === 0 ? (
          <CardBody>
            <Callout tone="danger" title="No cron job has ever run">
              Scheduled campaigns will never send and events will never be processed until the cron jobs are
              configured. See the deployment guide.
            </Callout>
          </CardBody>
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Job</Th>
                <Th>Last run</Th>
                <Th>Status</Th>
                <Th className="hidden text-right sm:table-cell">Runs</Th>
                <Th className="hidden text-right sm:table-cell">Duration</Th>
              </tr>
            </thead>
            <tbody>
              {cron.map((job) => (
                <tr key={job.job_name}>
                  <Td className="font-medium text-ink">{job.job_name}</Td>
                  <Td className="text-ink-soft">
                    {formatRelative(job.last_run_at)}
                    <span className="block text-[11px] text-ink-faint">{formatDateTime(job.last_run_at)}</span>
                  </Td>
                  <Td>
                    <span className="flex items-center gap-1.5">
                      <StatusDot status={job.last_status === 'ok' ? 'green' : 'red'} />
                      <span className="text-[13px] text-ink-soft">{job.last_status}</span>
                    </span>
                  </Td>
                  <Td className="hidden text-right tabular sm:table-cell">{formatNumber(job.run_count)}</Td>
                  <Td className="hidden text-right tabular sm:table-cell">{job.duration_ms} ms</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {/* ------------------------------------------------------------ runtime */}
      <Card>
        <CardHeader title="Hosting environment" description="Reported by the server; useful when tuning limits." />
        <CardBody>
          <dl className="grid gap-x-8 gap-y-2 text-[13px] sm:grid-cols-2 lg:grid-cols-3">
            <Row label="PHP">{runtime.php_version}</Row>
            <Row label="MySQL">{runtime.database_version}</Row>
            <Row label="Memory limit">{runtime.memory_limit}</Row>
            <Row label="Max execution time">{runtime.max_execution_time}s</Row>
            <Row label="Max upload size">{runtime.upload_max_filesize}</Row>
            <Row label="Max POST size">{runtime.post_max_size}</Row>
          </dl>
          <div className="mt-3 flex flex-wrap gap-3">
            {Object.entries(health).map(([area, entry]) => (
              <span key={area} className="flex items-center gap-1.5 text-[12px] text-ink-soft">
                <StatusDot status={entry.status} /> {area}
              </span>
            ))}
          </div>
        </CardBody>
      </Card>

      <Dialog
        open={killOpen}
        onClose={() => setKillOpen(false)}
        title="Stop all sending?"
        description="The cron worker stops immediately. Campaigns in progress are paused where they are."
        footer={
          <>
            <Button variant="secondary" onClick={() => setKillOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={saving}
              disabled={!killReason.trim()}
              onClick={() => void toggleKillSwitch(true)}
            >
              <Power className="h-3.5 w-3.5" /> Stop sending
            </Button>
          </>
        }
      >
        <Field label="Why are you stopping sending?" required hint="Recorded in the audit log and shown on the dashboard.">
          <Textarea rows={3} value={killReason} onChange={(event) => setKillReason(event.target.value)} autoFocus />
        </Field>
      </Dialog>

      <Card>
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <KeyRound className="h-4 w-4" aria-hidden /> Your account
            </span>
          }
        />
        <CardBody>
          <Link to="/settings/profile">
            <Button variant="secondary">Change password and profile</Button>
          </Link>
        </CardBody>
      </Card>
    </div>
  );
}

function Row({
  label,
  children,
  className,
  mono,
}: {
  label: string;
  children: React.ReactNode;
  className?: string;
  mono?: boolean;
}) {
  return (
    <div className={className}>
      <dt className="text-ink-faint">{label}</dt>
      <dd className={mono ? 'font-mono text-[12px] break-all text-ink' : 'text-ink'}>{children}</dd>
    </div>
  );
}
