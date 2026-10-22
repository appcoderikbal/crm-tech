import { Plus, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button, Dialog, Field, Input, Select, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import type { Contact } from '@/lib/types';

interface CustomField {
  key: string;
  value: string;
}

export function ContactFormDialog({
  contact,
  onClose,
  onSaved,
}: {
  contact: Contact | null;
  onClose: () => void;
  onSaved: (contact: Contact) => void;
}) {
  const { notify } = useToast();
  const editing = contact !== null;

  const [form, setForm] = useState({
    email: contact?.email ?? '',
    first_name: contact?.first_name ?? '',
    last_name: contact?.last_name ?? '',
    company_name: contact?.company_name ?? '',
    website: contact?.website ?? '',
    country: contact?.country ?? '',
    source: contact?.source ?? 'manual',
    status: contact?.status ?? 'active',
  });

  const [customFields, setCustomFields] = useState<CustomField[]>(
    Object.entries(contact?.custom_fields ?? {}).map(([key, value]) => ({ key, value })),
  );

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setErrors({});
    setSaving(true);

    const custom: Record<string, string> = {};
    for (const field of customFields) {
      const key = field.key.trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
      if (key) custom[key] = field.value;
    }

    const payload = { ...form, custom_fields: custom };

    try {
      const { data } = editing
        ? await api.patch<Contact>(`/contacts/${contact.id}`, payload)
        : await api.post<Contact>('/contacts', payload);

      notify(editing ? 'Contact updated.' : 'Contact created.', 'ok');
      onSaved(data);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors);
        if (Object.keys(error.fieldErrors).length === 0) notify(error.message, 'danger');
      } else {
        notify('Could not save the contact.', 'danger');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={editing ? 'Edit contact' : 'New contact'}
      description={editing ? contact.email : 'Add a single contact. Use Import for a file of them.'}
      width="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" form="contact-form" type="submit" loading={saving}>
            {editing ? 'Save changes' : 'Create contact'}
          </Button>
        </>
      }
    >
      <form id="contact-form" onSubmit={submit} className="space-y-4" noValidate>
        <Field label="Email address" htmlFor="email" required error={errors.email}>
          <Input
            id="email"
            type="email"
            value={form.email}
            onChange={(event) => update('email', event.target.value)}
            invalid={Boolean(errors.email)}
            required
            autoFocus={!editing}
          />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="First name" htmlFor="first_name" error={errors.first_name}>
            <Input id="first_name" value={form.first_name} onChange={(event) => update('first_name', event.target.value)} />
          </Field>
          <Field label="Last name" htmlFor="last_name" error={errors.last_name}>
            <Input id="last_name" value={form.last_name} onChange={(event) => update('last_name', event.target.value)} />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Company" htmlFor="company_name" error={errors.company_name}>
            <Input
              id="company_name"
              value={form.company_name}
              onChange={(event) => update('company_name', event.target.value)}
            />
          </Field>
          <Field label="Website" htmlFor="website" error={errors.website}>
            <Input id="website" value={form.website} onChange={(event) => update('website', event.target.value)} />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Country" htmlFor="country" error={errors.country}>
            <Input id="country" value={form.country} onChange={(event) => update('country', event.target.value)} />
          </Field>
          <Field label="Source" htmlFor="source" error={errors.source}>
            <Input id="source" value={form.source} onChange={(event) => update('source', event.target.value)} />
          </Field>
          <Field label="Status" htmlFor="status" error={errors.status}>
            <Select id="status" value={form.status} onChange={(event) => update('status', event.target.value)}>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="archived">Archived</option>
            </Select>
          </Field>
        </div>

        <div className="rounded-lg border border-line bg-muted/30 p-3">
          <div className="mb-2 flex items-center justify-between">
            <p className="text-[13px] font-medium text-ink">Custom fields</p>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={() => setCustomFields((current) => [...current, { key: '', value: '' }])}
            >
              <Plus className="h-3.5 w-3.5" /> Add field
            </Button>
          </div>

          {customFields.length === 0 ? (
            <p className="text-[12px] text-ink-faint">
              Any field you add here is available in campaigns as <code>{'{{field_name}}'}</code>.
            </p>
          ) : (
            <div className="space-y-2">
              {customFields.map((field, index) => (
                <div key={index} className="flex gap-2">
                  <Input
                    value={field.key}
                    onChange={(event) =>
                      setCustomFields((current) =>
                        current.map((item, position) => (position === index ? { ...item, key: event.target.value } : item)),
                      )
                    }
                    placeholder="field_name"
                    className="max-w-[12rem]"
                  />
                  <Input
                    value={field.value}
                    onChange={(event) =>
                      setCustomFields((current) =>
                        current.map((item, position) => (position === index ? { ...item, value: event.target.value } : item)),
                      )
                    }
                    placeholder="Value"
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => setCustomFields((current) => current.filter((_, position) => position !== index))}
                    aria-label="Remove field"
                  >
                    <X className="h-4 w-4" />
                  </Button>
                </div>
              ))}
            </div>
          )}
          {errors.custom_fields && <p className="mt-2 text-[12px] text-danger">{errors.custom_fields}</p>}
        </div>
      </form>
    </Dialog>
  );
}
