import { BellOff, Gauge, RefreshCw, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  LoadingPanel,
  PageHeader,
  Select,
  StatTile,
  StatusDot,
  Table,
  Td,
  Th,
  useToast,
} from '@/components/ui';
import { useAuth } from '@/features/auth/AuthContext';
import { useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { Alert, DeliverabilityReport } from '@/lib/types';
import { formatDateTime, formatNumber, formatPercent } from '@/lib/utils';

const WINDOWS = [
  { label: 'Last 24 hours', hours: 24 },
  { label: 'Last 3 days', hours: 72 },
  { label: 'Last 7 days', hours: 168 },
  { label: 'Last 30 days', hours: 720 },
];

export function ReportsPage() {
  const { canWrite, refresh: refreshAuth } = useAuth();
  const { notify } = useToast();
  const [hours, setHours] = useState(72);
  const [busy, setBusy] = useState(false);

  const report = useResource<DeliverabilityReport>('/reports/deliverability', { window_hours: hours });

  async function reevaluate() {
    setBusy(true);
    try {
      await api.post('/alerts/evaluate');
      notify('Monitoring checks re-run.', 'ok');
      void report.reload();
      void refreshAuth();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not run the checks.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function acknowledge(alert: Alert) {
    try {
      await api.post(`/alerts/${alert.id}/acknowledge`);
      notify('Alert acknowledged.', 'ok');
      void report.reload();
      void refreshAuth();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not acknowledge the alert.', 'danger');
    }
  }

  if (report.loading && !report.data) return <LoadingPanel label="Loading deliverability…" />;
  if (report.error) return <Callout tone="danger" title="Could not load the report">{report.error}</Callout>;
  if (!report.data) return null;

  const { metrics, rates, thresholds, health, alerts, provider, sample_size: sample, min_sample: minSample } = report.data;
  const underSampled = sample < minSample;

  return (
    <div className="space-y-5">
      <PageHeader
        title="Deliverability"
        description="Account-level sending health over a rolling window."
        actions={
          <>
            <Select value={hours} onChange={(event) => setHours(Number(event.target.value))} className="w-44">
              {WINDOWS.map((window) => (
                <option key={window.hours} value={window.hours}>
                  {window.label}
                </option>
              ))}
            </Select>
            {canWrite && (
              <Button variant="secondary" loading={busy} onClick={() => void reevaluate()}>
                <RefreshCw className="h-3.5 w-3.5" /> Re-run checks
              </Button>
            )}
          </>
        }
      />

      {/* ------------------------------------------------------------ health */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {Object.entries(health).map(([area, entry]) => (
          <Card key={area}>
            <CardBody className="py-3.5">
              <div className="flex items-center gap-2">
                <StatusDot status={entry.status} />
                <p className="text-[13px] font-medium text-ink capitalize">{area.replace('_', ' ')}</p>
                <Badge
                  tone={
                    entry.status === 'red'
                      ? 'danger'
                      : entry.status === 'amber'
                        ? 'warn'
                        : entry.status === 'green'
                          ? 'ok'
                          : 'neutral'
                  }
                  className="ml-auto"
                >
                  {entry.status}
                </Badge>
              </div>
              <p className="mt-1.5 text-[12px] leading-snug text-ink-faint">{entry.detail}</p>
            </CardBody>
          </Card>
        ))}
      </div>

      {underSampled && (
        <Callout tone="info" title="Not enough data for a meaningful rate">
          Only {formatNumber(sample)} attempted recipient(s) in this window; the alert thresholds need at least{' '}
          {formatNumber(minSample)}. Rates below are shown for reference but are not being judged — the status is
          reported as <em>unknown</em> rather than healthy.
        </Callout>
      )}

      {report.data.data_quality.length > 0 && (
        <Callout tone="warn" title="These numbers are incomplete">
          <ul className="space-y-1">
            {report.data.data_quality.map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        </Callout>
      )}

      {/* ------------------------------------------------------------- rates */}
      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">Rates in this window</h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <StatTile
            label="Bounce rate"
            value={formatPercent(rates.bounce_rate)}
            hint={`Warn ${thresholds.bounce_warning}% · Critical ${thresholds.bounce_critical}%`}
            tone={
              rates.bounce_rate === null || underSampled
                ? 'neutral'
                : rates.bounce_rate >= thresholds.bounce_critical
                  ? 'danger'
                  : rates.bounce_rate >= thresholds.bounce_warning
                    ? 'warn'
                    : 'ok'
            }
          />
          <StatTile
            label="Complaint rate"
            value={formatPercent(rates.complaint_rate, 3)}
            hint={`Warn ${thresholds.complaint_warning}% · Critical ${thresholds.complaint_critical}%`}
            tone={
              rates.complaint_rate === null || underSampled
                ? 'neutral'
                : rates.complaint_rate >= thresholds.complaint_critical
                  ? 'danger'
                  : rates.complaint_rate >= thresholds.complaint_warning
                    ? 'warn'
                    : 'ok'
            }
          />
          <StatTile label="Delivery rate" value={formatPercent(rates.delivery_rate)} hint="Of SES-accepted messages" />
          <StatTile label="Open rate" value={formatPercent(rates.open_rate)} hint="Tracked, of delivered" />
          <StatTile label="Click rate" value={formatPercent(rates.click_rate)} hint="Tracked, of delivered" />
        </div>
      </section>

      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">Volumes</h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <StatTile label="Attempted" value={formatNumber(metrics.attempted)} />
          <StatTile label="Accepted" value={formatNumber(metrics.accepted)} />
          <StatTile label="Delivered" value={formatNumber(metrics.delivered)} tone="ok" />
          <StatTile label="Hard bounces" value={formatNumber(metrics.hard_bounces)} tone={metrics.hard_bounces ? 'danger' : 'neutral'} />
          <StatTile label="Soft bounces" value={formatNumber(metrics.soft_bounces)} tone={metrics.soft_bounces ? 'warn' : 'neutral'} />
          <StatTile label="Complaints" value={formatNumber(metrics.complaints)} tone={metrics.complaints ? 'danger' : 'neutral'} />
        </div>
      </section>

      {/* ---------------------------------------------------------- provider */}
      <Card>
        <CardHeader
          title="Amazon SES account"
          description="Read live from the SES API, not from stored settings."
        />
        <CardBody>
          {provider === null ? (
            <Callout tone="warn" title="Account status unavailable">
              The SES account status could not be read, so sending limits and the enforcement state are unknown. This
              is reported as unknown rather than healthy.
            </Callout>
          ) : (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <StatTile
                label="Sending enabled"
                value={provider.sending_enabled ? 'Yes' : 'No'}
                tone={provider.sending_enabled ? 'ok' : 'danger'}
              />
              <StatTile
                label="Enforcement"
                value={provider.enforcement_status}
                tone={provider.enforcement_status === 'HEALTHY' ? 'ok' : 'warn'}
              />
              <StatTile
                label="24-hour quota"
                value={`${formatNumber(Math.round(provider.sent_last_24_hours))} / ${formatNumber(Math.round(provider.max_24_hour))}`}
              />
              <StatTile
                label="Max send rate"
                value={`${provider.max_send_rate}/s`}
                hint={provider.production_access ? 'Production access' : 'Sandbox'}
                tone={provider.production_access ? 'neutral' : 'warn'}
              />
            </div>
          )}
        </CardBody>
      </Card>

      {/* ------------------------------------------------------------ alerts */}
      <Card>
        <CardHeader
          title="Active alerts"
          description="Raised by the monitoring checks. Acknowledging keeps the alert visible but clears the badge."
        />
        {alerts.length === 0 ? (
          <CardBody>
            <p className="flex items-center justify-center gap-2 py-6 text-[13px] text-ink-faint">
              <BellOff className="h-4 w-4" /> No active alerts.
            </p>
          </CardBody>
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Severity</Th>
                <Th>Alert</Th>
                <Th className="hidden lg:table-cell">Category</Th>
                <Th className="hidden text-right sm:table-cell">Last seen</Th>
                <Th className="w-32" />
              </tr>
            </thead>
            <tbody>
              {alerts.map((alert) => (
                <tr key={alert.id} className="hover:bg-muted/40">
                  <Td>
                    <Badge tone={alert.severity === 'critical' ? 'danger' : alert.severity === 'warning' ? 'warn' : 'neutral'}>
                      {alert.severity === 'critical' && <ShieldAlert className="h-3 w-3" />}
                      {alert.severity}
                    </Badge>
                  </Td>
                  <Td>
                    <p className="font-medium text-ink">{alert.title}</p>
                    <p className="max-w-2xl text-[12px] text-ink-faint">{alert.message}</p>
                    {alert.campaign_id && (
                      <Link
                        to={`/campaigns/${alert.campaign_id}/report`}
                        className="text-[12px] font-medium text-brand hover:underline"
                      >
                        View campaign
                      </Link>
                    )}
                  </Td>
                  <Td className="hidden text-ink-soft lg:table-cell">{alert.category}</Td>
                  <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint sm:table-cell">
                    {formatDateTime(alert.last_seen_at)}
                  </Td>
                  <Td className="text-right">
                    {alert.status === 'acknowledged' ? (
                      <Badge>acknowledged</Badge>
                    ) : (
                      canWrite && (
                        <Button size="sm" variant="ghost" onClick={() => void acknowledge(alert)}>
                          Acknowledge
                        </Button>
                      )
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      <Callout tone="info" title="These thresholds are our alert levels, not safe limits">
        <span className="inline-flex items-start gap-1.5">
          <Gauge className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          Amazon applies its own review criteria to your account. A rate below the thresholds here is not a guarantee
          that your sending is safe; always check the SES console as well. A complaint spike pauses sending at the
          warning level rather than waiting for the critical threshold.
        </span>
      </Callout>
    </div>
  );
}
