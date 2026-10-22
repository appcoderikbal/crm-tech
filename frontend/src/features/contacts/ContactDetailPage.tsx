import { ArrowLeft, Pencil, ShieldBan, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  Dialog,
  Field,
  LoadingPanel,
  PageHeader,
  Select,
  Table,
  Td,
  Textarea,
  Th,
  useToast,
} from '@/components/ui';
import { RecipientStatusBadge } from '@/features/campaigns/CampaignStatusBadge';
import { ContactFormDialog } from '@/features/contacts/ContactFormDialog';
import { useAuth } from '@/features/auth/AuthContext';
import { useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { Contact } from '@/lib/types';
import { formatDateTime } from '@/lib/utils';

interface CampaignHistoryRow {
  campaign_id: number;
  name: string;
  status: string;
  accepted_at: string | null;
  delivered_at: string | null;
  bounced_at: string | null;
  complained_at: string | null;
  first_opened_at: string | null;
  first_clicked_at: string | null;
}

interface SuppressionHistoryRow {
  id: number;
  action: string;
  reason: string;
  source: string;
  notes: string;
  created_at: string;
  actor_email: string | null;
}

interface DetailResponse {
  contact: Contact;
  campaign_history: CampaignHistoryRow[];
  suppression_history: SuppressionHistoryRow[];
}

export function ContactDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { canWrite, isAdmin } = useAuth();
  const { notify } = useToast();

  const { data, loading, error, reload } = useResource<DetailResponse>(id ? `/contacts/${id}` : null);
  const [editing, setEditing] = useState(false);
  const [suppressOpen, setSuppressOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [reason, setReason] = useState('manual_block');
  const [notes, setNotes] = useState('');

  if (loading && !data) return <LoadingPanel />;
  if (error) return <Callout tone="danger" title="Could not load this contact">{error}</Callout>;
  if (!data) return null;

  const { contact } = data;
  const name = [contact.first_name, contact.last_name].filter(Boolean).join(' ');

  async function suppress() {
    setBusy(true);
    try {
      await api.post('/suppressions', { email: contact.email, reason, notes });
      notify('Contact suppressed. They will be skipped by every campaign.', 'ok');
      setSuppressOpen(false);
      void reload();
    } catch (caught) {
      notify(caught instanceof ApiError ? caught.message : 'Could not suppress the contact.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function removeContact() {
    setBusy(true);
    try {
      await api.delete(`/contacts/${contact.id}`);
      notify('Contact deleted.', 'ok');
      navigate('/contacts');
    } catch (caught) {
      notify(caught instanceof ApiError ? caught.message : 'Could not delete the contact.', 'danger');
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <Link to="/contacts" className="inline-flex items-center gap-1 text-[13px] text-ink-faint hover:text-ink">
        <ArrowLeft className="h-3.5 w-3.5" /> Back to contacts
      </Link>

      <PageHeader
        title={name || contact.email}
        description={name ? contact.email : undefined}
        actions={
          canWrite && (
            <>
              <Button variant="secondary" onClick={() => setEditing(true)}>
                <Pencil className="h-3.5 w-3.5" /> Edit
              </Button>
              {!contact.is_suppressed && (
                <Button variant="secondary" onClick={() => setSuppressOpen(true)}>
                  <ShieldBan className="h-3.5 w-3.5" /> Suppress
                </Button>
              )}
              <Button variant="danger" onClick={() => setDeleteOpen(true)}>
                <Trash2 className="h-3.5 w-3.5" /> Delete
              </Button>
            </>
          )
        }
      />

      {contact.is_suppressed && (
        <Callout tone="danger" title={`Suppressed — ${contact.suppression_reason?.replace('_', ' ')}`}>
          This address is excluded from every campaign. Suppression is global and survives list changes, re-imports and
          contact deletion.{' '}
          {isAdmin && (
            <Link to="/suppressions" className="font-medium underline">
              Manage suppressions
            </Link>
          )}
        </Callout>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader title="Details" />
          <CardBody>
            <dl className="space-y-3 text-[13px]">
              {[
                ['Email', contact.email],
                ['First name', contact.first_name || '—'],
                ['Last name', contact.last_name || '—'],
                ['Company', contact.company_name || '—'],
                ['Website', contact.website || '—'],
                ['Country', contact.country || '—'],
                ['Source', contact.source || '—'],
                ['Created', formatDateTime(contact.created_at)],
                ['Updated', formatDateTime(contact.updated_at)],
              ].map(([label, value]) => (
                <div key={label} className="flex justify-between gap-3">
                  <dt className="shrink-0 text-ink-faint">{label}</dt>
                  <dd className="truncate text-right font-medium text-ink">{value}</dd>
                </div>
              ))}

              <div className="flex justify-between gap-3 border-t border-line pt-3">
                <dt className="text-ink-faint">List</dt>
                <dd className="text-right">
                  {contact.list_id ? (
                    <Link to={`/lists/${contact.list_id}`} className="font-medium text-brand hover:underline">
                      {contact.list_name}
                    </Link>
                  ) : (
                    <Badge>Unassigned</Badge>
                  )}
                </dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-ink-faint">Status</dt>
                <dd className="text-right">
                  <Badge tone={contact.status === 'active' ? 'ok' : 'neutral'}>{contact.status}</Badge>
                </dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-ink-faint">Subscription</dt>
                <dd className="text-right">
                  {contact.is_unsubscribed ? <Badge tone="warn">Unsubscribed</Badge> : <Badge tone="ok">Subscribed</Badge>}
                </dd>
              </div>
            </dl>

            {Object.keys(contact.custom_fields).length > 0 && (
              <div className="mt-4 border-t border-line pt-3">
                <p className="mb-2 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">Custom fields</p>
                <dl className="space-y-2 text-[13px]">
                  {Object.entries(contact.custom_fields).map(([key, value]) => (
                    <div key={key} className="flex justify-between gap-3">
                      <dt className="text-ink-faint">{key}</dt>
                      <dd className="truncate text-right text-ink">{value}</dd>
                    </div>
                  ))}
                </dl>
              </div>
            )}
          </CardBody>
        </Card>

        <div className="space-y-4 lg:col-span-2">
          <Card>
            <CardHeader title="Campaign history" description="Every campaign this contact was a recipient of." />
            {data.campaign_history.length === 0 ? (
              <CardBody>
                <p className="py-5 text-center text-[13px] text-ink-faint">No campaigns yet.</p>
              </CardBody>
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>Campaign</Th>
                    <Th>Result</Th>
                    <Th className="hidden sm:table-cell">Opened</Th>
                    <Th className="hidden sm:table-cell">Clicked</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.campaign_history.map((row) => (
                    <tr key={row.campaign_id} className="hover:bg-muted/40">
                      <Td className="max-w-[16rem] truncate">
                        <Link to={`/campaigns/${row.campaign_id}/report`} className="font-medium text-ink hover:text-brand">
                          {row.name}
                        </Link>
                      </Td>
                      <Td>
                        <RecipientStatusBadge status={row.status} />
                      </Td>
                      <Td className="hidden text-[12px] text-ink-faint sm:table-cell">
                        {row.first_opened_at ? formatDateTime(row.first_opened_at) : '—'}
                      </Td>
                      <Td className="hidden text-[12px] text-ink-faint sm:table-cell">
                        {row.first_clicked_at ? formatDateTime(row.first_clicked_at) : '—'}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Suppression history"
              description="Append-only audit trail for this address."
            />
            {data.suppression_history.length === 0 ? (
              <CardBody>
                <p className="flex items-center justify-center gap-2 py-5 text-center text-[13px] text-ink-faint">
                  <ShieldCheck className="h-4 w-4" /> No suppression events recorded.
                </p>
              </CardBody>
            ) : (
              <Table>
                <thead>
                  <tr>
                    <Th>Action</Th>
                    <Th>Reason</Th>
                    <Th className="hidden sm:table-cell">Source</Th>
                    <Th className="text-right">When</Th>
                  </tr>
                </thead>
                <tbody>
                  {data.suppression_history.map((row) => (
                    <tr key={row.id}>
                      <Td>
                        <Badge tone={row.action === 'removed' ? 'ok' : row.action === 'added' ? 'danger' : 'neutral'}>
                          {row.action}
                        </Badge>
                      </Td>
                      <Td className="text-ink-soft">{row.reason?.replace('_', ' ') || '—'}</Td>
                      <Td className="hidden text-[12px] text-ink-faint sm:table-cell">{row.source}</Td>
                      <Td className="text-right text-[12px] whitespace-nowrap text-ink-faint">
                        {formatDateTime(row.created_at)}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        </div>
      </div>

      {editing && (
        <ContactFormDialog
          contact={contact}
          onClose={() => setEditing(false)}
          onSaved={() => {
            setEditing(false);
            void reload();
          }}
        />
      )}

      <Dialog
        open={suppressOpen}
        onClose={() => setSuppressOpen(false)}
        title="Suppress this contact"
        description="The contact record is kept; the address simply stops receiving mail."
        footer={
          <>
            <Button variant="secondary" onClick={() => setSuppressOpen(false)}>
              Cancel
            </Button>
            <Button variant="danger" loading={busy} onClick={() => void suppress()}>
              Suppress address
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Reason">
            <Select value={reason} onChange={(event) => setReason(event.target.value)}>
              <option value="manual_block">Manual block</option>
              <option value="unsubscribe">Unsubscribe</option>
              <option value="complaint">Spam complaint</option>
              <option value="hard_bounce">Hard bounce</option>
              <option value="invalid_address">Invalid address</option>
            </Select>
          </Field>
          <Field label="Notes" hint="Recorded in the suppression audit trail.">
            <Textarea rows={3} value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={500} />
          </Field>
        </div>
      </Dialog>

      <Dialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title="Delete this contact?"
        description="This cannot be undone."
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleteOpen(false)}>
              Cancel
            </Button>
            <Button variant="danger" loading={busy} onClick={() => void removeContact()}>
              Delete contact
            </Button>
          </>
        }
      >
        <Callout tone="warn" title="Suppression is not deleted">
          If this address is suppressed, that record stays. Re-importing the same address later will not make it
          mailable again.
        </Callout>
      </Dialog>
    </div>
  );
}
