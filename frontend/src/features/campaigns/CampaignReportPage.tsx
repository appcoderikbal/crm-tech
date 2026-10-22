import { ArrowLeft, HelpCircle, RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import {
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  LoadingPanel,
  PageHeader,
  Pagination,
  SearchInput,
  Select,
  StatTile,
  Table,
  Td,
  Th,
} from '@/components/ui';
import { CampaignStatusBadge, RecipientStatusBadge } from '@/features/campaigns/CampaignStatusBadge';
import { useAuth } from '@/features/auth/AuthContext';
import { useDebounced, usePoll, useResource } from '@/hooks/useResource';
import type { CampaignRecipient, CampaignReport } from '@/lib/types';
import { formatDateTime, formatNumber, formatPercent } from '@/lib/utils';

export function CampaignReportPage() {
  const { id } = useParams();
  const { user } = useAuth();

  const report = useResource<CampaignReport>(id ? `/campaigns/${id}/report` : null);
  const [statusFilter, setStatusFilter] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const debouncedSearch = useDebounced(search);

  const recipients = useResource<CampaignRecipient[]>(
    id ? `/campaigns/${id}/recipients` : null,
    useMemo(
      () => ({ status: statusFilter, search: debouncedSearch, page, per_page: 50 }),
      [statusFilter, debouncedSearch, page],
    ),
  );

  const active = report.data && ['queued', 'sending'].includes(report.data.campaign.status);
  usePoll(() => {
    void report.refresh();
    void recipients.refresh();
  }, 15_000, Boolean(active));

  const chartData = useMemo(() => {
    if (!report.data) return [];
    const buckets = new Map<string, Record<string, number | string>>();

    for (const point of report.data.timeline) {
      const existing = buckets.get(point.bucket) ?? { bucket: point.bucket.slice(5, 16) };
      existing[point.event_type] = Number(point.total);
      buckets.set(point.bucket, existing);
    }

    return Array.from(buckets.values()).slice(-48);
  }, [report.data]);

  if (report.loading && !report.data) return <LoadingPanel label="Building report…" />;
  if (report.error) return <Callout tone="danger" title="Could not load this report">{report.error}</Callout>;
  if (!report.data) return null;

  const { campaign, metrics, rates, denominators } = report.data;

  return (
    <div className="space-y-5">
      <Link to="/campaigns" className="inline-flex items-center gap-1 text-[13px] text-ink-faint hover:text-ink">
        <ArrowLeft className="h-3.5 w-3.5" /> Back to campaigns
      </Link>

      <PageHeader
        title={campaign.name}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <CampaignStatusBadge status={campaign.status} />
            <span className="text-ink-faint">{campaign.subject}</span>
          </span>
        }
        actions={
          <Button variant="secondary" onClick={() => void report.reload()}>
            <RefreshCw className="h-3.5 w-3.5" /> Refresh
          </Button>
        }
      />

      {campaign.status === 'failed' && campaign.last_error && (
        <Callout tone="danger" title="This campaign failed">{campaign.last_error}</Callout>
      )}

      {metrics.pending > 0 && (
        <Callout tone="info" title={`${formatNumber(metrics.pending)} recipient(s) still queued`}>
          The cron worker is still processing this campaign. Numbers update as it progresses.
        </Callout>
      )}

      {/* ------------------------------------------------------- delivery */}
      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">Delivery</h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <StatTile label="Recipients" value={formatNumber(metrics.total_recipients)} />
          <StatTile label="Attempted" value={formatNumber(metrics.attempted)} />
          <StatTile
            label="Accepted by SES"
            value={formatNumber(metrics.accepted)}
            hint="API acceptance only"
            tone="brand"
          />
          <StatTile
            label="Confirmed deliveries"
            value={formatNumber(metrics.delivered)}
            hint={`${formatPercent(rates.delivery_rate)} of accepted`}
            tone="ok"
          />
          <StatTile label="Pending" value={formatNumber(metrics.pending)} />
          <StatTile label="Suppressed" value={formatNumber(metrics.suppressed)} hint="Skipped before sending" />
        </div>
      </section>

      {/* ------------------------------------------------------ engagement */}
      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">
          Tracked engagement
        </h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <StatTile
            label="Unique opens"
            value={formatNumber(metrics.unique_opens)}
            hint={`${formatPercent(rates.open_rate)} of delivered`}
          />
          <StatTile label="Total opens" value={formatNumber(metrics.total_opens)} />
          <StatTile
            label="Unique clicks"
            value={formatNumber(metrics.unique_clicks)}
            hint={`${formatPercent(rates.click_rate)} of delivered`}
          />
          <StatTile label="Total clicks" value={formatNumber(metrics.total_clicks)} />
          <StatTile label="Click to open" value={formatPercent(rates.click_to_open)} />
          <StatTile
            label="Unsubscribes"
            value={formatNumber(metrics.unsubscribes)}
            hint={formatPercent(rates.unsubscribe_rate)}
          />
        </div>
      </section>

      {/* --------------------------------------------------------- problems */}
      <section>
        <h2 className="mb-2.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">Problems</h2>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <StatTile
            label="Hard bounces"
            value={formatNumber(metrics.hard_bounces)}
            hint={`${formatPercent(rates.hard_bounce_rate)} of attempted`}
            tone={metrics.hard_bounces > 0 ? 'danger' : 'neutral'}
          />
          <StatTile label="Soft bounces" value={formatNumber(metrics.soft_bounces)} tone={metrics.soft_bounces > 0 ? 'warn' : 'neutral'} />
          <StatTile label="Rejections" value={formatNumber(metrics.rejects)} tone={metrics.rejects > 0 ? 'danger' : 'neutral'} />
          <StatTile
            label="Spam complaints"
            value={formatNumber(metrics.complaints)}
            hint={`${formatPercent(rates.complaint_rate, 3)} of accepted`}
            tone={metrics.complaints > 0 ? 'danger' : 'neutral'}
          />
          <StatTile label="Failed" value={formatNumber(metrics.failed)} tone={metrics.failed > 0 ? 'danger' : 'neutral'} />
          <StatTile label="Cancelled" value={formatNumber(metrics.cancelled)} />
        </div>
      </section>

      {report.data.data_quality.length > 0 && (
        <Callout tone="warn" title="These numbers are incomplete">
          <ul className="space-y-1">
            {report.data.data_quality.map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        </Callout>
      )}

      <Callout tone="info" title="How these numbers are calculated">
        <ul className="mt-1 grid gap-x-6 gap-y-0.5 sm:grid-cols-2">
          {Object.entries(denominators).map(([metric, denominator]) => (
            <li key={metric} className="flex items-start gap-1.5">
              <HelpCircle className="mt-0.5 h-3 w-3 shrink-0 opacity-60" aria-hidden />
              <span>
                <strong>{metric.replace(/_/g, ' ')}</strong> ÷ {denominator}
              </span>
            </li>
          ))}
        </ul>
        <p className="mt-2">{report.data.tracking_note}</p>
      </Callout>

      {chartData.length > 1 && (
        <Card>
          <CardHeader title="Events over time" description="Hourly buckets, UTC." />
          <CardBody>
            <div className="h-64 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chartData} margin={{ top: 4, right: 8, left: -18, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-line)" vertical={false} />
                  <XAxis dataKey="bucket" tick={{ fontSize: 11, fill: 'var(--color-ink-faint)' }} tickLine={false} />
                  <YAxis tick={{ fontSize: 11, fill: 'var(--color-ink-faint)' }} tickLine={false} axisLine={false} />
                  <Tooltip
                    contentStyle={{
                      borderRadius: 8,
                      border: '1px solid var(--color-line)',
                      fontSize: 12,
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="delivery" stackId="a" fill="var(--color-ok)" name="Delivered" />
                  <Bar dataKey="open" stackId="a" fill="var(--color-brand)" name="Opens" />
                  <Bar dataKey="click" stackId="a" fill="#7c3aed" name="Clicks" />
                  <Bar dataKey="bounce" stackId="a" fill="var(--color-danger)" name="Bounces" />
                  <Bar dataKey="complaint" stackId="a" fill="#b45309" name="Complaints" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardBody>
        </Card>
      )}

      {report.data.links.length > 0 && (
        <Card>
          <CardHeader title="Link performance" description="Only links that were rewritten for click tracking." />
          <Table>
            <thead>
              <tr>
                <Th>Destination</Th>
                <Th className="text-right">Unique clicks</Th>
                <Th className="text-right">Total clicks</Th>
              </tr>
            </thead>
            <tbody>
              {report.data.links.map((link) => (
                <tr key={link.id}>
                  <Td className="max-w-[28rem] truncate text-ink-soft">{link.url}</Td>
                  <Td className="text-right tabular">{formatNumber(link.unique_clicks)}</Td>
                  <Td className="text-right tabular">{formatNumber(link.total_clicks)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      <Card>
        <CardHeader title="Recipients" description="Per-recipient outcome for this campaign." />
        <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Search by email…"
            className="min-w-[14rem] flex-1"
          />
          <Select
            value={statusFilter}
            onChange={(event) => {
              setStatusFilter(event.target.value);
              setPage(1);
            }}
            className="w-auto min-w-[12rem]"
            aria-label="Recipient status"
          >
            <option value="">Any outcome</option>
            <option value="accepted">Accepted by SES</option>
            <option value="delivered">Delivered</option>
            <option value="bounced">Bounced</option>
            <option value="complained">Complained</option>
            <option value="failed">Failed</option>
            <option value="suppressed">Suppressed</option>
            <option value="pending">Pending</option>
            <option value="needs_reconciliation">Needs reconciliation</option>
          </Select>
        </div>

        {recipients.loading && !recipients.data ? (
          <LoadingPanel />
        ) : (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>Recipient</Th>
                  <Th>Outcome</Th>
                  <Th className="hidden md:table-cell">Opened</Th>
                  <Th className="hidden md:table-cell">Clicked</Th>
                  <Th className="hidden lg:table-cell">Detail</Th>
                </tr>
              </thead>
              <tbody>
                {(recipients.data ?? []).map((recipient) => (
                  <tr key={recipient.id} className="hover:bg-muted/40">
                    <Td className="max-w-[18rem]">
                      {recipient.contact_id ? (
                        <Link
                          to={`/contacts/${recipient.contact_id}`}
                          className="block truncate font-medium text-ink hover:text-brand"
                        >
                          {recipient.recipient_email_snapshot}
                        </Link>
                      ) : (
                        <span className="block truncate text-ink">{recipient.recipient_email_snapshot}</span>
                      )}
                    </Td>
                    <Td>
                      <RecipientStatusBadge status={recipient.status} />
                    </Td>
                    <Td className="hidden text-[12px] whitespace-nowrap text-ink-faint md:table-cell">
                      {recipient.first_opened_at ? formatDateTime(recipient.first_opened_at, user?.timezone) : '—'}
                    </Td>
                    <Td className="hidden text-[12px] whitespace-nowrap text-ink-faint md:table-cell">
                      {recipient.first_clicked_at ? formatDateTime(recipient.first_clicked_at, user?.timezone) : '—'}
                    </Td>
                    <Td className="hidden max-w-[20rem] truncate text-[12px] text-ink-faint lg:table-cell">
                      {recipient.last_error_message || recipient.ses_message_id || '—'}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>

            <Pagination
              page={recipients.meta?.page ?? 1}
              pages={recipients.meta?.pages ?? 1}
              total={recipients.meta?.total ?? 0}
              perPage={recipients.meta?.per_page ?? 50}
              onPage={setPage}
            />
          </>
        )}
      </Card>
    </div>
  );
}
