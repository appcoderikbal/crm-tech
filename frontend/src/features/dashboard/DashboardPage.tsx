import { AlertTriangle, ArrowRight, Pause, RefreshCw, ShieldAlert } from 'lucide-react';
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
} from '@/components/ui';
import { CampaignStatusBadge } from '@/features/campaigns/CampaignStatusBadge';
import { usePoll, useResource } from '@/hooks/useResource';
import type { DashboardData } from '@/lib/types';
import { formatDateTime, formatNumber, formatPercent, formatRelative } from '@/lib/utils';

const RANGES = [
  { label: 'Last 7 days', days: 7 },
  { label: 'Last 30 days', days: 30 },
  { label: 'Last 90 days', days: 90 },
  { label: 'Last 12 months', days: 365 },
];

function rangeFrom(days: number): string {
  const date = new Date(Date.now() - days * 86400000);
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

const EVENT_TONES: Record<string, 'neutral' | 'ok' | 'warn' | 'danger' | 'brand'> = {
  delivery: 'ok',
  send: 'neutral',
  open: 'brand',
  click: 'brand',
  bounce: 'danger',
  complaint: 'danger',
  reject: 'danger',
  failure: 'danger',
  unsubscribe: 'warn',
  delivery_delay: 'warn',
};

export function DashboardPage() {
  const [days, setDays] = useState(30);
  const { data, loading, error, refresh } = useResource<DashboardData>('/reports/dashboard', {
    from: rangeFrom(days),
  });

  // The dashboard is an operations screen; keep it close to live.
  usePoll(refresh, 60_000, Boolean(data));

  if (loading && !data) return <LoadingPanel label="Loading dashboard…" />;
  if (error) return <Callout tone="danger" title="Could not load the dashboard">{error}</Callout>;
  if (!data) return null;

  const { metrics, rates, contacts, campaigns, sending_status: sending, health, alerts } = data;
  const criticalAlerts = alerts.filter((alert) => alert.severity === 'critical');

  return (
    <div className="space-y-5">
      <PageHeader
        title="Dashboard"
        description="Everything on this page is calculated from stored events — no estimates."
        actions={
          <>
            <Select value={days} onChange={(event) => setDays(Number(event.target.value))} className="w-40">
              {RANGES.map((range) => (
                <option key={range.days} value={range.days}>
                  {range.label}
                </option>
              ))}
            </Select>
            <Button variant="secondary" onClick={() => void refresh()}>
              <RefreshCw className="h-3.5 w-3.5" /> Refresh
            </Button>
          </>
        }
      />

      {!sending.can_send && (
        <Callout tone="danger" title={sending.kill_switch ? 'Sending is stopped' : 'Sending is disabled'}>
          {sending.kill_switch_reason || 'No new messages will be sent until this is released in Settings.'}{' '}
          <Link to="/settings" className="font-medium underline">
            Open settings
          </Link>
        </Callout>
      )}

      {criticalAlerts.length > 0 && (
        <Card className="border-danger/30">
          <CardHeader
            title={
              <span className="flex items-center gap-2 text-danger">
                <ShieldAlert className="h-4 w-4" aria-hidden /> {criticalAlerts.length} critical deliverability alert
                {criticalAlerts.length === 1 ? '' : 's'}
              </span>
            }
            action={
              <Link to="/reports">
                <Button size="sm" variant="secondary">
                  View all <ArrowRight className="h-3.5 w-3.5" />
                </Button>
              </Link>
            }
          />
          <CardBody className="space-y-2.5 py-3">
            {criticalAlerts.slice(0, 3).map((alert) => (
              <div key={alert.id} className="flex gap-2.5">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-danger" aria-hidden />
                <div className="min-w-0">
                  <p className="text-[13px] font-medium text-ink">{alert.title}</p>
                  <p className="text-[12px] text-ink-faint">{alert.message}</p>
                </div>
              </div>
            ))}
          </CardBody>
        </Card>
      )}

      {/* ---------------------------------------------------------- audience */}
      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">Audience</h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <StatTile label="Total contacts" value={formatNumber(contacts.total)} />
          <StatTile label="Unassigned" value={formatNumber(contacts.unassigned)} hint="Not on any list" />
          <StatTile label="Lists" value={formatNumber(data.lists.total)} />
          <StatTile label="Suppressed" value={formatNumber(contacts.suppressed)} tone={contacts.suppressed > 0 ? 'warn' : 'neutral'} />
          <StatTile label="Unsubscribed" value={formatNumber(contacts.unsubscribed)} />
        </div>
      </section>

      {/* --------------------------------------------------------- campaigns */}
      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">Campaigns</h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <StatTile label="Total" value={formatNumber(campaigns.total)} />
          <StatTile label="Draft" value={formatNumber(campaigns.draft)} />
          <StatTile label="Scheduled" value={formatNumber(campaigns.scheduled)} tone="brand" />
          <StatTile label="Sending now" value={formatNumber(campaigns.sending)} tone={campaigns.sending > 0 ? 'brand' : 'neutral'} />
          <StatTile label="Paused" value={formatNumber(campaigns.paused)} tone={campaigns.paused > 0 ? 'warn' : 'neutral'} />
          <StatTile label="Failed" value={formatNumber(campaigns.failed)} tone={campaigns.failed > 0 ? 'danger' : 'neutral'} />
        </div>
      </section>

      {/* ----------------------------------------------------------- sending */}
      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">
          Sending in the selected range
        </h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          <StatTile label="Attempted" value={formatNumber(metrics.attempted)} hint="Recipients the sender tried" />
          <StatTile label="Accepted by SES" value={formatNumber(metrics.accepted)} hint="API acceptance, not delivery" />
          <StatTile
            label="Confirmed deliveries"
            value={formatNumber(metrics.delivered)}
            hint={`${formatPercent(rates.delivery_rate)} of accepted`}
            tone="ok"
          />
          <StatTile label="Unique opens" value={formatNumber(metrics.unique_opens)} hint="Tracked engagement" />
          <StatTile label="Unique clicks" value={formatNumber(metrics.unique_clicks)} hint="Tracked engagement" />
          <StatTile
            label="Hard bounces"
            value={formatNumber(metrics.hard_bounces)}
            tone={metrics.hard_bounces > 0 ? 'danger' : 'neutral'}
          />
          <StatTile label="Soft bounces" value={formatNumber(metrics.soft_bounces)} tone={metrics.soft_bounces > 0 ? 'warn' : 'neutral'} />
          <StatTile
            label="Spam complaints"
            value={formatNumber(metrics.complaints)}
            tone={metrics.complaints > 0 ? 'danger' : 'neutral'}
          />
          <StatTile label="Unsubscribes" value={formatNumber(metrics.unsubscribes)} />
          <StatTile label="Sent today" value={formatNumber(sending.sent_today)} hint={`Cap ${formatNumber(sending.daily_cap)}/day`} />
        </div>
        <p className="mt-2 text-[12px] text-ink-faint">
          Opens and clicks are tracked engagement signals. Privacy proxies and security scanners can inflate or hide
          them.
        </p>
        {data.data_quality.length > 0 && (
          <Callout tone="warn" className="mt-3" title="These numbers are incomplete">
            <ul className="space-y-1">
              {data.data_quality.map((note) => (
                <li key={note}>{note}</li>
              ))}
            </ul>
          </Callout>
        )}
      </section>

      <div className="grid gap-4 lg:grid-cols-5">
        {/* ------------------------------------------------------- health */}
        <Card className="lg:col-span-2">
          <CardHeader title="System health" description="Grey means unknown, never assumed healthy." />
          <CardBody className="space-y-2.5">
            {Object.entries(health).map(([area, entry]) => (
              <div key={area} className="flex items-start gap-2.5">
                <StatusDot status={entry.status} className="mt-1.5" />
                <div className="min-w-0">
                  <p className="text-[13px] font-medium text-ink capitalize">{area.replace('_', ' ')}</p>
                  <p className="text-[12px] text-ink-faint">{entry.detail}</p>
                </div>
              </div>
            ))}
            <div className="flex items-start gap-2.5 border-t border-line pt-2.5">
              {sending.can_send ? <StatusDot status="green" className="mt-1.5" /> : <Pause className="mt-0.5 h-3.5 w-3.5 text-danger" />}
              <div>
                <p className="text-[13px] font-medium text-ink">Queue</p>
                <p className="text-[12px] text-ink-faint">
                  {formatNumber(sending.queued_recipients)} recipient(s) waiting across{' '}
                  {formatNumber(sending.campaigns_in_flight)} campaign(s).
                </p>
              </div>
            </div>
          </CardBody>
        </Card>

        {/* ---------------------------------------------- recent campaigns */}
        <Card className="lg:col-span-3">
          <CardHeader
            title="Recent campaigns"
            action={
              <Link to="/campaigns">
                <Button size="sm" variant="ghost">
                  All campaigns <ArrowRight className="h-3.5 w-3.5" />
                </Button>
              </Link>
            }
          />
          {data.recent_campaigns.length === 0 ? (
            <CardBody>
              <p className="py-6 text-center text-[13px] text-ink-faint">No campaigns yet.</p>
            </CardBody>
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Campaign</Th>
                  <Th>Status</Th>
                  <Th className="text-right">Accepted</Th>
                  <Th className="text-right">Delivered</Th>
                  <Th className="text-right">Opens</Th>
                </tr>
              </thead>
              <tbody>
                {data.recent_campaigns.map((campaign) => (
                  <tr key={campaign.id} className="hover:bg-muted/40">
                    <Td>
                      <Link
                        to={`/campaigns/${campaign.id}/report`}
                        className="font-medium text-ink hover:text-brand"
                      >
                        {campaign.name}
                      </Link>
                      <span className="block text-[12px] text-ink-faint">
                        {formatRelative(campaign.started_at ?? campaign.scheduled_at_utc ?? campaign.created_at)}
                      </span>
                    </Td>
                    <Td>
                      <CampaignStatusBadge status={campaign.status} />
                    </Td>
                    <Td className="text-right tabular">{formatNumber(campaign.accepted ?? 0)}</Td>
                    <Td className="text-right tabular">{formatNumber(campaign.delivered ?? 0)}</Td>
                    <Td className="text-right tabular">{formatNumber(campaign.unique_opens ?? 0)}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      </div>

      {/* ------------------------------------------------------ recent events */}
      <Card>
        <CardHeader title="Recent delivery events" description="Newest provider and tracking events." />
        {data.recent_events.length === 0 ? (
          <CardBody>
            <p className="py-6 text-center text-[13px] text-ink-faint">
              No events recorded yet. They appear once SES event publishing is configured and a campaign has sent.
            </p>
          </CardBody>
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Event</Th>
                <Th>Recipient</Th>
                <Th>Campaign</Th>
                <Th className="text-right">When</Th>
              </tr>
            </thead>
            <tbody>
              {data.recent_events.map((event) => (
                <tr key={event.id} className="hover:bg-muted/40">
                  <Td>
                    <Badge tone={EVENT_TONES[event.event_type] ?? 'neutral'}>
                      {event.event_type.replace('_', ' ')}
                      {event.event_subtype ? ` · ${event.event_subtype}` : ''}
                    </Badge>
                  </Td>
                  <Td className="max-w-[16rem] truncate text-ink-soft">{event.recipient_email_snapshot ?? '—'}</Td>
                  <Td className="max-w-[14rem] truncate">
                    {event.campaign_id ? (
                      <Link to={`/campaigns/${event.campaign_id}/report`} className="text-ink-soft hover:text-brand">
                        {event.campaign_name}
                      </Link>
                    ) : (
                      '—'
                    )}
                  </Td>
                  <Td className="text-right text-[12px] whitespace-nowrap text-ink-faint">
                    {formatDateTime(event.event_timestamp)}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  );
}
