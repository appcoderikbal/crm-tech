import { ArrowLeft } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Badge,
  Card,
  EmptyState,
  LoadingPanel,
  PageHeader,
  Pagination,
  SearchInput,
  Table,
  Td,
  Th,
} from '@/components/ui';
import { useDebounced, useResource } from '@/hooks/useResource';
import type { AuditEntry } from '@/lib/types';
import { formatDateTime } from '@/lib/utils';

function tone(action: string): 'neutral' | 'ok' | 'warn' | 'danger' | 'brand' {
  if (action.includes('deleted') || action.includes('failed') || action.includes('kill_switch_engaged')) return 'danger';
  if (action.includes('paused') || action.includes('cancelled') || action.includes('suppression')) return 'warn';
  if (action.includes('created') || action.includes('completed')) return 'ok';
  if (action.includes('login') || action.includes('auth')) return 'brand';
  return 'neutral';
}

export function AuditLogPage() {
  const [action, setAction] = useState('');
  const [page, setPage] = useState(1);
  const debounced = useDebounced(action);

  const entries = useResource<AuditEntry[]>(
    '/settings/audit',
    useMemo(() => ({ action: debounced, page, per_page: 50 }), [debounced, page]),
  );

  const rows = entries.data ?? [];

  return (
    <div className="space-y-5">
      <Link to="/settings" className="inline-flex items-center gap-1 text-[13px] text-ink-faint hover:text-ink">
        <ArrowLeft className="h-3.5 w-3.5" /> Back to settings
      </Link>

      <PageHeader
        title="Audit log"
        description="Administrative and sending actions, newest first. Append-only."
      />

      <Card>
        <div className="border-b border-line px-4 py-3">
          <SearchInput
            value={action}
            onChange={(value) => {
              setAction(value);
              setPage(1);
            }}
            placeholder="Filter by action, e.g. campaign. or suppression."
            className="max-w-md"
          />
        </div>

        {entries.loading && rows.length === 0 ? (
          <LoadingPanel />
        ) : rows.length === 0 ? (
          <EmptyState title="No audit entries" description="Actions appear here as they happen." />
        ) : (
          <>
            <Table>
              <thead>
                <tr>
                  <Th>Action</Th>
                  <Th>Actor</Th>
                  <Th className="hidden md:table-cell">Entity</Th>
                  <Th className="hidden lg:table-cell">Details</Th>
                  <Th className="hidden text-right sm:table-cell">IP</Th>
                  <Th className="text-right">When</Th>
                </tr>
              </thead>
              <tbody>
                {rows.map((entry) => (
                  <tr key={entry.id} className="hover:bg-muted/40">
                    <Td>
                      <Badge tone={tone(entry.action)}>{entry.action}</Badge>
                    </Td>
                    <Td className="max-w-[14rem] truncate text-ink-soft">
                      {entry.user_email ?? entry.actor_label ?? 'system'}
                    </Td>
                    <Td className="hidden text-[12px] text-ink-faint md:table-cell">
                      {entry.entity_type ? `${entry.entity_type}${entry.entity_id ? ` #${entry.entity_id}` : ''}` : '—'}
                    </Td>
                    <Td className="hidden max-w-[22rem] truncate font-mono text-[11px] text-ink-faint lg:table-cell">
                      {entry.details_json ?? '—'}
                    </Td>
                    <Td className="hidden text-right font-mono text-[11px] whitespace-nowrap text-ink-faint sm:table-cell">
                      {entry.ip_address ?? '—'}
                    </Td>
                    <Td className="text-right text-[12px] whitespace-nowrap text-ink-faint">
                      {formatDateTime(entry.created_at)}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>

            <Pagination
              page={entries.meta?.page ?? 1}
              pages={entries.meta?.pages ?? 1}
              total={entries.meta?.total ?? 0}
              perPage={entries.meta?.per_page ?? 50}
              onPage={setPage}
            />
          </>
        )}
      </Card>
    </div>
  );
}
