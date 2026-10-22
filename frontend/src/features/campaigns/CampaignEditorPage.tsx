import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  Clock,
  Monitor,
  Save,
  Send,
  Smartphone,
  XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { RichTextEditor } from '@/components/RichTextEditor';
import {
  Badge,
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  Checkbox,
  Dialog,
  Field,
  Input,
  LoadingPanel,
  PageHeader,
  Select,
  StatTile,
  Switch,
  Textarea,
  useToast,
} from '@/components/ui';
import { useAuth } from '@/features/auth/AuthContext';
import { useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { Campaign, CampaignReadiness, ContactList } from '@/lib/types';
import { cn, formatDuration, formatNumber, localTimezone, toDateTimeLocalValue } from '@/lib/utils';

type Step = 1 | 2 | 3 | 4;

const STEPS: { id: Step; label: string }[] = [
  { id: 1, label: 'Details' },
  { id: 2, label: 'Content' },
  { id: 3, label: 'Preview & test' },
  { id: 4, label: 'Schedule' },
];

interface Draft {
  name: string;
  list_id: number | '';
  from_name: string;
  from_email: string;
  reply_to: string;
  subject: string;
  preview_text: string;
  html_body: string;
  text_body: string;
  track_opens: boolean;
  track_clicks: boolean;
  send_rate_per_minute: number;
}

const EMPTY: Draft = {
  name: '',
  list_id: '',
  from_name: '',
  from_email: '',
  reply_to: '',
  subject: '',
  preview_text: '',
  html_body: '',
  text_body: '',
  track_opens: true,
  track_clicks: true,
  send_rate_per_minute: 60,
};

export function CampaignEditorPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { notify } = useToast();
  const { user } = useAuth();

  const isNew = !id;
  const [campaignId, setCampaignId] = useState<number | null>(id ? Number(id) : null);
  const [step, setStep] = useState<Step>(1);
  const [draft, setDraft] = useState<Draft>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [loaded, setLoaded] = useState(isNew);
  const [status, setStatus] = useState<Campaign['status']>('draft');
  const [readiness, setReadiness] = useState<CampaignReadiness | null>(null);

  const lists = useResource<ContactList[]>('/lists');
  const existing = useResource<{ campaign: Campaign; readiness: CampaignReadiness }>(
    campaignId ? `/campaigns/${campaignId}` : null,
  );

  // Hydrate the form once the saved campaign arrives.
  useEffect(() => {
    if (!existing.data || loaded) return;
    const campaign = existing.data.campaign;

    setDraft({
      name: campaign.name,
      list_id: campaign.list_id ?? '',
      from_name: campaign.from_name,
      from_email: campaign.from_email,
      reply_to: campaign.reply_to,
      subject: campaign.subject,
      preview_text: campaign.preview_text,
      html_body: campaign.html_body ?? '',
      text_body: campaign.text_body ?? '',
      track_opens: campaign.track_opens,
      track_clicks: campaign.track_clicks,
      send_rate_per_minute: campaign.send_rate_per_minute,
    });
    setStatus(campaign.status);
    setReadiness(existing.data.readiness);
    setLoaded(true);
  }, [existing.data, loaded]);

  const update = useCallback(<K extends keyof Draft>(key: K, value: Draft[K]) => {
    setDraft((current) => ({ ...current, [key]: value }));
    setDirty(true);
  }, []);

  const payload = useMemo(
    () => ({ ...draft, list_id: draft.list_id === '' ? null : draft.list_id }),
    [draft],
  );

  const save = useCallback(
    async (options: { silent?: boolean } = {}): Promise<number | null> => {
      setErrors({});
      setSaving(true);
      try {
        if (campaignId) {
          const { data } = await api.patch<{ campaign: Campaign; readiness: CampaignReadiness }>(
            `/campaigns/${campaignId}`,
            payload,
          );
          setStatus(data.campaign.status);
          setReadiness(data.readiness);
          setDirty(false);
          if (!options.silent) notify('Draft saved.', 'ok');
          return campaignId;
        }

        const { data } = await api.post<Campaign>('/campaigns', payload);
        setCampaignId(data.id);
        setStatus(data.status);
        setDirty(false);
        window.history.replaceState(null, '', `/campaigns/${data.id}/edit`);
        if (!options.silent) notify('Draft created.', 'ok');
        return data.id;
      } catch (error) {
        if (error instanceof ApiError) {
          setErrors(error.fieldErrors);
          notify(
            Object.keys(error.fieldErrors).length > 0 ? 'Check the highlighted fields.' : error.message,
            'danger',
          );
        } else {
          notify('Could not save the campaign.', 'danger');
        }
        return null;
      } finally {
        setSaving(false);
      }
    },
    [campaignId, payload, notify],
  );

  // Autosave: a campaign editor that loses work on a refresh is not usable.
  const autosaveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => {
    if (!dirty || !campaignId || !draft.name.trim()) return;
    if (autosaveTimer.current) clearTimeout(autosaveTimer.current);
    autosaveTimer.current = setTimeout(() => void save({ silent: true }), 2500);
    return () => {
      if (autosaveTimer.current) clearTimeout(autosaveTimer.current);
    };
  }, [dirty, campaignId, draft, save]);

  async function goToStep(next: Step) {
    if (next > step) {
      const savedId = await save({ silent: true });
      if (!savedId) return;
    }
    setStep(next);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  const editable = ['draft', 'scheduled', 'failed'].includes(status);

  if (campaignId && !loaded) {
    return existing.error ? (
      <Callout tone="danger" title="Could not load this campaign">{existing.error}</Callout>
    ) : (
      <LoadingPanel />
    );
  }

  return (
    <div className="space-y-5">
      <Link to="/campaigns" className="inline-flex items-center gap-1 text-[13px] text-ink-faint hover:text-ink">
        <ArrowLeft className="h-3.5 w-3.5" /> Back to campaigns
      </Link>

      <PageHeader
        title={draft.name || 'New campaign'}
        description={
          <span className="flex items-center gap-2">
            <Badge tone={status === 'draft' ? 'neutral' : 'brand'}>{status}</Badge>
            {saving ? (
              <span className="text-ink-faint">Saving…</span>
            ) : dirty ? (
              <span className="text-warn">Unsaved changes</span>
            ) : campaignId ? (
              <span className="text-ink-faint">All changes saved</span>
            ) : null}
          </span>
        }
        actions={
          <Button variant="secondary" loading={saving} onClick={() => void save()} disabled={!draft.name.trim()}>
            <Save className="h-3.5 w-3.5" /> Save draft
          </Button>
        }
      />

      {!editable && (
        <Callout tone="warn" title="This campaign is no longer editable">
          A campaign that has started sending cannot be changed — the report would describe two different emails.
          Duplicate it to make a new version.
        </Callout>
      )}

      {/* ----------------------------------------------------------- stepper */}
      <nav className="flex flex-wrap gap-1 rounded-card border border-line bg-surface p-1.5">
        {STEPS.map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={() => void goToStep(item.id)}
            disabled={item.id > 1 && !campaignId}
            className={cn(
              'flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-[13px] font-medium transition-colors disabled:opacity-40',
              step === item.id ? 'bg-brand text-white' : 'text-ink-soft hover:bg-muted',
            )}
          >
            <span
              className={cn(
                'flex h-5 w-5 items-center justify-center rounded-full text-[11px]',
                step === item.id ? 'bg-white/25' : 'bg-muted',
              )}
            >
              {item.id}
            </span>
            {item.label}
          </button>
        ))}
      </nav>

      {step === 1 && (
        <DetailsStep
          draft={draft}
          update={update}
          errors={errors}
          lists={lists.data ?? []}
          disabled={!editable}
        />
      )}

      {step === 2 && <ContentStep draft={draft} update={update} errors={errors} disabled={!editable} />}

      {step === 3 && campaignId && <PreviewStep campaignId={campaignId} onReadiness={setReadiness} />}

      {step === 4 && campaignId && (
        <ScheduleStep
          campaignId={campaignId}
          draft={draft}
          update={update}
          readiness={readiness}
          timezone={user?.timezone ?? localTimezone()}
          status={status}
          onScheduled={() => navigate('/campaigns')}
          onSave={() => save({ silent: true })}
        />
      )}

      <div className="flex justify-between gap-2">
        <Button variant="secondary" disabled={step === 1} onClick={() => void goToStep((step - 1) as Step)}>
          <ArrowLeft className="h-3.5 w-3.5" /> Back
        </Button>
        {step < 4 && (
          <Button
            variant="primary"
            loading={saving}
            disabled={!draft.name.trim()}
            onClick={() => void goToStep((step + 1) as Step)}
          >
            Continue <ArrowRight className="h-3.5 w-3.5" />
          </Button>
        )}
      </div>
    </div>
  );
}

/* --------------------------------------------------------------- step one */

function DetailsStep({
  draft,
  update,
  errors,
  lists,
  disabled,
}: {
  draft: Draft;
  update: <K extends keyof Draft>(key: K, value: Draft[K]) => void;
  errors: Record<string, string>;
  lists: ContactList[];
  disabled: boolean;
}) {
  const selectedList = lists.find((list) => list.id === draft.list_id);

  return (
    <Card>
      <CardHeader title="Campaign details" description="Internal name, audience and sender identity." />
      <CardBody className="space-y-4">
        <Field label="Internal campaign name" required error={errors.name} hint="Only you see this.">
          <Input
            value={draft.name}
            onChange={(event) => update('name', event.target.value)}
            maxLength={190}
            disabled={disabled}
            invalid={Boolean(errors.name)}
            placeholder="October product update"
          />
        </Field>

        <Field
          label="Recipient list"
          required
          error={errors.list_id}
          hint={
            selectedList
              ? `${formatNumber(selectedList.sendable_count)} sendable of ${formatNumber(selectedList.contact_count)} members.`
              : 'Suppressed and unsubscribed members are excluded automatically.'
          }
        >
          <Select
            value={draft.list_id}
            onChange={(event) => update('list_id', event.target.value === '' ? '' : Number(event.target.value))}
            disabled={disabled}
          >
            <option value="">Choose a list…</option>
            {lists.map((list) => (
              <option key={list.id} value={list.id}>
                {list.name} — {formatNumber(list.sendable_count)} sendable
              </option>
            ))}
          </Select>
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="From name" error={errors.from_name}>
            <Input
              value={draft.from_name}
              onChange={(event) => update('from_name', event.target.value)}
              maxLength={120}
              disabled={disabled}
              placeholder="Techzapp"
            />
          </Field>
          <Field
            label="From email"
            required
            error={errors.from_email}
            hint="Must be a verified SES identity."
          >
            <Input
              type="email"
              value={draft.from_email}
              onChange={(event) => update('from_email', event.target.value)}
              disabled={disabled}
              invalid={Boolean(errors.from_email)}
              placeholder="news@techzapp.com"
            />
          </Field>
        </div>

        <Field label="Reply-to email" error={errors.reply_to} hint="Optional. Where replies should go.">
          <Input
            type="email"
            value={draft.reply_to}
            onChange={(event) => update('reply_to', event.target.value)}
            disabled={disabled}
            placeholder="hello@techzapp.com"
          />
        </Field>

        <Field label="Subject line" required error={errors.subject} hint="Personalization works here too.">
          <Input
            value={draft.subject}
            onChange={(event) => update('subject', event.target.value)}
            maxLength={500}
            disabled={disabled}
            invalid={Boolean(errors.subject)}
            placeholder="{{first_name}}, here is what shipped this month"
          />
        </Field>

        <Field
          label="Preview text"
          error={errors.preview_text}
          hint="The snippet shown next to the subject in most inboxes."
        >
          <Input
            value={draft.preview_text}
            onChange={(event) => update('preview_text', event.target.value)}
            maxLength={500}
            disabled={disabled}
          />
        </Field>
      </CardBody>
    </Card>
  );
}

/* --------------------------------------------------------------- step two */

function ContentStep({
  draft,
  update,
  errors,
  disabled,
}: {
  draft: Draft;
  update: <K extends keyof Draft>(key: K, value: Draft[K]) => void;
  errors: Record<string, string>;
  disabled: boolean;
}) {
  const [showText, setShowText] = useState(false);

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title="Email content"
          description="Content is sanitized on save: scripts, event handlers and unsafe URLs are removed."
        />
        <CardBody className="space-y-4">
          {disabled ? (
            <div
              className="email-preview rounded-lg border border-line p-4"
              // Server-sanitized HTML, rendered read-only for a locked campaign.
              dangerouslySetInnerHTML={{ __html: draft.html_body }}
            />
          ) : (
            <RichTextEditor value={draft.html_body} onChange={(html) => update('html_body', html)} />
          )}
          {errors.html_body && <p className="text-[12px] text-danger">{errors.html_body}</p>}

          <Callout tone="info" title="Unsubscribe link">
            A compliant unsubscribe footer and the List-Unsubscribe headers are added automatically. To place the link
            yourself, use the token <code>{'{{!unsubscribe_url}}'}</code>.
          </Callout>
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Plain-text version"
          description="Sent alongside the HTML. Generated from your content if you leave it blank."
          action={
            <Button size="sm" variant="ghost" onClick={() => setShowText((current) => !current)}>
              {showText ? 'Hide' : 'Edit manually'}
            </Button>
          }
        />
        {showText && (
          <CardBody>
            <Textarea
              rows={10}
              value={draft.text_body}
              onChange={(event) => update('text_body', event.target.value)}
              disabled={disabled}
              className="font-mono text-[13px]"
              placeholder="Leave blank to generate automatically from the HTML."
            />
          </CardBody>
        )}
      </Card>

      <Card>
        <CardHeader title="Tracking" />
        <CardBody className="divide-y divide-line">
          <Switch
            checked={draft.track_opens}
            onChange={(value) => update('track_opens', value)}
            disabled={disabled}
            label="Track opens"
            description="Adds a 1×1 pixel. Privacy proxies and scanners distort open counts — treat them as a signal, not a fact."
          />
          <Switch
            checked={draft.track_clicks}
            onChange={(value) => update('track_clicks', value)}
            disabled={disabled}
            label="Track clicks"
            description="Rewrites links through your own domain. Destinations are pre-registered, so the redirect cannot be abused."
          />
        </CardBody>
      </Card>
    </div>
  );
}

/* ------------------------------------------------------------- step three */

function PreviewStep({
  campaignId,
  onReadiness,
}: {
  campaignId: number;
  onReadiness: (readiness: CampaignReadiness) => void;
}) {
  const { notify } = useToast();
  const { user } = useAuth();
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [testOpen, setTestOpen] = useState(false);
  const [testEmail, setTestEmail] = useState(user?.email ?? '');
  const [sendingTest, setSendingTest] = useState(false);

  const preview = useResource<{
    subject: string;
    html: string;
    text: string;
    missing_variables: string[];
    readiness: CampaignReadiness;
  }>(`/campaigns/${campaignId}/preview`);

  useEffect(() => {
    if (preview.data?.readiness) onReadiness(preview.data.readiness);
  }, [preview.data, onReadiness]);

  async function sendTest() {
    setSendingTest(true);
    try {
      await api.post(`/campaigns/${campaignId}/test`, { email: testEmail });
      notify(`Test message sent to ${testEmail}.`, 'ok');
      setTestOpen(false);
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'The test message could not be sent.', 'danger');
    } finally {
      setSendingTest(false);
    }
  }

  if (preview.loading && !preview.data) return <LoadingPanel label="Rendering preview…" />;
  if (preview.error) return <Callout tone="danger" title="Could not render the preview">{preview.error}</Callout>;
  if (!preview.data) return null;

  const { readiness } = preview.data;

  return (
    <div className="space-y-4">
      <ReadinessPanel readiness={readiness} />

      <Card>
        <CardHeader
          title="Preview"
          description="Rendered with sample contact data and inert tracking links."
          action={
            <>
              <div className="flex rounded-lg border border-line p-0.5">
                <button
                  type="button"
                  onClick={() => setDevice('desktop')}
                  className={cn(
                    'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-[12px]',
                    device === 'desktop' ? 'bg-muted font-medium text-ink' : 'text-ink-faint',
                  )}
                >
                  <Monitor className="h-3.5 w-3.5" /> Desktop
                </button>
                <button
                  type="button"
                  onClick={() => setDevice('mobile')}
                  className={cn(
                    'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-[12px]',
                    device === 'mobile' ? 'bg-muted font-medium text-ink' : 'text-ink-faint',
                  )}
                >
                  <Smartphone className="h-3.5 w-3.5" /> Mobile
                </button>
              </div>
              <Button size="sm" variant="secondary" onClick={() => setTestOpen(true)}>
                <Send className="h-3.5 w-3.5" /> Send test
              </Button>
            </>
          }
        />
        <CardBody className="bg-muted/40">
          <div className={cn('mx-auto transition-all', device === 'mobile' ? 'max-w-[390px]' : 'max-w-[680px]')}>
            <div className="rounded-t-lg border border-line bg-surface px-4 py-2.5">
              <p className="truncate text-[13px] font-semibold text-ink">{preview.data.subject}</p>
              <p className="truncate text-[12px] text-ink-faint">to a sample recipient</p>
            </div>
            <div
              className="email-preview rounded-b-lg border border-t-0 border-line bg-white p-4"
              // Server-rendered and server-sanitized: contact values are HTML-escaped
              // and the body passed through HTMLPurifier before storage.
              dangerouslySetInnerHTML={{ __html: preview.data.html }}
            />
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Plain-text version" />
        <CardBody>
          <pre className="max-h-80 overflow-auto rounded-lg bg-muted/60 p-3 font-mono text-[12px] whitespace-pre-wrap text-ink-soft">
            {preview.data.text}
          </pre>
        </CardBody>
      </Card>

      <Dialog
        open={testOpen}
        onClose={() => setTestOpen(false)}
        title="Send a test message"
        description="Test sends are restricted to your own address, another CRM user, or an allow-listed domain."
        footer={
          <>
            <Button variant="secondary" onClick={() => setTestOpen(false)}>
              Cancel
            </Button>
            <Button variant="primary" loading={sendingTest} onClick={() => void sendTest()}>
              Send test
            </Button>
          </>
        }
      >
        <Field label="Send to">
          <Input type="email" value={testEmail} onChange={(event) => setTestEmail(event.target.value)} autoFocus />
        </Field>
      </Dialog>
    </div>
  );
}

function ReadinessPanel({ readiness }: { readiness: CampaignReadiness }) {
  const { counts, problems, warnings, estimate } = readiness;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatTile label="Eligible recipients" value={formatNumber(counts.eligible)} tone="brand" />
        <StatTile label="Suppressed" value={formatNumber(counts.suppressed)} tone={counts.suppressed ? 'warn' : 'neutral'} />
        <StatTile label="Unsubscribed" value={formatNumber(counts.unsubscribed)} />
        <StatTile
          label="Estimated duration"
          value={formatDuration(estimate.estimated_minutes)}
          hint={`${estimate.send_rate_per_minute}/min`}
        />
      </div>

      {problems.length > 0 && (
        <Callout tone="danger" title="This campaign cannot be scheduled yet">
          <ul className="mt-1 space-y-1">
            {problems.map((problem) => (
              <li key={problem.field + problem.message} className="flex gap-1.5">
                <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                {problem.message}
              </li>
            ))}
          </ul>
        </Callout>
      )}

      {problems.length === 0 && (
        <Callout tone="ok" title="Ready to schedule">
          <span className="inline-flex items-center gap-1.5">
            <CheckCircle2 className="h-3.5 w-3.5" />
            Sender identity verified, content present, {formatNumber(counts.eligible)} eligible recipient(s).
          </span>
        </Callout>
      )}

      {warnings.length > 0 && (
        <Callout tone="warn" title="Worth checking">
          <ul className="mt-1 space-y-1">
            {warnings.map((warning) => (
              <li key={warning} className="flex gap-1.5">
                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                {warning}
              </li>
            ))}
          </ul>
        </Callout>
      )}
    </div>
  );
}

/* -------------------------------------------------------------- step four */

function ScheduleStep({
  campaignId,
  draft,
  update,
  readiness,
  timezone,
  status,
  onScheduled,
  onSave,
}: {
  campaignId: number;
  draft: Draft;
  update: <K extends keyof Draft>(key: K, value: Draft[K]) => void;
  readiness: CampaignReadiness | null;
  timezone: string;
  status: Campaign['status'];
  onScheduled: () => void;
  onSave: () => Promise<number | null>;
}) {
  const { notify } = useToast();
  const [zone, setZone] = useState(timezone);
  const [when, setWhen] = useState(() => toDateTimeLocalValue(new Date(Date.now() + 3600_000), timezone));
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState<'schedule' | 'now'>('schedule');

  const zones = useMemo(() => {
    try {
      return (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? [timezone, 'UTC'];
    } catch {
      return [timezone, 'UTC'];
    }
  }, [timezone]);

  const canSend = readiness?.can_send ?? false;

  async function submit() {
    if (!confirmed) {
      notify('Tick the confirmation box first.', 'danger');
      return;
    }

    setBusy(true);
    try {
      await onSave();

      if (mode === 'now') {
        await api.post(`/campaigns/${campaignId}/send-now`, { confirm: true });
        notify('Campaign queued. The cron worker starts sending on its next run.', 'ok');
      } else {
        await api.post(`/campaigns/${campaignId}/schedule`, {
          scheduled_at: when.replace('T', ' ') + ':00',
          timezone: zone,
          send_rate_per_minute: draft.send_rate_per_minute,
          confirm: true,
        });
        notify('Campaign scheduled.', 'ok');
      }
      onScheduled();
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not schedule the campaign.', 'danger');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      {readiness && <ReadinessPanel readiness={readiness} />}

      <Card>
        <CardHeader title="When should this send?" />
        <CardBody className="space-y-4">
          <div className="grid gap-2 sm:grid-cols-2">
            <button
              type="button"
              onClick={() => setMode('schedule')}
              className={cn(
                'rounded-lg border px-4 py-3 text-left transition-colors',
                mode === 'schedule' ? 'border-brand bg-brand-soft' : 'border-line hover:bg-muted',
              )}
            >
              <span className="flex items-center gap-2 text-[13px] font-medium text-ink">
                <Clock className="h-4 w-4" /> Schedule for later
              </span>
              <span className="mt-0.5 block text-[12px] text-ink-faint">
                The cron worker picks it up when it falls due.
              </span>
            </button>
            <button
              type="button"
              onClick={() => setMode('now')}
              className={cn(
                'rounded-lg border px-4 py-3 text-left transition-colors',
                mode === 'now' ? 'border-brand bg-brand-soft' : 'border-line hover:bg-muted',
              )}
            >
              <span className="flex items-center gap-2 text-[13px] font-medium text-ink">
                <Send className="h-4 w-4" /> Send as soon as possible
              </span>
              <span className="mt-0.5 block text-[12px] text-ink-faint">
                Queued immediately; sending starts on the next cron run.
              </span>
            </button>
          </div>

          {mode === 'schedule' && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Date and time" hint="Stored in UTC, shown in the timezone you pick.">
                <Input type="datetime-local" value={when} onChange={(event) => setWhen(event.target.value)} />
              </Field>
              <Field label="Timezone">
                <Select value={zone} onChange={(event) => setZone(event.target.value)}>
                  {zones.map((item) => (
                    <option key={item} value={item}>
                      {item}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>
          )}

          <Field
            label="Send rate (messages per minute)"
            hint="Capped by the account-wide limit in Settings and by your SES quota."
          >
            <Input
              type="number"
              min={1}
              max={10000}
              value={draft.send_rate_per_minute}
              onChange={(event) => update('send_rate_per_minute', Number(event.target.value))}
              className="max-w-[12rem]"
            />
          </Field>

          {readiness && (
            <p className="text-[13px] text-ink-soft">
              At {readiness.estimate.send_rate_per_minute} messages per minute, {formatNumber(readiness.counts.eligible)}{' '}
              recipient(s) take <strong>{formatDuration(readiness.estimate.estimated_minutes)}</strong>. Delivery is
              spread across cron runs, so the browser does not need to stay open.
            </p>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Confirm" />
        <CardBody className="space-y-3">
          {!canSend && (
            <Callout tone="danger" title="Not ready">
              Resolve the problems listed above before scheduling.
            </Callout>
          )}

          <Checkbox
            checked={confirmed}
            onChange={setConfirmed}
            label={
              <span className="text-[13px]">
                I confirm these recipients consented to receive this email, and that the content complies with
                applicable marketing law.
              </span>
            }
          />

          <Button
            variant="primary"
            size="lg"
            loading={busy}
            disabled={!canSend || !confirmed || status === 'completed'}
            onClick={() => void submit()}
          >
            {mode === 'now' ? (
              <>
                <Send className="h-4 w-4" /> Queue campaign now
              </>
            ) : (
              <>
                <Clock className="h-4 w-4" /> Schedule campaign
              </>
            )}
          </Button>
        </CardBody>
      </Card>
    </div>
  );
}
