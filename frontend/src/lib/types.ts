/** Shapes returned by the PHP API. */

export interface Contact {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  company_name: string;
  website: string;
  country: string;
  source: string;
  status: 'active' | 'inactive' | 'archived';
  custom_fields: Record<string, string>;
  list_id: number | null;
  list_name: string | null;
  assigned_at: string | null;
  unsubscribed_at: string | null;
  suppression_reason: string | null;
  suppressed_at: string | null;
  is_suppressed: boolean;
  is_unsubscribed: boolean;
  created_at: string;
  updated_at: string;
}

export interface ContactCounts {
  total: number;
  unassigned: number;
  assigned: number;
  suppressed: number;
  unsubscribed: number;
}

export interface ContactList {
  id: number;
  name: string;
  description: string | null;
  contact_count: number;
  sendable_count: number;
  created_at: string;
  updated_at: string;
}

export type CampaignStatus =
  | 'draft'
  | 'scheduled'
  | 'queued'
  | 'sending'
  | 'paused'
  | 'completed'
  | 'failed'
  | 'cancelled';

export interface Campaign {
  id: number;
  name: string;
  status: CampaignStatus;
  list_id: number | null;
  list_name: string | null;
  from_name: string;
  from_email: string;
  reply_to: string;
  subject: string;
  preview_text: string;
  html_body: string | null;
  text_body: string | null;
  scheduled_at_utc: string | null;
  timezone: string;
  send_rate_per_minute: number;
  track_opens: boolean;
  track_clicks: boolean;
  total_recipients: number;
  started_at: string | null;
  completed_at: string | null;
  last_error: string;
  created_at: string;
  updated_at: string;
  // Present on list rows (joined from campaign_stats).
  accepted?: number;
  delivered?: number;
  hard_bounces?: number;
  soft_bounces?: number;
  complaints?: number;
  unique_opens?: number;
  unique_clicks?: number;
  pending?: number;
  failed?: number;
}

export interface CampaignReadiness {
  can_send: boolean;
  problems: { field: string; message: string }[];
  warnings: string[];
  counts: {
    list_total: number;
    suppressed: number;
    unsubscribed: number;
    invalid: number;
    eligible: number;
  };
  sender_verified: boolean;
  missing_variables: string[];
  estimate: {
    send_rate_per_minute: number;
    estimated_minutes: number;
    transport: string;
  };
}

export interface CampaignMetrics {
  total_recipients: number;
  attempted: number;
  accepted: number;
  delivered: number;
  pending: number;
  failed: number;
  suppressed: number;
  cancelled: number;
  unsubscribes: number;
  hard_bounces: number;
  soft_bounces: number;
  rejects: number;
  complaints: number;
  total_opens: number;
  total_clicks: number;
  unique_opens: number;
  unique_clicks: number;
}

export interface MetricRates {
  delivery_rate: number | null;
  bounce_rate: number | null;
  hard_bounce_rate: number | null;
  complaint_rate: number | null;
  open_rate: number | null;
  click_rate: number | null;
  click_to_open: number | null;
  unsubscribe_rate: number | null;
}

export interface CampaignRecipient {
  id: number;
  contact_id: number | null;
  recipient_email_snapshot: string;
  status: string;
  attempts: number;
  ses_message_id: string | null;
  last_error_code: string;
  last_error_message: string;
  accepted_at: string | null;
  delivered_at: string | null;
  bounced_at: string | null;
  complained_at: string | null;
  first_opened_at: string | null;
  first_clicked_at: string | null;
  unsubscribed_at: string | null;
}

export interface CampaignReport {
  campaign: Campaign;
  metrics: CampaignMetrics;
  rates: MetricRates;
  denominators: Record<string, string>;
  data_quality: string[];
  timeline: { event_type: string; bucket: string; total: number }[];
  links: { id: number; url: string; total_clicks: number; unique_clicks: number }[];
  tracking_note: string;
}

export interface HealthEntry {
  status: 'green' | 'amber' | 'red' | 'unknown';
  detail: string;
}

export interface Alert {
  id: number;
  alert_key: string;
  category: string;
  severity: 'info' | 'warning' | 'critical';
  status: 'active' | 'acknowledged' | 'resolved';
  title: string;
  message: string;
  metrics: Record<string, unknown>;
  campaign_id: number | null;
  first_seen_at: string;
  last_seen_at: string;
}

export interface SendingStatus {
  sending_enabled: boolean;
  kill_switch: boolean;
  kill_switch_reason: string;
  can_send: boolean;
  campaigns_in_flight: number;
  queued_recipients: number;
  sent_today: number;
  daily_cap: number;
  rate_per_minute: number;
}

export interface DashboardData {
  range: { from: string; to: string };
  contacts: ContactCounts;
  lists: { total: number };
  campaigns: {
    total: number;
    draft: number;
    scheduled: number;
    sending: number;
    paused: number;
    completed: number;
    failed: number;
  };
  metrics: CampaignMetrics;
  rates: MetricRates;
  denominators: Record<string, string>;
  data_quality: string[];
  sending_status: SendingStatus;
  recent_campaigns: Campaign[];
  recent_events: {
    id: number;
    event_type: string;
    event_subtype: string;
    event_timestamp: string;
    campaign_id: number | null;
    campaign_name: string | null;
    recipient_email_snapshot: string | null;
  }[];
  alerts: Alert[];
  health: Record<string, HealthEntry>;
}

export interface DeliverabilityReport {
  window_hours: number;
  since: string;
  metrics: CampaignMetrics;
  rates: MetricRates;
  denominators: Record<string, string>;
  data_quality: string[];
  thresholds: {
    bounce_warning: number;
    bounce_critical: number;
    complaint_warning: number;
    complaint_critical: number;
  };
  sample_size: number;
  min_sample: number;
  alerts: Alert[];
  provider: ProviderAccount | null;
  health: Record<string, HealthEntry>;
}

export interface ProviderAccount {
  sending_enabled: boolean;
  production_access: boolean;
  enforcement_status: string;
  max_24_hour: number;
  sent_last_24_hours: number;
  max_send_rate: number;
}

export interface Suppression {
  id: number;
  normalized_email: string;
  reason: string;
  source: string;
  notes: string;
  campaign_id: number | null;
  campaign_name: string | null;
  contact_id: number | null;
  contact_email: string | null;
  created_at: string;
  updated_at: string;
}

export interface ImportJob {
  id: number;
  original_filename: string;
  status: 'uploaded' | 'mapped' | 'queued' | 'processing' | 'completed' | 'failed' | 'cancelled';
  duplicate_mode: 'skip' | 'update' | 'new_only';
  target_list_id: number | null;
  target_list_name: string | null;
  file_size_bytes: number;
  total_rows: number;
  processed_rows: number;
  inserted_rows: number;
  updated_rows: number;
  skipped_rows: number;
  failed_rows: number;
  progress_percent: number;
  has_file: boolean;
  last_error: string;
  created_by_email: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  mapping: Record<string, number>;
}

export interface ImportDescription {
  job: ImportJob;
  header: string[];
  preview: string[][];
  suggested_mapping: Record<string, number>;
  mappable_fields: Record<string, string>;
}

export interface SettingsPayload {
  settings: Record<string, string | number | boolean | string[] | Record<string, string>>;
  editable: string[];
  provider: {
    transport: string;
    region: string;
    configuration_set: string;
    verified_identities: string[];
    credentials_configured: boolean;
    account: ProviderAccount | null;
  };
  tracking: {
    base_url: string;
    open_pixel_path: string;
    click_path: string;
    unsubscribe_path: string;
    sns_webhook: string;
  };
  runtime: {
    php_version: string;
    memory_limit: string;
    max_execution_time: string;
    upload_max_filesize: string;
    post_max_size: string;
    database_version: string;
  };
  cron: {
    job_name: string;
    last_run_at: string;
    last_status: 'ok' | 'error';
    last_message: string;
    duration_ms: number;
    run_count: number;
  }[];
  health: Record<string, HealthEntry>;
}

export interface AuditEntry {
  id: number;
  action: string;
  entity_type: string;
  entity_id: string;
  details_json: string | null;
  created_at: string;
  actor_label: string;
  user_email: string | null;
  ip_address: string | null;
}
