-- Techzapp CRM — initial schema
-- MySQL 8.0+ / MariaDB 10.5+ compatible (InnoDB, utf8mb4)

CREATE TABLE IF NOT EXISTS schema_migrations (
  version      VARCHAR(100) NOT NULL,
  applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- users/auth

CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email            VARCHAR(255) NOT NULL,
  normalized_email VARCHAR(255) NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  name             VARCHAR(120) NOT NULL DEFAULT '',
  role             ENUM('admin','operator','viewer') NOT NULL DEFAULT 'operator',
  timezone         VARCHAR(64)  NOT NULL DEFAULT 'UTC',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at    DATETIME     NULL,
  password_changed_at DATETIME  NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_normalized_email (normalized_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login throttling. One row per attempt; pruned by the cleanup cron.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier   VARCHAR(255) NOT NULL,          -- normalized email
  ip_address   VARBINARY(16) NULL,
  successful   TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_identifier (identifier, attempted_at),
  KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic token-bucket style counters for public endpoints (tracking, unsubscribe).
CREATE TABLE IF NOT EXISTS rate_limit_counters (
  bucket_key   VARCHAR(190) NOT NULL,
  window_start DATETIME     NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start),
  KEY idx_rate_limit_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ contacts

CREATE TABLE IF NOT EXISTS contacts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email              VARCHAR(255) NOT NULL,
  normalized_email   VARCHAR(255) NOT NULL,
  first_name         VARCHAR(120) NOT NULL DEFAULT '',
  last_name          VARCHAR(120) NOT NULL DEFAULT '',
  company_name       VARCHAR(190) NOT NULL DEFAULT '',
  website            VARCHAR(255) NOT NULL DEFAULT '',
  country            VARCHAR(100) NOT NULL DEFAULT '',
  source             VARCHAR(120) NOT NULL DEFAULT '',
  custom_fields_json JSON NULL,
  status             ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  unsubscribed_at    DATETIME NULL,
  unsubscribe_token  CHAR(43) NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contacts_normalized_email (normalized_email),
  UNIQUE KEY uq_contacts_unsubscribe_token (unsubscribe_token),
  KEY idx_contacts_created_at (created_at),
  KEY idx_contacts_status (status),
  KEY idx_contacts_country (country),
  KEY idx_contacts_source (source),
  KEY idx_contacts_last_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lists (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(190) NOT NULL,
  description TEXT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lists_name (name),
  CONSTRAINT fk_lists_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exclusive membership is enforced here: UNIQUE(contact_id) means a contact
-- can hold at most one active list membership, regardless of concurrency.
CREATE TABLE IF NOT EXISTS list_contacts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_id     INT UNSIGNED NOT NULL,
  contact_id  BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_list_contacts_contact (contact_id),
  KEY idx_list_contacts_list (list_id, contact_id),
  CONSTRAINT fk_list_contacts_list FOREIGN KEY (list_id) REFERENCES lists (id) ON DELETE CASCADE,
  CONSTRAINT fk_list_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE,
  CONSTRAINT fk_list_contacts_user FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- suppressions

CREATE TABLE IF NOT EXISTS suppressions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  normalized_email VARCHAR(255) NOT NULL,
  reason           ENUM('unsubscribe','complaint','hard_bounce','manual_block','invalid_address','soft_bounce_threshold') NOT NULL,
  source           VARCHAR(120) NOT NULL DEFAULT 'system',
  campaign_id      INT UNSIGNED NULL,
  notes            VARCHAR(500) NOT NULL DEFAULT '',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppressions_email (normalized_email),
  KEY idx_suppressions_reason (reason, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only audit trail: survives suppression removal and re-import.
CREATE TABLE IF NOT EXISTS suppression_events (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  normalized_email VARCHAR(255) NOT NULL,
  action           ENUM('added','removed','reaffirmed') NOT NULL,
  reason           VARCHAR(60) NOT NULL DEFAULT '',
  source           VARCHAR(120) NOT NULL DEFAULT 'system',
  actor_user_id    INT UNSIGNED NULL,
  notes            VARCHAR(500) NOT NULL DEFAULT '',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_suppression_events_email (normalized_email, created_at),
  CONSTRAINT fk_suppression_events_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- campaigns

CREATE TABLE IF NOT EXISTS campaigns (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(190) NOT NULL,
  list_id              INT UNSIGNED NULL,
  from_name            VARCHAR(120) NOT NULL DEFAULT '',
  from_email           VARCHAR(255) NOT NULL DEFAULT '',
  reply_to             VARCHAR(255) NOT NULL DEFAULT '',
  subject              VARCHAR(500) NOT NULL DEFAULT '',
  preview_text         VARCHAR(500) NOT NULL DEFAULT '',
  html_body            MEDIUMTEXT NULL,
  text_body            MEDIUMTEXT NULL,
  status               ENUM('draft','scheduled','queued','sending','paused','completed','failed','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at_utc     DATETIME NULL,
  timezone             VARCHAR(64) NOT NULL DEFAULT 'UTC',
  send_rate_per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  track_opens          TINYINT(1) NOT NULL DEFAULT 1,
  track_clicks         TINYINT(1) NOT NULL DEFAULT 1,
  total_recipients     INT UNSIGNED NOT NULL DEFAULT 0,
  queued_at            DATETIME NULL,
  started_at           DATETIME NULL,
  completed_at         DATETIME NULL,
  cancelled_at         DATETIME NULL,
  last_error           VARCHAR(500) NOT NULL DEFAULT '',
  consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_campaigns_status (status, scheduled_at_utc),
  KEY idx_campaigns_list (list_id),
  KEY idx_campaigns_created (created_at),
  CONSTRAINT fk_campaigns_list FOREIGN KEY (list_id) REFERENCES lists (id) ON DELETE RESTRICT,
  CONSTRAINT fk_campaigns_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approved click destinations. A tracking URL references a link id, never a
-- caller-supplied destination, which makes open redirects structurally impossible.
CREATE TABLE IF NOT EXISTS campaign_links (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id INT UNSIGNED NOT NULL,
  url_hash    CHAR(64) NOT NULL,
  url         TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_links (campaign_id, url_hash),
  CONSTRAINT fk_campaign_links_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_recipients (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id              INT UNSIGNED NOT NULL,
  contact_id               BIGINT UNSIGNED NULL,
  recipient_email_snapshot VARCHAR(255) NOT NULL,
  merge_data_json          JSON NULL,
  tracking_token           CHAR(32) NOT NULL,
  status                   ENUM('pending','sending','accepted','delivered','bounced','complained','failed','suppressed','cancelled','needs_reconciliation') NOT NULL DEFAULT 'pending',
  attempts                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ses_message_id           VARCHAR(255) NULL,
  last_error_code          VARCHAR(100) NOT NULL DEFAULT '',
  last_error_message       VARCHAR(500) NOT NULL DEFAULT '',
  claim_token              CHAR(32) NULL,
  claimed_at               DATETIME NULL,
  next_attempt_at          DATETIME NULL,
  accepted_at              DATETIME NULL,
  delivered_at             DATETIME NULL,
  bounced_at               DATETIME NULL,
  complained_at            DATETIME NULL,
  first_opened_at          DATETIME NULL,
  first_clicked_at         DATETIME NULL,
  unsubscribed_at          DATETIME NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_recipient (campaign_id, contact_id),
  UNIQUE KEY uq_campaign_recipient_token (tracking_token),
  KEY idx_campaign_recipients_dispatch (campaign_id, status, id),
  KEY idx_campaign_recipients_claimed (status, claimed_at),
  KEY idx_campaign_recipients_message (ses_message_id),
  KEY idx_campaign_recipients_email (recipient_email_snapshot),
  CONSTRAINT fk_campaign_recipients_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
  CONSTRAINT fk_campaign_recipients_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- email events

CREATE TABLE IF NOT EXISTS email_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id       INT UNSIGNED NULL,
  recipient_id      BIGINT UNSIGNED NULL,
  contact_id        BIGINT UNSIGNED NULL,
  provider_event_id VARCHAR(255) NULL,
  dedupe_key        CHAR(64) NOT NULL,
  event_type        ENUM('send','delivery','bounce','complaint','reject','rendering_failure','delivery_delay','open','click','unsubscribe','subscription','failure') NOT NULL,
  event_subtype     VARCHAR(80) NOT NULL DEFAULT '',
  event_timestamp   DATETIME NOT NULL,
  metadata_json     JSON NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_events_dedupe (dedupe_key),
  KEY idx_email_events_campaign (campaign_id, event_type, event_timestamp),
  KEY idx_email_events_recipient (recipient_id, event_type),
  KEY idx_email_events_time (event_timestamp, event_type),
  CONSTRAINT fk_email_events_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
  CONSTRAINT fk_email_events_recipient FOREIGN KEY (recipient_id) REFERENCES campaign_recipients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw provider notifications land here first and are processed asynchronously,
-- so a slow or failing handler never loses an SNS delivery.
CREATE TABLE IF NOT EXISTS provider_notifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source       VARCHAR(40) NOT NULL DEFAULT 'ses_sns',
  message_id   VARCHAR(255) NOT NULL,
  topic_arn    VARCHAR(255) NOT NULL DEFAULT '',
  payload_json MEDIUMTEXT NOT NULL,
  status       ENUM('pending','processed','failed','ignored') NOT NULL DEFAULT 'pending',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error   VARCHAR(500) NOT NULL DEFAULT '',
  received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_notifications_message (source, message_id),
  KEY idx_provider_notifications_status (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materialised per-campaign counters, refreshed by cron and after event batches.
CREATE TABLE IF NOT EXISTS campaign_stats (
  campaign_id      INT UNSIGNED NOT NULL,
  total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
  attempted        INT UNSIGNED NOT NULL DEFAULT 0,
  accepted         INT UNSIGNED NOT NULL DEFAULT 0,
  delivered        INT UNSIGNED NOT NULL DEFAULT 0,
  hard_bounces     INT UNSIGNED NOT NULL DEFAULT 0,
  soft_bounces     INT UNSIGNED NOT NULL DEFAULT 0,
  rejects          INT UNSIGNED NOT NULL DEFAULT 0,
  complaints       INT UNSIGNED NOT NULL DEFAULT 0,
  unique_opens     INT UNSIGNED NOT NULL DEFAULT 0,
  total_opens      INT UNSIGNED NOT NULL DEFAULT 0,
  unique_clicks    INT UNSIGNED NOT NULL DEFAULT 0,
  total_clicks     INT UNSIGNED NOT NULL DEFAULT 0,
  unsubscribes     INT UNSIGNED NOT NULL DEFAULT 0,
  pending          INT UNSIGNED NOT NULL DEFAULT 0,
  failed           INT UNSIGNED NOT NULL DEFAULT 0,
  suppressed       INT UNSIGNED NOT NULL DEFAULT 0,
  refreshed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (campaign_id),
  CONSTRAINT fk_campaign_stats_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- imports

CREATE TABLE IF NOT EXISTS import_jobs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  original_filename VARCHAR(255) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL,
  file_size_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_checksum     CHAR(64) NOT NULL DEFAULT '',
  delimiter_char    VARCHAR(4) NOT NULL DEFAULT ',',
  has_header        TINYINT(1) NOT NULL DEFAULT 1,
  mapping_json      JSON NULL,
  options_json      JSON NULL,
  target_list_id    INT UNSIGNED NULL,
  duplicate_mode    ENUM('skip','update','new_only') NOT NULL DEFAULT 'skip',
  status            ENUM('uploaded','mapped','queued','processing','completed','failed','cancelled') NOT NULL DEFAULT 'uploaded',
  total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  processed_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  inserted_rows     INT UNSIGNED NOT NULL DEFAULT 0,
  updated_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  failed_rows       INT UNSIGNED NOT NULL DEFAULT 0,
  resume_byte_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
  resume_row_number INT UNSIGNED NOT NULL DEFAULT 0,
  error_report_path VARCHAR(500) NOT NULL DEFAULT '',
  last_error        VARCHAR(500) NOT NULL DEFAULT '',
  created_by        INT UNSIGNED NULL,
  started_at        DATETIME NULL,
  completed_at      DATETIME NULL,
  heartbeat_at      DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_import_jobs_status (status, created_at),
  CONSTRAINT fk_import_jobs_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_import_jobs_list FOREIGN KEY (target_list_id) REFERENCES lists (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_row_errors (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_job_id BIGINT UNSIGNED NOT NULL,
  csv_row_number INT UNSIGNED NOT NULL,
  email         VARCHAR(255) NOT NULL DEFAULT '',
  error_code    VARCHAR(60) NOT NULL,
  error_message VARCHAR(400) NOT NULL,
  raw_row_json  JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_import_row_errors_job (import_job_id, csv_row_number),
  CONSTRAINT fk_import_row_errors_job FOREIGN KEY (import_job_id) REFERENCES import_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- operational

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  value_type    ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cooperative locks for cron workers. Expiry makes a crashed worker recoverable.
CREATE TABLE IF NOT EXISTS processing_locks (
  lock_key     VARCHAR(120) NOT NULL,
  owner_token  CHAR(32) NOT NULL,
  owner_info   VARCHAR(190) NOT NULL DEFAULT '',
  acquired_at  DATETIME NOT NULL,
  heartbeat_at DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  PRIMARY KEY (lock_key),
  KEY idx_processing_locks_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-minute / per-day send counters used to enforce configured sending limits.
CREATE TABLE IF NOT EXISTS send_counters (
  bucket_type ENUM('minute','day') NOT NULL,
  bucket_key  VARCHAR(20) NOT NULL,
  sent_count  INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (bucket_type, bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  actor_label  VARCHAR(120) NOT NULL DEFAULT '',
  action       VARCHAR(100) NOT NULL,
  entity_type  VARCHAR(60) NOT NULL DEFAULT '',
  entity_id    VARCHAR(60) NOT NULL DEFAULT '',
  ip_address   VARBINARY(16) NULL,
  user_agent   VARCHAR(255) NOT NULL DEFAULT '',
  details_json JSON NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_created (created_at),
  KEY idx_audit_logs_action (action, created_at),
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deliverability alerts. alert_key keeps one open row per distinct condition.
CREATE TABLE IF NOT EXISTS alerts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_key       VARCHAR(190) NOT NULL,
  category        VARCHAR(60) NOT NULL,
  severity        ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  status          ENUM('active','acknowledged','resolved') NOT NULL DEFAULT 'active',
  title           VARCHAR(190) NOT NULL,
  message         VARCHAR(800) NOT NULL DEFAULT '',
  metrics_json    JSON NULL,
  campaign_id     INT UNSIGNED NULL,
  first_seen_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at DATETIME NULL,
  acknowledged_by INT UNSIGNED NULL,
  resolved_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alerts_open (alert_key, status),
  KEY idx_alerts_status (status, severity, last_seen_at),
  CONSTRAINT fk_alerts_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
  CONSTRAINT fk_alerts_user FOREIGN KEY (acknowledged_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Heartbeats written by each cron worker, used to detect a stalled scheduler.
CREATE TABLE IF NOT EXISTS cron_heartbeats (
  job_name      VARCHAR(80) NOT NULL,
  last_run_at   DATETIME NOT NULL,
  last_status   ENUM('ok','error') NOT NULL DEFAULT 'ok',
  last_message  VARCHAR(500) NOT NULL DEFAULT '',
  duration_ms   INT UNSIGNED NOT NULL DEFAULT 0,
  run_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('001_initial_schema')
  ON DUPLICATE KEY UPDATE version = version;
