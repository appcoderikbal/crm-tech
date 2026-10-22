import { ArrowLeft, MoveRight, UserMinus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  Checkbox,
  Dialog,
  EmptyState,
  Field,
  LoadingPanel,
  PageHeader,
  Pagination,
  SearchInput,
  Select,
  StatTile,
  Table,
  Td,
  Th,
  useToast,
} from '@/components/ui';
import { CampaignStatusBadge } from '@/features/campaigns/CampaignStatusBadge';
import { useAuth } from '@/features/auth/AuthContext';
import { useDebounced, useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { Campaign, Contact, ContactList } from '@/lib/types';
import { formatDate, formatNumber } from '@/lib/utils';

export function ListDetailPage() {
  const { id } = useParams();
  const { canWrite } = useAuth();
  const { notify } = useToast();

  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [moveOpen, setMoveOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [targetList, setTargetList] = useState<number | ''>('');

  const debouncedSearch = useDebounced(search);

  const detail = useResource<{ list: ContactList; campaign_history: Campaign[] }>(id ? `/lists/${id}` : null);
  const members = useResource<Contact[]>(
    id ? `/lists/${id}/members` : null,
    useMemo(() => ({ search: debouncedSearch, page, per_page: 25 }), [debouncedSearch, page]),
  );
  const allLists = useResource<ContactList[]>('/lists');

  const rows = members.data ?? [];
  const allOnPageSelected = rows.length > 0 && rows.every((row) => selected.has(row.id));

  async function removeSelected() {
    setBusy(true);
    try {
      const { data } = await api.post<{ removed: number }>(`/lists/${id}/remove`, {
        contact_ids: Array.from(selected),
      });
      notify(`${formatNumber(data.removed)} contact(s) returned to the unassigned Contacts page.`, 'ok');
      setSelected(new Set());
      void members.reload();
      void detail.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not remove the contacts.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function moveSelected() {
    if (targetList === '') return;
    setBusy(true);
    try {
      const { data } = await api.post<{ moved: number; assigned: number }>(`/lists/${targetList}/assign`, {
        contact_ids: Array.from(selected),
      });
      notify(`${formatNumber(data.moved + data.assigned)} contact(s) moved.`, 'ok');
      setMoveOpen(false);
      setSelected(new Set());
      void members.reload();
      void detail.reload();
      void allLists.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not move the contacts.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  if (detail.loading && !detail.data) return <LoadingPanel />;
  if (detail.error) return <Callout tone="danger" title="Could not load this list">{detail.error}</Callout>;
  if (!detail.data) return null;

  const { list, campaign_history: campaigns } = detail.data;
  const suppressedCount = list.contact_count - list.sendable_count;

  return (
    <div className="space-y-5">
      <Link to="/lists" className="inline-flex items-center gap-1 text-[13px] text-ink-faint hover:text-ink">
        <ArrowLeft className="h-3.5 w-3.5" /> Back to lists
      </Link>

      <PageHeader title={list.name} description={list.description || undefined} />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatTile label="Members" value={formatNumber(list.contact_count)} />
        <StatTile label="Sendable" value={formatNumber(list.sendable_count)} tone="ok" />
        <StatTile
          label="Excluded"
          value={formatNumber(suppressedCount)}
          hint="Suppressed or unsubscribed"
          tone={suppressedCount > 0 ? 'warn' : 'neutral'}
        />
        <StatTile label="Campaigns" value={formatNumber(campaigns.length)} />
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Search members…"
            className="min-w-[14rem] flex-1"
          />
        </div>

        {canWrite && selected.size > 0 && (
          <div className="flex flex-wrap items-center gap-3 border-b border-line bg-brand-soft/60 px-4 py-2.5">
            <span className="text-[13px] font-medium text-brand-strong">{selected.size} selected</span>
            <div className="ml-auto flex gap-2">
              <Button size="sm" variant="secondary" onClick={() => setMoveOpen(true)}>
                <MoveRight className="h-3.5 w-3.5" /> Move to another list
              </Button>
              <Button size="sm" variant="danger" loading={busy} onClick={() => void removeSelected()}>
                <UserMinus className="h-3.5 w-3.5" /> Remove from list
              </Button>
            </div>
          </div>
        )}

        {members.loading && rows.length === 0 ? (
          <LoadingPanel />
        ) : rows.length === 0 ? (
          <EmptyState
            title="No members yet"
            description="Assign contacts to this list from the Contacts page, or target it during a CSV import."
            action={
              <Link to="/contacts">
                <Button variant="primary">Go to contacts</Button>
              </Link>
            }
          />
        ) : (
          <>
            <Table>
              <thead>
                <tr>
                  {canWrite && (
                    <Th className="w-10">
                      <Checkbox
                        checked={allOnPageSelected}
                        indeterminate={selected.size > 0 && !allOnPageSelected}
                        onChange={(checked) =>
                          setSelected((current) => {
                            const next = new Set(current);
                            rows.forEach((row) => (checked ? next.add(row.id) : next.delete(row.id)));
                            return next;
                          })
                        }
                      />
                    </Th>
                  )}
                  <Th>Email</Th>
                  <Th>Name</Th>
                  <Th className="hidden md:table-cell">Company</Th>
                  <Th>Status</Th>
                  <Th className="hidden text-right sm:table-cell">Assigned</Th>
                </tr>
              </thead>
              <tbody>
                {rows.map((contact) => (
                  <tr key={contact.id} className="hover:bg-muted/40">
                    {canWrite && (
                      <Td>
                        <Checkbox
                          checked={selected.has(contact.id)}
                          onChange={(checked) =>
                            setSelected((current) => {
                              const next = new Set(current);
                              if (checked) next.add(contact.id);
                              else next.delete(contact.id);
                              return next;
                            })
                          }
                        />
                      </Td>
                    )}
                    <Td className="max-w-[18rem]">
                      <Link to={`/contacts/${contact.id}`} className="block truncate font-medium text-ink hover:text-brand">
                        {contact.email}
                      </Link>
                    </Td>
                    <Td className="max-w-[12rem] truncate text-ink-soft">
                      {[contact.first_name, contact.last_name].filter(Boolean).join(' ') || '—'}
                    </Td>
                    <Td className="hidden max-w-[12rem] truncate text-ink-soft md:table-cell">
                      {contact.company_name || '—'}
                    </Td>
                    <Td>
                      {contact.is_suppressed ? (
                        <Badge tone="danger">{contact.suppression_reason?.replace('_', ' ')}</Badge>
                      ) : contact.is_unsubscribed ? (
                        <Badge tone="warn">unsubscribed</Badge>
                      ) : (
                        <Badge tone="ok">sendable</Badge>
                      )}
                    </Td>
                    <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint sm:table-cell">
                      {formatDate(contact.assigned_at)}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>

            <Pagination
              page={members.meta?.page ?? 1}
              pages={members.meta?.pages ?? 1}
              total={members.meta?.total ?? 0}
              perPage={members.meta?.per_page ?? 25}
              onPage={setPage}
            />
          </>
        )}
      </Card>

      <Card>
        <CardHeader title="Campaign history" description="Campaigns that targeted this list." />
        {campaigns.length === 0 ? (
          <CardBody>
            <p className="py-5 text-center text-[13px] text-ink-faint">No campaigns have used this list yet.</p>
          </CardBody>
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Campaign</Th>
                <Th>Status</Th>
                <Th className="text-right">Recipients</Th>
                <Th className="hidden text-right sm:table-cell">Started</Th>
              </tr>
            </thead>
            <tbody>
              {campaigns.map((campaign) => (
                <tr key={campaign.id} className="hover:bg-muted/40">
                  <Td>
                    <Link to={`/campaigns/${campaign.id}/report`} className="font-medium text-ink hover:text-brand">
                      {campaign.name}
                    </Link>
                  </Td>
                  <Td>
                    <CampaignStatusBadge status={campaign.status} />
                  </Td>
                  <Td className="text-right tabular">{formatNumber(campaign.total_recipients)}</Td>
                  <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint sm:table-cell">
                    {formatDate(campaign.started_at ?? campaign.scheduled_at_utc)}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      <Dialog
        open={moveOpen}
        onClose={() => setMoveOpen(false)}
        title={`Move ${selected.size} contact(s)`}
        description="The contact's membership of this list is replaced, not duplicated."
        footer={
          <>
            <Button variant="secondary" onClick={() => setMoveOpen(false)}>
              Cancel
            </Button>
            <Button variant="primary" loading={busy} disabled={targetList === ''} onClick={() => void moveSelected()}>
              Move contacts
            </Button>
          </>
        }
      >
        <Field label="Destination list">
          <Select value={targetList} onChange={(event) => setTargetList(Number(event.target.value) || '')}>
            <option value="">Choose a list…</option>
            {(allLists.data ?? [])
              .filter((item) => String(item.id) !== id)
              .map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name} ({formatNumber(item.contact_count)})
                </option>
              ))}
          </Select>
        </Field>
      </Dialog>
    </div>
  );
}
