import { ArrowLeft, CheckCircle2, Download, FileSpreadsheet, Upload, XCircle } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Badge,
  Button,
  Callout,
  Card,
  CardBody,
  CardHeader,
  Checkbox,
  Field,
  LoadingPanel,
  PageHeader,
  Select,
  StatTile,
  Table,
  Td,
  Th,
  useToast,
} from '@/components/ui';
import { usePoll, useResource } from '@/hooks/useResource';
import { api, ApiError } from '@/lib/api';
import type { ContactList, ImportDescription, ImportJob } from '@/lib/types';
import { formatBytes, formatDateTime, formatNumber } from '@/lib/utils';

type Step = 'upload' | 'map' | 'run' | 'done';

export function ImportPage() {
  const { notify } = useToast();
  const [step, setStep] = useState<Step>('upload');
  const [description, setDescription] = useState<ImportDescription | null>(null);
  const [job, setJob] = useState<ImportJob | null>(null);
  const [uploading, setUploading] = useState(false);
  const [working, setWorking] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);

  const lists = useResource<ContactList[]>('/lists');
  const history = useResource<ImportJob[]>('/imports', { per_page: 10 });

  const [mapping, setMapping] = useState<Record<string, number | ''>>({});
  const [customFields, setCustomFields] = useState<Record<string, number>>({});
  const [duplicateMode, setDuplicateMode] = useState<'skip' | 'update' | 'new_only'>('skip');
  const [targetList, setTargetList] = useState<number | ''>('');
  const [hasHeader, setHasHeader] = useState(true);

  async function upload(file: File) {
    setUploading(true);
    try {
      const formData = new FormData();
      formData.append('file', file);

      const { data } = await api.upload<ImportDescription>('/contacts/import', formData);
      setDescription(data);
      setJob(data.job);
      setMapping(data.suggested_mapping as Record<string, number>);
      setStep('map');
      notify('File uploaded. Check the column mapping below.', 'ok');
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'The upload failed.', 'danger');
    } finally {
      setUploading(false);
      if (fileInput.current) fileInput.current.value = '';
    }
  }

  async function start() {
    if (!job) return;
    setWorking(true);
    try {
      const { data } = await api.post<{ job: ImportJob }>(`/imports/${job.id}/start`, {
        mapping,
        custom_fields: customFields,
        duplicate_mode: duplicateMode,
        target_list_id: targetList === '' ? null : targetList,
        has_header: hasHeader,
      });
      setJob(data.job);
      setStep('run');
      void processChunk(data.job.id);
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'Could not start the import.', 'danger');
    } finally {
      setWorking(false);
    }
  }

  /**
   * Drives the import from the browser in bounded chunks. The cron worker runs
   * exactly the same code, so closing this tab does not abandon the import — it
   * just finishes a little later.
   */
  async function processChunk(jobId: number) {
    try {
      const { data } = await api.post<{ job: ImportJob }>(`/imports/${jobId}/process`, {});
      setJob(data.job);

      if (data.job.status === 'processing' || data.job.status === 'queued') {
        setTimeout(() => void processChunk(jobId), 300);
      } else {
        setStep('done');
        void history.reload();
        if (data.job.status === 'completed') {
          notify(`Import finished: ${formatNumber(data.job.inserted_rows)} contact(s) added.`, 'ok');
        }
      }
    } catch (error) {
      notify(error instanceof ApiError ? error.message : 'The import stopped unexpectedly.', 'danger');
      setStep('done');
    }
  }

  function reset() {
    setDescription(null);
    setJob(null);
    setMapping({});
    setCustomFields({});
    setTargetList('');
    setDuplicateMode('skip');
    setStep('upload');
  }

  return (
    <div className="space-y-5">
      <Link to="/contacts" className="inline-flex items-center gap-1 text-[13px] text-ink-faint hover:text-ink">
        <ArrowLeft className="h-3.5 w-3.5" /> Back to contacts
      </Link>

      <PageHeader
        title="Import contacts"
        description="Large files are processed in chunks on the server — nothing is loaded into the browser."
      />

      <Stepper step={step} />

      {step === 'upload' && (
        <Card>
          <CardHeader title="Upload a CSV file" description="UTF-8 CSV with a header row works best." />
          <CardBody>
            <label
              className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-line-strong bg-muted/30 px-6 py-12 text-center transition-colors hover:border-brand hover:bg-brand-soft/40"
              onDragOver={(event) => event.preventDefault()}
              onDrop={(event) => {
                event.preventDefault();
                const file = event.dataTransfer.files?.[0];
                if (file) void upload(file);
              }}
            >
              <FileSpreadsheet className="mb-3 h-8 w-8 text-ink-faint" aria-hidden />
              <p className="text-sm font-medium text-ink">Drop a CSV here, or click to choose a file</p>
              <p className="mt-1 text-[12px] text-ink-faint">
                The file is stored outside the public web directory and deleted after the retention period.
              </p>
              <input
                ref={fileInput}
                type="file"
                accept=".csv,text/csv,text/plain"
                className="sr-only"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) void upload(file);
                }}
              />
              {uploading && <p className="mt-3 text-[13px] text-brand">Uploading…</p>}
            </label>

            <Callout tone="info" className="mt-4" title="What happens to duplicates">
              Addresses are normalised and compared against the whole database. You choose on the next step whether a
              duplicate is skipped or used to update the existing contact — a second record is never created.
            </Callout>
          </CardBody>
        </Card>
      )}

      {step === 'map' && description && (
        <MapStep
          description={description}
          mapping={mapping}
          setMapping={setMapping}
          customFields={customFields}
          setCustomFields={setCustomFields}
          duplicateMode={duplicateMode}
          setDuplicateMode={setDuplicateMode}
          targetList={targetList}
          setTargetList={setTargetList}
          hasHeader={hasHeader}
          setHasHeader={setHasHeader}
          lists={lists.data ?? []}
          working={working}
          onBack={reset}
          onStart={() => void start()}
        />
      )}

      {(step === 'run' || step === 'done') && job && (
        <ProgressStep job={job} onDone={reset} onRetryProcessing={() => void processChunk(job.id)} />
      )}

      <Card>
        <CardHeader title="Import history" description="The last ten imports." />
        {history.loading && !history.data ? (
          <LoadingPanel />
        ) : (history.data ?? []).length === 0 ? (
          <CardBody>
            <p className="py-5 text-center text-[13px] text-ink-faint">No imports yet.</p>
          </CardBody>
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>File</Th>
                <Th>Status</Th>
                <Th className="text-right">Added</Th>
                <Th className="text-right">Updated</Th>
                <Th className="text-right">Skipped</Th>
                <Th className="text-right">Failed</Th>
                <Th className="hidden text-right sm:table-cell">When</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {(history.data ?? []).map((row) => (
                <tr key={row.id} className="hover:bg-muted/40">
                  <Td className="max-w-[16rem] truncate">
                    <span className="font-medium text-ink">{row.original_filename}</span>
                    <span className="block text-[12px] text-ink-faint">{formatBytes(row.file_size_bytes)}</span>
                  </Td>
                  <Td>
                    <ImportStatusBadge status={row.status} />
                  </Td>
                  <Td className="text-right tabular">{formatNumber(row.inserted_rows)}</Td>
                  <Td className="text-right tabular">{formatNumber(row.updated_rows)}</Td>
                  <Td className="text-right tabular">{formatNumber(row.skipped_rows)}</Td>
                  <Td className="text-right tabular">
                    {row.failed_rows > 0 ? (
                      <span className="font-medium text-danger">{formatNumber(row.failed_rows)}</span>
                    ) : (
                      '0'
                    )}
                  </Td>
                  <Td className="hidden text-right text-[12px] whitespace-nowrap text-ink-faint sm:table-cell">
                    {formatDateTime(row.created_at)}
                  </Td>
                  <Td className="text-right">
                    {row.failed_rows > 0 && (
                      <a href={api.downloadUrl(`/imports/${row.id}/errors`, { format: 'csv' })}>
                        <Button size="sm" variant="ghost">
                          <Download className="h-3.5 w-3.5" /> Errors
                        </Button>
                      </a>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  );
}

function Stepper({ step }: { step: Step }) {
  const steps: { key: Step; label: string }[] = [
    { key: 'upload', label: 'Upload' },
    { key: 'map', label: 'Map columns' },
    { key: 'run', label: 'Process' },
    { key: 'done', label: 'Results' },
  ];
  const activeIndex = steps.findIndex((item) => item.key === step);

  return (
    <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
      {steps.map((item, index) => (
        <li key={item.key} className="flex items-center gap-2">
          <span
            className={
              index <= activeIndex
                ? 'flex h-6 w-6 items-center justify-center rounded-full bg-brand text-[11px] font-semibold text-white'
                : 'flex h-6 w-6 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-ink-faint'
            }
          >
            {index + 1}
          </span>
          <span className={index <= activeIndex ? 'font-medium text-ink' : 'text-ink-faint'}>{item.label}</span>
          {index < steps.length - 1 && <span className="mx-1 h-px w-6 bg-line-strong" aria-hidden />}
        </li>
      ))}
    </ol>
  );
}

function ImportStatusBadge({ status }: { status: ImportJob['status'] }) {
  const tone =
    status === 'completed' ? 'ok' : status === 'failed' ? 'danger' : status === 'cancelled' ? 'outline' : 'brand';
  return <Badge tone={tone}>{status}</Badge>;
}

function MapStep({
  description,
  mapping,
  setMapping,
  customFields,
  setCustomFields,
  duplicateMode,
  setDuplicateMode,
  targetList,
  setTargetList,
  hasHeader,
  setHasHeader,
  lists,
  working,
  onBack,
  onStart,
}: {
  description: ImportDescription;
  mapping: Record<string, number | ''>;
  setMapping: (value: Record<string, number | ''>) => void;
  customFields: Record<string, number>;
  setCustomFields: (value: Record<string, number>) => void;
  duplicateMode: 'skip' | 'update' | 'new_only';
  setDuplicateMode: (value: 'skip' | 'update' | 'new_only') => void;
  targetList: number | '';
  setTargetList: (value: number | '') => void;
  hasHeader: boolean;
  setHasHeader: (value: boolean) => void;
  lists: ContactList[];
  working: boolean;
  onBack: () => void;
  onStart: () => void;
}) {
  const { header, preview, mappable_fields: fields, job } = description;
  const mappedColumns = new Set(Object.values(mapping).filter((value) => value !== ''));

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title="Map your columns"
          description={`${job.original_filename} · ${formatBytes(job.file_size_bytes)}`}
          action={<Checkbox checked={hasHeader} onChange={setHasHeader} label="First row is a header" />}
        />
        <CardBody className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {Object.entries(fields).map(([field, label]) => (
              <Field
                key={field}
                label={label}
                required={field === 'email'}
                error={field === 'email' && mapping.email === undefined ? 'Required' : undefined}
              >
                <Select
                  value={mapping[field] ?? ''}
                  onChange={(event) =>
                    setMapping({ ...mapping, [field]: event.target.value === '' ? '' : Number(event.target.value) })
                  }
                >
                  <option value="">Not imported</option>
                  {header.map((column, index) => (
                    <option key={index} value={index}>
                      {column || `Column ${index + 1}`}
                    </option>
                  ))}
                </Select>
              </Field>
            ))}
          </div>

          <div className="rounded-lg border border-line bg-muted/30 p-3">
            <p className="mb-2 text-[13px] font-medium text-ink">Unmapped columns</p>
            <p className="mb-2.5 text-[12px] text-ink-faint">
              Tick a column to store it as a custom field, usable in campaigns as{' '}
              <code>{'{{column_name}}'}</code>.
            </p>
            <div className="flex flex-wrap gap-x-5 gap-y-2">
              {header.map((column, index) => {
                if (mappedColumns.has(index)) return null;
                const key = (column || `column_${index + 1}`).toLowerCase().replace(/[^a-z0-9]+/g, '_').slice(0, 40);
                return (
                  <Checkbox
                    key={index}
                    checked={key in customFields}
                    onChange={(checked) => {
                      const next = { ...customFields };
                      if (checked) next[key] = index;
                      else delete next[key];
                      setCustomFields(next);
                    }}
                    label={<span className="text-[13px]">{column || `Column ${index + 1}`}</span>}
                  />
                );
              })}
              {header.every((_, index) => mappedColumns.has(index)) && (
                <span className="text-[12px] text-ink-faint">Every column is mapped.</span>
              )}
            </div>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Preview" description="The first rows of your file, as the importer reads them." />
        <Table>
          <thead>
            <tr>
              {header.map((column, index) => (
                <Th key={index}>
                  {column || `Column ${index + 1}`}
                  {mappedColumns.has(index) && (
                    <span className="ml-1.5 font-normal text-brand normal-case">
                      → {Object.entries(mapping).find(([, value]) => value === index)?.[0]}
                    </span>
                  )}
                </Th>
              ))}
            </tr>
          </thead>
          <tbody>
            {preview.map((row, rowIndex) => (
              <tr key={rowIndex}>
                {header.map((_, columnIndex) => (
                  <Td key={columnIndex} className="max-w-[14rem] truncate text-ink-soft">
                    {row[columnIndex] ?? ''}
                  </Td>
                ))}
              </tr>
            ))}
          </tbody>
        </Table>
      </Card>

      <Card>
        <CardHeader title="Import options" />
        <CardBody className="grid gap-4 sm:grid-cols-2">
          <Field
            label="When an address already exists"
            hint="Duplicates never create a second contact record."
          >
            <Select
              value={duplicateMode}
              onChange={(event) => setDuplicateMode(event.target.value as typeof duplicateMode)}
            >
              <option value="skip">Skip the row, keep the stored contact</option>
              <option value="update">Update the stored contact with the new values</option>
              <option value="new_only">Import new contacts only</option>
            </Select>
          </Field>

          <Field
            label="Assign imported contacts to a list"
            hint="Optional. Assigning moves a contact from any list it is already on."
          >
            <Select
              value={targetList}
              onChange={(event) => setTargetList(event.target.value === '' ? '' : Number(event.target.value))}
            >
              <option value="">Leave unassigned</option>
              {lists.map((list) => (
                <option key={list.id} value={list.id}>
                  {list.name}
                </option>
              ))}
            </Select>
          </Field>
        </CardBody>
      </Card>

      <Callout tone="info" title="Opt-outs are protected">
        Importing an address that previously unsubscribed or complained does not make it mailable again — the
        suppression list is keyed on the address itself.
      </Callout>

      <div className="flex justify-between gap-2">
        <Button variant="secondary" onClick={onBack}>
          Choose a different file
        </Button>
        <Button variant="primary" loading={working} disabled={mapping.email === undefined || mapping.email === ''} onClick={onStart}>
          <Upload className="h-3.5 w-3.5" /> Start import
        </Button>
      </div>
    </div>
  );
}

function ProgressStep({
  job,
  onDone,
  onRetryProcessing,
}: {
  job: ImportJob;
  onDone: () => void;
  onRetryProcessing: () => void;
}) {
  const running = job.status === 'processing' || job.status === 'queued';
  const errors = useResource<{ csv_row_number: number; email: string; error_code: string; error_message: string }[]>(
    job.failed_rows > 0 && !running ? `/imports/${job.id}/errors` : null,
    { },
  );

  // If the browser tab is throttled the cron worker keeps going; re-poll so the
  // screen reflects server-side progress either way.
  usePoll(onRetryProcessing, 4000, running);

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={running ? 'Importing…' : job.status === 'completed' ? 'Import complete' : `Import ${job.status}`}
          description={job.original_filename}
          action={
            !running && (
              <Button variant="secondary" onClick={onDone}>
                Import another file
              </Button>
            )
          }
        />
        <CardBody className="space-y-4">
          <div>
            <div className="mb-1.5 flex justify-between text-[13px]">
              <span className="text-ink-soft">
                {formatNumber(job.processed_rows)} of {formatNumber(job.total_rows)} rows
              </span>
              <span className="font-medium text-ink tabular">{job.progress_percent}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
              <div
                className={running ? 'h-full bg-brand transition-all' : 'h-full bg-ok transition-all'}
                style={{ width: `${job.progress_percent}%` }}
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile label="Added" value={formatNumber(job.inserted_rows)} tone="ok" />
            <StatTile label="Updated" value={formatNumber(job.updated_rows)} />
            <StatTile label="Skipped" value={formatNumber(job.skipped_rows)} />
            <StatTile
              label="Failed"
              value={formatNumber(job.failed_rows)}
              tone={job.failed_rows > 0 ? 'danger' : 'neutral'}
            />
          </div>

          {job.status === 'completed' && (
            <Callout tone="ok" title="Finished">
              <span className="inline-flex items-center gap-1.5">
                <CheckCircle2 className="h-3.5 w-3.5" />
                {formatNumber(job.inserted_rows)} contact(s) added
                {job.target_list_name ? ` and assigned to ${job.target_list_name}` : ''}.
              </span>
            </Callout>
          )}

          {job.status === 'failed' && (
            <Callout tone="danger" title="The import stopped">
              {job.last_error || 'An unexpected error ended this import.'}
            </Callout>
          )}

          {running && (
            <p className="text-[12px] text-ink-faint">
              You can close this page — the server-side cron worker finishes the remaining rows on its own.
            </p>
          )}
        </CardBody>
      </Card>

      {job.failed_rows > 0 && !running && (
        <Card>
          <CardHeader
            title={`${formatNumber(job.failed_rows)} row(s) could not be imported`}
            action={
              <a href={api.downloadUrl(`/imports/${job.id}/errors`, { format: 'csv' })}>
                <Button size="sm" variant="secondary">
                  <Download className="h-3.5 w-3.5" /> Download error report
                </Button>
              </a>
            }
          />
          <Table>
            <thead>
              <tr>
                <Th className="w-20">Row</Th>
                <Th>Email</Th>
                <Th>Problem</Th>
              </tr>
            </thead>
            <tbody>
              {(errors.data ?? []).slice(0, 50).map((row, index) => (
                <tr key={index}>
                  <Td className="tabular text-ink-faint">{row.csv_row_number}</Td>
                  <Td className="max-w-[16rem] truncate">{row.email || <em className="text-ink-faint">empty</em>}</Td>
                  <Td>
                    <span className="inline-flex items-center gap-1.5 text-ink-soft">
                      <XCircle className="h-3.5 w-3.5 text-danger" aria-hidden />
                      {row.error_message}
                    </span>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {(errors.data ?? []).length > 50 && (
            <CardBody>
              <p className="text-[12px] text-ink-faint">
                Showing the first 50. Download the report for the full list.
              </p>
            </CardBody>
          )}
        </Card>
      )}
    </div>
  );
}
