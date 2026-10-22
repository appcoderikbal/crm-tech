import { Badge } from '@/components/ui';
import type { CampaignStatus } from '@/lib/types';

const CONFIG: Record<CampaignStatus, { tone: 'neutral' | 'brand' | 'ok' | 'warn' | 'danger' | 'outline'; label: string }> = {
  draft: { tone: 'neutral', label: 'Draft' },
  scheduled: { tone: 'brand', label: 'Scheduled' },
  queued: { tone: 'brand', label: 'Queued' },
  sending: { tone: 'brand', label: 'Sending' },
  paused: { tone: 'warn', label: 'Paused' },
  completed: { tone: 'ok', label: 'Completed' },
  failed: { tone: 'danger', label: 'Failed' },
  cancelled: { tone: 'outline', label: 'Cancelled' },
};

export function CampaignStatusBadge({ status }: { status: CampaignStatus }) {
  const config = CONFIG[status] ?? { tone: 'neutral' as const, label: status };
  return <Badge tone={config.tone}>{config.label}</Badge>;
}

const RECIPIENT_CONFIG: Record<string, { tone: 'neutral' | 'brand' | 'ok' | 'warn' | 'danger' | 'outline'; label: string }> = {
  pending: { tone: 'neutral', label: 'Pending' },
  sending: { tone: 'brand', label: 'Sending' },
  accepted: { tone: 'brand', label: 'Accepted by SES' },
  delivered: { tone: 'ok', label: 'Delivered' },
  bounced: { tone: 'danger', label: 'Bounced' },
  complained: { tone: 'danger', label: 'Complained' },
  failed: { tone: 'danger', label: 'Failed' },
  suppressed: { tone: 'warn', label: 'Suppressed' },
  cancelled: { tone: 'outline', label: 'Cancelled' },
  needs_reconciliation: { tone: 'warn', label: 'Needs reconciliation' },
};

export function RecipientStatusBadge({ status }: { status: string }) {
  const config = RECIPIENT_CONFIG[status] ?? { tone: 'neutral' as const, label: status };
  return <Badge tone={config.tone}>{config.label}</Badge>;
}
