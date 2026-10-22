import { Ban, Copy, Mail, Pause, Pencil, Play, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  Button,
  Callout,
  Card,
  Dialog,
  EmptyState,
  LoadingPanel,
  PageHeader,
  Pagination,
  SearchInput,
  Select,
  Table,
  Td,
  Th,
  useToast,
} from '@/components/ui';
import { CampaignStatusBadge } from '@/features/campaigns/CampaignStatusBadge';
import { useAuth } from '@/features/auth/AuthContext';
import { useDebounced, usePoll, useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { Campaign } from '@/lib/types';
import { formatDateTime, formatNumber, formatPercent } from '@/lib/utils';

export function CampaignsPage() {
  const { canWrite, user } = useAuth();
  const { notify } = useToast();
  const navigate = useNavigate();

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [confirm, setConfirm] = useState<{ campaign: Campaign; action: 'cancel' | 'delete' } | null>(null);
  const [busy, setBusy] = useState(false);

  const debouncedSearch = useDebounced(search);
  const query = useMemo(
    () => ({ search: debouncedSearch, status, page, per_page: 25 }),
    [debouncedSearch, status, page],
  );

  const campaigns = useResource<Campaign[]>('/campaigns', query);
  const rows = campaigns.data ?? [];

  // Live-ish refresh while anything is in flight.
  const hasActive = rows.some((row) => ['queued', 'sending'].includes(row.status));
  usePoll(campaigns.refresh, 15_000, hasActive);

  async function act(campaign: Campaign, action: 'pause' | 'resume' | 'cancel' | 'duplicate' | 'delete') {
    setBusy(true);
    try {
      if (action === 'delete') {
        await api.delete(`/campaigns/${campaign.id}`);
        notify('Campaign deleted.', 'ok');
      } else if (action === 'duplicate') {
        const { data } = await api.post<Campaign>(`/campaigns/${campaign.id}/duplicate`);
        notify('Campaign duplicated as a draft.', 'ok');
        navigate(`/campaigns/${data.id}/edit`);
        return;
      } else {
        await api.post(`/campaigns/${campaign.id}/${action}`);
        notify(
          action === 'pause' ? 'Campaign paused.' : action === 'resume' ? 'Campaign resumed.' : 'Campaign cancelled.',
          'ok',
        );
      }
      setConfirm(null);
      void campaigns.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'That action could not be completed.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Campaigns"
        description="Scheduled campaigns are sent by the server-side cron worker — no browser needs to stay open."
        actions={
          canWrite && (
            <Link to="/campaigns/new">
              <Button variant="primary">
                <Plus className="h-3.5 w-3.5" /> New campaign
              </Button>
            </Link>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Search campaigns…"
            className="min-w-[14rem] flex-1"
          />
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
            className="w-auto min-w-[11rem]"
            aria-label="Status filter"
          >
            <option value="">Any status</option>
            <option value="draft">Draft</option>
            <option value="scheduled">Scheduled</option>
            <option value="queued,sending">Sending</option>
            <option value="paused">Paused</option>
            <option value="completed">Completed</option>
            <option value="failed">Failed</option>
            <option value="cancelled">Cancelled</option>
          </Select>
        </div>

        {campaigns.loading && rows.length === 0 ? (
          <LoadingPanel />
        ) : campaigns.error ? (
          <div className="p-4">
            <Callout tone="danger" title="Could not load campaigns">
              {campaigns.error}
            </Callout>
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={<Mail className="h-7 w-7" />}
            title="No campaigns yet"
            description="Create a campaign, pick a list, write the email, then schedule it."
            action={
              canWrite && (
                <Link to="/campaigns/new">
                  <Button variant="primary">
                    <Plus className="h-3.5 w-3.5" /> New campaign
                  </Button>
                </Link>
              )
            }
          />
        ) : (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>Campaign</Th>
                  <Th>Status</Th>
                  <Th className="hidden lg:table-cell">List</Th>
                  <Th className="text-right">Accepted</Th>
                  <Th className="text-right">Delivered</Th>
                  <Th className="hidden text-right md:table-cell">Opens</Th>
                  <Th className="hidden text-right md:table-cell">Bounced</Th>
                  <Th className="hidden text-right xl:table-cell">Scheduled</Th>
                  <Th className="w-36" />
                </tr>
              </thead>
              <tbody>
                {rows.map((campaign) => {
                  const deliveryRate =
                    campaign.accepted && campaign.accepted > 0
                      ? ((campaign.delivered ?? 0) / campaign.accepted) * 100
                      : null;

                  return (
                    <tr key={campaign.id} className="hover:bg-muted/40">
                      <Td className="max-w-[18rem]">
                        <Link
                          to={
                            campaign.status === 'draft'
                              ? `/campaigns/${campaign.id}/edit`
                              : `/campaigns/${campaign.id}/report`
                          }
                          className="block truncate font-medium text-ink hover:text-brand"
                        >
                          {campaign.name}
                        </Link>
                        <span className="block truncate text-[12px] text-ink-faint">{campaign.subject || 'No subject'}</span>
                      </Td>
                      <Td>
                        <CampaignStatusBadge status={campaign.status} />
                        {campaign.status === 'failed' && campaign.last_error && (
                          <span className="mt-0.5 block max-w-[14rem] truncate text-[11px] text-danger">
                            {campaign.last_error}
                          </span>
                        )}
                      </Td>
                      <Td className="hidden max-w-[12rem] truncate lg:table-cell">
                        {campaign.list_id ? (
                          <Link to={`/lists/${campaign.list_id}`} className="text-ink-soft hover:text-brand">
                            {campaign.list_name}
                          </Link>
                        ) : (
                          <span className="text-ink-faint">—</span>
                        )}
                      </Td>
                      <Td className="text-right tabular">{formatNumber(campaign.accepted ?? 0)}</Td>
                      <Td className="text-right tabular">
                        {formatNumber(campaign.delivered ?? 0)}
                        {deliveryRate !== null && (
                          <span className="block text-[11px] text-ink-faint">{formatPercent(deliveryRate, 1)}</span>
                        )}
                      </Td>
                      <Td className="hidden text-right tabular md:table-cell">{formatNumber(campaign.unique_opens ?? 0)}</Td>
                      <Td className="hidden text-right tabular md:table-cell">
                        <span className={(campaign.hard_bounces ?? 0) > 0 ? 'text-danger' : ''}>
                          {formatNumber((campaign.hard_bounces ?? 0) + (campaign.soft_bounces ?? 0))}
                        </span>
                      </Td>
                      <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint xl:table-cell">
                        {campaign.scheduled_at_utc
                          ? formatDateTime(campaign.scheduled_at_utc, user?.timezone)
                          : '—'}
                      </Td>
                      <Td className="text-right">
                        {canWrite && (
                          <div className="flex justify-end gap-0.5">
                            {['draft', 'scheduled', 'failed'].includes(campaign.status) && (
                              <Link to={`/campaigns/${campaign.id}/edit`}>
                                <Button size="icon" variant="ghost" aria-label="Edit campaign">
                                  <Pencil className="h-3.5 w-3.5" />
                                </Button>
                              </Link>
                            )}
                            {['queued', 'sending'].includes(campaign.status) && (
                              <Button
                                size="icon"
                                variant="ghost"
                                onClick={() => void act(campaign, 'pause')}
                                aria-label="Pause campaign"
                              >
                                <Pause className="h-3.5 w-3.5" />
                              </Button>
                            )}
                            {campaign.status === 'paused' && (
                              <Button
                                size="icon"
                                variant="ghost"
                                onClick={() => void act(campaign, 'resume')}
                                aria-label="Resume campaign"
                              >
                                <Play className="h-3.5 w-3.5" />
                              </Button>
                            )}
                            <Button
                              size="icon"
                              variant="ghost"
                              onClick={() => void act(campaign, 'duplicate')}
                              aria-label="Duplicate campaign"
                            >
                              <Copy className="h-3.5 w-3.5" />
                            </Button>
                            {['scheduled', 'queued', 'sending', 'paused'].includes(campaign.status) && (
                              <Button
                                size="icon"
                                variant="ghost"
                                onClick={() => setConfirm({ campaign, action: 'cancel' })}
                                aria-label="Cancel campaign"
                              >
                                <Ban className="h-3.5 w-3.5" />
                              </Button>
                            )}
                            {['draft', 'cancelled', 'failed'].includes(campaign.status) && (
                              <Button
                                size="icon"
                                variant="ghost"
                                onClick={() => setConfirm({ campaign, action: 'delete' })}
                                aria-label="Delete campaign"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            )}
                          </div>
                        )}
                      </Td>
                    </tr>
                  );
                })}
              </tbody>
            </Table>

            <Pagination
              page={campaigns.meta?.page ?? 1}
              pages={campaigns.meta?.pages ?? 1}
              total={campaigns.meta?.total ?? 0}
              perPage={campaigns.meta?.per_page ?? 25}
              onPage={setPage}
            />
          </>
        )}
      </Card>

      <Dialog
        open={confirm !== null}
        onClose={() => setConfirm(null)}
        title={
          confirm?.action === 'cancel'
            ? `Cancel “${confirm.campaign.name}”?`
            : `Delete “${confirm?.campaign.name}”?`
        }
        description={
          confirm?.action === 'cancel'
            ? 'Recipients that have not been sent to yet will be marked cancelled. Messages already accepted by SES cannot be recalled.'
            : 'This permanently removes the campaign and its recipient records.'
        }
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirm(null)}>
              Keep campaign
            </Button>
            <Button
              variant="danger"
              loading={busy}
              onClick={() => confirm && void act(confirm.campaign, confirm.action)}
            >
              {confirm?.action === 'cancel' ? 'Cancel campaign' : 'Delete campaign'}
            </Button>
          </>
        }
      />
    </div>
  );
}
