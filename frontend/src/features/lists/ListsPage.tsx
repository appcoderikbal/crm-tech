import { ListChecks, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Button,
  Callout,
  Card,
  Dialog,
  EmptyState,
  Field,
  Input,
  LoadingPanel,
  PageHeader,
  SearchInput,
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
import type { ContactList } from '@/lib/types';
import { formatDate, formatNumber } from '@/lib/utils';

export function ListsPage() {
  const { canWrite } = useAuth();
  const { notify } = useToast();

  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounced(search);
  const lists = useResource<ContactList[]>('/lists', { search: debouncedSearch });

  const [editing, setEditing] = useState<ContactList | 'new' | null>(null);
  const [deleting, setDeleting] = useState<ContactList | null>(null);
  const [busy, setBusy] = useState(false);

  const rows = lists.data ?? [];
  const unassigned = (lists.meta?.unassigned as number | undefined) ?? 0;
  const totalAssigned = rows.reduce((sum, list) => sum + list.contact_count, 0);

  async function remove(list: ContactList) {
    setBusy(true);
    try {
      const { data } = await api.delete<{ contacts_released: number }>(`/lists/${list.id}`, { confirm: true });
      notify(
        `List deleted. ${formatNumber(data.contacts_released)} contact(s) returned to the unassigned pool.`,
        'ok',
      );
      setDeleting(null);
      void lists.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not delete the list.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Lists"
        description="A contact belongs to one list at a time. Removing it returns it to the unassigned Contacts page."
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setEditing('new')}>
              <Plus className="h-3.5 w-3.5" /> New list
            </Button>
          )
        }
      />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <StatTile label="Lists" value={formatNumber(rows.length)} />
        <StatTile label="Contacts on a list" value={formatNumber(totalAssigned)} />
        <StatTile label="Unassigned contacts" value={formatNumber(unassigned)} />
      </div>

      <Card>
        <div className="border-b border-line px-4 py-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search lists…" className="max-w-sm" />
        </div>

        {lists.loading && rows.length === 0 ? (
          <LoadingPanel />
        ) : lists.error ? (
          <div className="p-4">
            <Callout tone="danger" title="Could not load lists">
              {lists.error}
            </Callout>
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={<ListChecks className="h-7 w-7" />}
            title="No lists yet"
            description="Create a list, then assign contacts to it from the Contacts page or during an import."
            action={
              canWrite && (
                <Button variant="primary" onClick={() => setEditing('new')}>
                  <Plus className="h-3.5 w-3.5" /> Create a list
                </Button>
              )
            }
          />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>List</Th>
                <Th className="text-right">Contacts</Th>
                <Th className="text-right">Sendable</Th>
                <Th className="hidden text-right sm:table-cell">Created</Th>
                <Th className="w-28" />
              </tr>
            </thead>
            <tbody>
              {rows.map((list) => (
                <tr key={list.id} className="hover:bg-muted/40">
                  <Td>
                    <Link to={`/lists/${list.id}`} className="font-medium text-ink hover:text-brand">
                      {list.name}
                    </Link>
                    {list.description && (
                      <span className="block max-w-xl truncate text-[12px] text-ink-faint">{list.description}</span>
                    )}
                  </Td>
                  <Td className="text-right tabular">{formatNumber(list.contact_count)}</Td>
                  <Td className="text-right tabular">
                    <span className={list.sendable_count < list.contact_count ? 'text-warn' : 'text-ink'}>
                      {formatNumber(list.sendable_count)}
                    </span>
                  </Td>
                  <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint sm:table-cell">
                    {formatDate(list.created_at)}
                  </Td>
                  <Td className="text-right">
                    {canWrite && (
                      <div className="flex justify-end gap-1">
                        <Button size="icon" variant="ghost" onClick={() => setEditing(list)} aria-label="Rename list">
                          <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button size="icon" variant="ghost" onClick={() => setDeleting(list)} aria-label="Delete list">
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {editing && (
        <ListFormDialog
          list={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            void lists.reload();
          }}
        />
      )}

      <Dialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={`Delete “${deleting?.name}”?`}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button variant="danger" loading={busy} onClick={() => deleting && void remove(deleting)}>
              Delete list, keep contacts
            </Button>
          </>
        }
      >
        <Callout tone="info" title="Contacts are not deleted">
          <span className="inline-flex items-center gap-1.5">
            <Users className="h-3.5 w-3.5" />
            {formatNumber(deleting?.contact_count ?? 0)} contact(s) will return to the unassigned Contacts page.
          </span>
        </Callout>
        <p className="mt-3 text-[13px] text-ink-faint">
          A list that is referenced by a campaign cannot be deleted until that campaign is removed.
        </p>
      </Dialog>
    </div>
  );
}

function ListFormDialog({
  list,
  onClose,
  onSaved,
}: {
  list: ContactList | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { notify } = useToast();
  const [name, setName] = useState(list?.name ?? '');
  const [description, setDescription] = useState(list?.description ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    setErrors({});
    setSaving(true);
    try {
      if (list) {
        await api.patch(`/lists/${list.id}`, { name, description });
        notify('List updated.', 'ok');
      } else {
        await api.post('/lists', { name, description });
        notify('List created.', 'ok');
      }
      onSaved();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors);
        if (Object.keys(error.fieldErrors).length === 0) notify(error.message, 'danger');
      } else {
        notify('Could not save the list.', 'danger');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={list ? 'Rename list' : 'New list'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} disabled={!name.trim()} onClick={() => void save()}>
            {list ? 'Save changes' : 'Create list'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Name" required error={errors.name}>
          <Input
            value={name}
            onChange={(event) => setName(event.target.value)}
            maxLength={190}
            autoFocus
            invalid={Boolean(errors.name)}
          />
        </Field>
        <Field label="Description" error={errors.description}>
          <Textarea rows={3} value={description} onChange={(event) => setDescription(event.target.value)} maxLength={1000} />
        </Field>
      </div>
    </Dialog>
  );
}
