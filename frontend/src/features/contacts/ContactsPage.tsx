import { Download, ListPlus, Plus, Trash2, Upload, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  Checkbox,
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
  Th,
  useToast,
} from '@/components/ui';
import { ContactFormDialog } from '@/features/contacts/ContactFormDialog';
import { useAuth } from '@/features/auth/AuthContext';
import { useDebounced, useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { Contact, ContactCounts, ContactList } from '@/lib/types';
import { formatDate } from '@/lib/utils';

type Assignment = 'unassigned' | 'assigned' | 'any';

export function ContactsPage() {
  const { canWrite } = useAuth();
  const { notify } = useToast();

  const [assignment, setAssignment] = useState<Assignment>('unassigned');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [country, setCountry] = useState('');
  const [suppressed, setSuppressed] = useState('');
  const [sort, setSort] = useState('created_at');
  const [direction, setDirection] = useState<'asc' | 'desc'>('desc');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [selectAllMatching, setSelectAllMatching] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [editing, setEditing] = useState<Contact | null | 'new'>(null);
  const [busy, setBusy] = useState(false);

  const debouncedSearch = useDebounced(search);

  const query = useMemo(
    () => ({
      assignment,
      search: debouncedSearch,
      status,
      country,
      suppressed,
      sort,
      direction,
      page,
      per_page: perPage,
    }),
    [assignment, debouncedSearch, status, country, suppressed, sort, direction, page, perPage],
  );

  const contacts = useResource<Contact[]>('/contacts', query);
  const meta = contacts.meta;
  const counts = (meta?.counts as ContactCounts | undefined) ?? null;

  const lists = useResource<ContactList[]>('/lists');
  const filterMeta = useResource<{ countries: string[]; sources: string[]; statuses: string[] }>('/contacts/meta');

  const rows = contacts.data ?? [];
  const total = meta?.total ?? 0;
  const allOnPageSelected = rows.length > 0 && rows.every((row) => selected.has(row.id));

  function resetSelection() {
    setSelected(new Set());
    setSelectAllMatching(false);
  }

  function toggleRow(id: number, checked: boolean) {
    setSelected((current) => {
      const next = new Set(current);
      if (checked) next.add(id);
      else next.delete(id);
      return next;
    });
    setSelectAllMatching(false);
  }

  function toggleSort(column: string) {
    if (sort === column) {
      setDirection(direction === 'asc' ? 'desc' : 'asc');
    } else {
      setSort(column);
      setDirection('asc');
    }
    setPage(1);
  }

  function changeFilter(apply: () => void) {
    apply();
    setPage(1);
    resetSelection();
  }

  const selectionCount = selectAllMatching ? total : selected.size;

  async function assignToList(listId: number) {
    setBusy(true);
    try {
      const body = selectAllMatching
        ? { list_id: listId, all_matching_filter: true, filters: { ...query, page: undefined, per_page: undefined } }
        : { list_id: listId, contact_ids: Array.from(selected) };

      const { data } = await api.post<{ assigned: number; moved: number; unchanged: number }>(
        '/contacts/bulk-assign',
        body,
      );

      notify(
        `${data.assigned + data.moved} contact(s) assigned${data.moved ? ` (${data.moved} moved from another list)` : ''}.`,
        'ok',
      );
      setAssignOpen(false);
      resetSelection();
      void contacts.reload();
      void lists.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not assign the contacts.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function deleteSelected() {
    setBusy(true);
    try {
      const { data } = await api.post<{ deleted: number }>('/contacts/bulk-delete', {
        contact_ids: Array.from(selected),
      });
      notify(`${data.deleted} contact(s) deleted. Suppression records were kept.`, 'ok');
      setDeleteOpen(false);
      resetSelection();
      void contacts.reload();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not delete the contacts.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  function exportCsv() {
    api.download('/contacts/export', {
      assignment,
      search: debouncedSearch,
      status,
      country,
      suppressed,
    });
    notify('Your export is downloading.', 'ok');
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title="Contacts"
        description={
          assignment === 'unassigned'
            ? 'Contacts not yet assigned to a list. Assigning one moves it off this page.'
            : 'All contacts matching the current filters.'
        }
        actions={
          canWrite && (
            <>
              <Button variant="secondary" onClick={exportCsv}>
                <Download className="h-3.5 w-3.5" /> Export
              </Button>
              <Link to="/contacts/import">
                <Button variant="secondary">
                  <Upload className="h-3.5 w-3.5" /> Import CSV
                </Button>
              </Link>
              <Button variant="primary" onClick={() => setEditing('new')}>
                <Plus className="h-3.5 w-3.5" /> New contact
              </Button>
            </>
          )
        }
      />

      {counts && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <StatTile label="Total" value={counts.total.toLocaleString()} onClick={() => changeFilter(() => setAssignment('any'))} />
          <StatTile
            label="Unassigned"
            value={counts.unassigned.toLocaleString()}
            onClick={() => changeFilter(() => setAssignment('unassigned'))}
          />
          <StatTile
            label="On a list"
            value={counts.assigned.toLocaleString()}
            onClick={() => changeFilter(() => setAssignment('assigned'))}
          />
          <StatTile label="Suppressed" value={counts.suppressed.toLocaleString()} tone={counts.suppressed ? 'warn' : 'neutral'} />
          <StatTile label="Unsubscribed" value={counts.unsubscribed.toLocaleString()} />
        </div>
      )}

      <Card>
        {/* ------------------------------------------------------- filters */}
        <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
          <SearchInput
            value={search}
            onChange={(value) => changeFilter(() => setSearch(value))}
            placeholder="Search name, email, company…"
            className="min-w-[14rem] flex-1"
          />
          <Select
            value={assignment}
            onChange={(event) => changeFilter(() => setAssignment(event.target.value as Assignment))}
            className="w-auto min-w-[11rem]"
            aria-label="List assignment"
          >
            <option value="unassigned">Unassigned only</option>
            <option value="assigned">On a list</option>
            <option value="any">All contacts</option>
          </Select>
          <Select
            value={status}
            onChange={(event) => changeFilter(() => setStatus(event.target.value))}
            className="w-auto min-w-[9rem]"
            aria-label="Status"
          >
            <option value="">Any status</option>
            {(filterMeta.data?.statuses ?? []).map((value) => (
              <option key={value} value={value}>
                {value}
              </option>
            ))}
          </Select>
          <Select
            value={country}
            onChange={(event) => changeFilter(() => setCountry(event.target.value))}
            className="w-auto min-w-[10rem]"
            aria-label="Country"
          >
            <option value="">Any country</option>
            {(filterMeta.data?.countries ?? []).map((value) => (
              <option key={value} value={value}>
                {value}
              </option>
            ))}
          </Select>
          <Select
            value={suppressed}
            onChange={(event) => changeFilter(() => setSuppressed(event.target.value))}
            className="w-auto min-w-[11rem]"
            aria-label="Suppression"
          >
            <option value="">Any suppression</option>
            <option value="no">Not suppressed</option>
            <option value="yes">Suppressed</option>
          </Select>
        </div>

        {/* ---------------------------------------------------- bulk toolbar */}
        {canWrite && selected.size > 0 && (
          <div className="flex flex-wrap items-center gap-3 border-b border-line bg-brand-soft/60 px-4 py-2.5">
            <span className="text-[13px] font-medium text-brand-strong">
              {selectionCount.toLocaleString()} selected
            </span>
            {allOnPageSelected && total > rows.length && !selectAllMatching && (
              <button
                type="button"
                className="text-[13px] font-medium text-brand underline"
                onClick={() => setSelectAllMatching(true)}
              >
                Select all {total.toLocaleString()} matching
              </button>
            )}
            {selectAllMatching && (
              <button type="button" className="text-[13px] text-brand underline" onClick={resetSelection}>
                Clear selection
              </button>
            )}
            <div className="ml-auto flex gap-2">
              <Button size="sm" variant="primary" onClick={() => setAssignOpen(true)}>
                <ListPlus className="h-3.5 w-3.5" /> Assign to list
              </Button>
              <Button size="sm" variant="danger" onClick={() => setDeleteOpen(true)} disabled={selectAllMatching}>
                <Trash2 className="h-3.5 w-3.5" /> Delete
              </Button>
            </div>
          </div>
        )}

        {/* ---------------------------------------------------------- table */}
        {contacts.loading && rows.length === 0 ? (
          <LoadingPanel />
        ) : contacts.error ? (
          <div className="p-4">
            <Callout tone="danger" title="Could not load contacts">
              {contacts.error}
            </Callout>
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={<Users className="h-7 w-7" />}
            title={assignment === 'unassigned' ? 'No unassigned contacts' : 'No contacts match these filters'}
            description={
              assignment === 'unassigned'
                ? 'Every contact is on a list, or you have not imported any yet.'
                : 'Try clearing the filters, or import a CSV to get started.'
            }
            action={
              canWrite && (
                <Link to="/contacts/import">
                  <Button variant="primary">
                    <Upload className="h-3.5 w-3.5" /> Import contacts
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
                  {canWrite && (
                    <Th className="w-10">
                      <Checkbox
                        checked={allOnPageSelected}
                        indeterminate={selected.size > 0 && !allOnPageSelected}
                        onChange={(checked) => {
                          setSelected((current) => {
                            const next = new Set(current);
                            rows.forEach((row) => (checked ? next.add(row.id) : next.delete(row.id)));
                            return next;
                          });
                          setSelectAllMatching(false);
                        }}
                      />
                    </Th>
                  )}
                  <Th sortable active={sort === 'email'} direction={direction} onSort={() => toggleSort('email')}>
                    Email
                  </Th>
                  <Th sortable active={sort === 'last_name'} direction={direction} onSort={() => toggleSort('last_name')}>
                    Name
                  </Th>
                  <Th
                    className="hidden md:table-cell"
                    sortable
                    active={sort === 'company_name'}
                    direction={direction}
                    onSort={() => toggleSort('company_name')}
                  >
                    Company
                  </Th>
                  <Th className="hidden lg:table-cell">Country</Th>
                  {assignment !== 'unassigned' && <Th className="hidden lg:table-cell">List</Th>}
                  <Th>Status</Th>
                  <Th
                    className="hidden sm:table-cell text-right"
                    sortable
                    active={sort === 'created_at'}
                    direction={direction}
                    onSort={() => toggleSort('created_at')}
                  >
                    Added
                  </Th>
                </tr>
              </thead>
              <tbody>
                {rows.map((contact) => (
                  <tr key={contact.id} className="hover:bg-muted/40">
                    {canWrite && (
                      <Td>
                        <Checkbox
                          checked={selected.has(contact.id)}
                          onChange={(checked) => toggleRow(contact.id, checked)}
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
                    <Td className="hidden text-ink-soft lg:table-cell">{contact.country || '—'}</Td>
                    {assignment !== 'unassigned' && (
                      <Td className="hidden lg:table-cell">
                        {contact.list_id ? (
                          <Link to={`/lists/${contact.list_id}`} className="text-ink-soft hover:text-brand">
                            {contact.list_name}
                          </Link>
                        ) : (
                          <span className="text-ink-faint">Unassigned</span>
                        )}
                      </Td>
                    )}
                    <Td>
                      <div className="flex flex-wrap gap-1">
                        {contact.is_suppressed && <Badge tone="danger">{contact.suppression_reason?.replace('_', ' ')}</Badge>}
                        {contact.is_unsubscribed && !contact.is_suppressed && <Badge tone="warn">unsubscribed</Badge>}
                        {!contact.is_suppressed && !contact.is_unsubscribed && (
                          <Badge tone={contact.status === 'active' ? 'ok' : 'neutral'}>{contact.status}</Badge>
                        )}
                      </div>
                    </Td>
                    <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint sm:table-cell">
                      {formatDate(contact.created_at)}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>

            <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-1">
              <Select
                value={perPage}
                onChange={(event) => changeFilter(() => setPerPage(Number(event.target.value)))}
                className="my-2 w-auto"
                aria-label="Rows per page"
              >
                {[25, 50, 100, 200].map((size) => (
                  <option key={size} value={size}>
                    {size} per page
                  </option>
                ))}
              </Select>
            </div>

            <Pagination
              page={meta?.page ?? 1}
              pages={meta?.pages ?? 1}
              total={total}
              perPage={meta?.per_page ?? perPage}
              onPage={(next) => {
                setPage(next);
                window.scrollTo({ top: 0, behavior: 'smooth' });
              }}
            />
          </>
        )}
      </Card>

      {/* ------------------------------------------------------- assign modal */}
      <AssignDialog
        open={assignOpen}
        onClose={() => setAssignOpen(false)}
        lists={lists.data ?? []}
        count={selectionCount}
        busy={busy}
        onAssign={assignToList}
        onCreated={() => void lists.reload()}
      />

      {/* ------------------------------------------------------- delete modal */}
      <Dialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title={`Delete ${selected.size} contact(s)?`}
        description="This cannot be undone."
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleteOpen(false)}>
              Cancel
            </Button>
            <Button variant="danger" loading={busy} onClick={() => void deleteSelected()}>
              Delete contacts
            </Button>
          </>
        }
      >
        <Callout tone="warn" title="Suppression records are kept">
          Suppressions are stored against the email address, not the contact, so deleting a contact never makes a
          previously unsubscribed address mailable again.
        </Callout>
      </Dialog>

      {editing && (
        <ContactFormDialog
          contact={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            void contacts.reload();
          }}
        />
      )}
    </div>
  );
}

function AssignDialog({
  open,
  onClose,
  lists,
  count,
  busy,
  onAssign,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  lists: ContactList[];
  count: number;
  busy: boolean;
  onAssign: (listId: number) => void;
  onCreated: () => void;
}) {
  const { notify } = useToast();
  const [listId, setListId] = useState<number | ''>('');
  const [newName, setNewName] = useState('');
  const [creating, setCreating] = useState(false);

  async function createList() {
    if (!newName.trim()) return;
    setCreating(true);
    try {
      const { data } = await api.post<ContactList>('/lists', { name: newName.trim() });
      notify(`List “${data.name}” created.`, 'ok');
      setListId(data.id);
      setNewName('');
      onCreated();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not create the list.', 'danger');
    } finally {
      setCreating(false);
    }
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={`Assign ${count.toLocaleString()} contact(s) to a list`}
      description="A contact belongs to one list at a time. Assigning moves it from any current list."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} disabled={listId === ''} onClick={() => onAssign(Number(listId))}>
            Assign
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Target list">
          <Select value={listId} onChange={(event) => setListId(Number(event.target.value) || '')}>
            <option value="">Choose a list…</option>
            {lists.map((list) => (
              <option key={list.id} value={list.id}>
                {list.name} ({list.contact_count.toLocaleString()})
              </option>
            ))}
          </Select>
        </Field>

        <div className="rounded-lg border border-line bg-muted/40 p-3">
          <p className="mb-2 text-[13px] font-medium text-ink">Or create a new list</p>
          <div className="flex gap-2">
            <Input
              value={newName}
              onChange={(event) => setNewName(event.target.value)}
              placeholder="List name"
              maxLength={190}
            />
            <Button variant="secondary" loading={creating} onClick={() => void createList()} disabled={!newName.trim()}>
              Create
            </Button>
          </div>
        </div>
      </div>
    </Dialog>
  );
}
