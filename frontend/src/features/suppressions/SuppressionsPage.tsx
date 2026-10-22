import { Download, Plus, ShieldBan, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  Dialog,
  EmptyState,
  Field,
  Input,
  LoadingPanel,
  PageHeader,
  Pagination,
  SearchInput,
  Select,
  StatTile,
  Table,
  Td,
  Textarea,
  Th,
  useToast,
} from '@/components/ui';
import { useAuth } from '@/features/auth/AuthContext';
import { useDebounced, useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { Suppression } from '@/lib/types';
import { formatDateTime, formatNumber } from '@/lib/utils';

const REASON_TONES: Record<string, 'neutral' | 'warn' | 'danger' | 'outline'> = {
  complaint: 'danger',
  hard_bounce: 'danger',
  manual_block: 'warn',
  unsubscribe: 'neutral',
  invalid_address: 'outline',
  soft_bounce_threshold: 'warn',
};

export function SuppressionsPage() {
  const { canWrite, isAdmin } = useAuth();
  const { notify } = useToast();

  const [search, setSearch] = useState('');
  const [reason, setReason] = useState('');
  const [page, setPage] = useState(1);
  const [addOpen, setAddOpen] = useState(false);
  const [releasing, setReleasing] = useState<Suppression | null>(null);
  const [busy, setBusy] = useState(false);

  const debouncedSearch = useDebounced(search);
  const suppressions = useResource<Suppression[]>(
    '/suppressions',
    useMemo(() => ({ search: debouncedSearch, reason, page, per_page: 25 }), [debouncedSearch, reason, page]),
  );

  const rows = suppressions.data ?? [];
  const byReason = (suppressions.meta?.by_reason as Record<string, number> | undefined) ?? {};
  const reasons = (suppressions.meta?.reasons as string[] | undefined) ?? [];
  const total = suppressions.meta?.total ?? 0;

  async function release(entry: Suppression) {
    setBusy(true);
    try {
      await api.delete(`/suppressions/${entry.id}`, { notes: 'Released from the suppressions screen' });
      notify('Suppression released. This address can receive campaigns again.', 'ok');
      setReleasing(null);
      void suppressions.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not release the suppression.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Suppressions"
        description="Addresses excluded from every campaign. Suppression is global and survives list changes and re-imports."
        actions={
          <>
            <Button variant="secondary" onClick={() => api.download('/suppressions/export')}>
              <Download className="h-3.5 w-3.5" /> Export
            </Button>
            {canWrite && (
              <Button variant="primary" onClick={() => setAddOpen(true)}>
                <Plus className="h-3.5 w-3.5" /> Add address
              </Button>
            )}
          </>
        }
      />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatTile label="Total" value={formatNumber(total)} />
        <StatTile label="Complaints" value={formatNumber(byReason.complaint ?? 0)} tone={byReason.complaint ? 'danger' : 'neutral'} />
        <StatTile label="Hard bounces" value={formatNumber(byReason.hard_bounce ?? 0)} tone={byReason.hard_bounce ? 'danger' : 'neutral'} />
        <StatTile label="Unsubscribes" value={formatNumber(byReason.unsubscribe ?? 0)} />
        <StatTile label="Manual blocks" value={formatNumber(byReason.manual_block ?? 0)} />
        <StatTile label="Invalid" value={formatNumber(byReason.invalid_address ?? 0)} />
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
          <SearchInput
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Search addresses…"
            className="min-w-[14rem] flex-1"
          />
          <Select
            value={reason}
            onChange={(event) => {
              setReason(event.target.value);
              setPage(1);
            }}
            className="w-auto min-w-[12rem]"
            aria-label="Reason"
          >
            <option value="">Any reason</option>
            {reasons.map((value) => (
              <option key={value} value={value}>
                {value.replace(/_/g, ' ')}
              </option>
            ))}
          </Select>
        </div>

        {suppressions.loading && rows.length === 0 ? (
          <LoadingPanel />
        ) : rows.length === 0 ? (
          <EmptyState
            icon={<ShieldCheck className="h-7 w-7" />}
            title="No suppressed addresses"
            description="Unsubscribes, complaints and hard bounces land here automatically."
          />
        ) : (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>Address</Th>
                  <Th>Reason</Th>
                  <Th className="hidden lg:table-cell">Source</Th>
                  <Th className="hidden text-right sm:table-cell">Added</Th>
                  <Th className="w-28" />
                </tr>
              </thead>
              <tbody>
                {rows.map((entry) => (
                  <tr key={entry.id} className="hover:bg-muted/40">
                    <Td className="max-w-[20rem]">
                      {entry.contact_id ? (
                        <Link
                          to={`/contacts/${entry.contact_id}`}
                          className="block truncate font-medium text-ink hover:text-brand"
                        >
                          {entry.normalized_email}
                        </Link>
                      ) : (
                        <span className="block truncate font-medium text-ink">{entry.normalized_email}</span>
                      )}
                      {entry.notes && <span className="block truncate text-[12px] text-ink-faint">{entry.notes}</span>}
                    </Td>
                    <Td>
                      <Badge tone={REASON_TONES[entry.reason] ?? 'neutral'}>{entry.reason.replace(/_/g, ' ')}</Badge>
                    </Td>
                    <Td className="hidden max-w-[14rem] truncate text-[12px] text-ink-faint lg:table-cell">
                      {entry.campaign_id ? (
                        <Link to={`/campaigns/${entry.campaign_id}/report`} className="hover:text-brand">
                          {entry.campaign_name ?? entry.source}
                        </Link>
                      ) : (
                        entry.source
                      )}
                    </Td>
                    <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint sm:table-cell">
                      {formatDateTime(entry.created_at)}
                    </Td>
                    <Td className="text-right">
                      {isAdmin && (
                        <Button size="sm" variant="ghost" onClick={() => setReleasing(entry)}>
                          Release
                        </Button>
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>

            <Pagination
              page={suppressions.meta?.page ?? 1}
              pages={suppressions.meta?.pages ?? 1}
              total={total}
              perPage={suppressions.meta?.per_page ?? 25}
              onPage={setPage}
            />
          </>
        )}
      </Card>

      {addOpen && (
        <AddSuppressionDialog
          onClose={() => setAddOpen(false)}
          onSaved={() => {
            setAddOpen(false);
            void suppressions.reload();
          }}
        />
      )}

      <Dialog
        open={releasing !== null}
        onClose={() => setReleasing(null)}
        title={`Release ${releasing?.normalized_email}?`}
        description="This address becomes mailable again."
        footer={
          <>
            <Button variant="secondary" onClick={() => setReleasing(null)}>
              Keep suppressed
            </Button>
            <Button variant="danger" loading={busy} onClick={() => releasing && void release(releasing)}>
              Release suppression
            </Button>
          </>
        }
      >
        <Callout tone="warn" title="Think about why it was suppressed">
          Releasing an address that complained or hard bounced can damage your sending reputation. Only do this when
          the recipient has asked to be re-added. The action is recorded in the suppression audit trail.
        </Callout>
      </Dialog>
    </div>
  );
}

function AddSuppressionDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { notify } = useToast();
  const [email, setEmail] = useState('');
  const [reason, setReason] = useState('manual_block');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    setErrors({});
    setSaving(true);
    try {
      await api.post('/suppressions', { email, reason, notes });
      notify('Address suppressed.', 'ok');
      onSaved();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors);
        if (Object.keys(error.fieldErrors).length === 0) notify(error.message, 'danger');
      } else {
        notify('Could not add the suppression.', 'danger');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Suppress an address"
      description="The contact record, if one exists, is kept — only sending stops."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} disabled={!email.trim()} onClick={() => void save()}>
            <ShieldBan className="h-3.5 w-3.5" /> Suppress address
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Email address" required error={errors.email}>
          <Input
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoFocus
            invalid={Boolean(errors.email)}
          />
        </Field>
        <Field label="Reason" error={errors.reason}>
          <Select value={reason} onChange={(event) => setReason(event.target.value)}>
            <option value="manual_block">Manual block</option>
            <option value="unsubscribe">Unsubscribe</option>
            <option value="complaint">Spam complaint</option>
            <option value="hard_bounce">Hard bounce</option>
            <option value="invalid_address">Invalid address</option>
          </Select>
        </Field>
        <Field label="Notes" hint="Stored in the audit trail." error={errors.notes}>
          <Textarea rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={500} />
        </Field>
      </div>
    </Dialog>
  );
}
