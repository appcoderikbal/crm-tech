<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use Throwable;

use const TechzappCrm\DB_VERSION;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema installation and upgrades.
 *
 * Tables are created with dbDelta(), which is what WordPress expects and what
 * makes later column additions safe. dbDelta cannot handle FOREIGN KEY
 * clauses — it would try to re-add them on every run — so the schema has none
 * and the cascade behaviour lives in Cleanup instead.
 *
 * The UNIQUE indexes are a different matter: they are the correctness
 * guarantees (one list per contact, one recipient row per campaign, one event
 * per provider notification) and dbDelta handles them correctly.
 */
final class Installer
{
    public const VERSION_OPTION = 'techzapp_crm_db_version';

    public static function activate(): void
    {
        self::installSchema();

        Settings::installDefaults();
        Capabilities::install();

        try {
            Storage::install();
        } catch (Throwable $e) {
            Logger::exception('installer.storage_failed', $e);
        }

        Cron::schedule();

        update_option(self::VERSION_OPTION, DB_VERSION, false);
        update_option('techzapp_crm_activated_at', gmdate('Y-m-d H:i:s'), false);

        // The rewrite rules for the public endpoints are REST routes, which
        // need the rules flushed once so pretty permalinks pick them up.
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        Cron::unschedule();
        flush_rewrite_rules(false);
    }

    /** Runs the schema again when the plugin files are newer than the database. */
    public static function maybeUpgrade(): void
    {
        $installed = get_option(self::VERSION_OPTION, '');

        if ($installed === DB_VERSION) {
            return;
        }

        self::installSchema();
        Settings::installDefaults();
        Capabilities::install();

        update_option(self::VERSION_OPTION, DB_VERSION, false);
        Logger::info('installer.upgraded', ['from' => $installed ?: 'none', 'to' => DB_VERSION]);
    }

    public static function installSchema(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = self::wpdbCharsetCollate();

        foreach (self::tableDefinitions($charset) as $sql) {
            dbDelta($sql);
        }
    }

    private static function wpdbCharsetCollate(): string
    {
        $collate = Database::wpdb()->get_charset_collate();
        // utf8mb4 is required: campaign content and contact names carry emoji
        // and non-Latin scripts, and utf8 (3-byte) silently truncates them.
        return $collate !== '' ? $collate : 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * @return list<string> one CREATE TABLE statement per plugin table
     */
    private static function tableDefinitions(string $charset): array
    {
        $t = static fn (string $name): string => Database::table($name);

        $definitions = [];

        // ------------------------------------------------------------ contacts
        $definitions[] = "CREATE TABLE {$t('contacts')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(255) NOT NULL,
  normalized_email varchar(255) NOT NULL,
  first_name varchar(120) NOT NULL DEFAULT '',
  last_name varchar(120) NOT NULL DEFAULT '',
  company_name varchar(190) NOT NULL DEFAULT '',
  website varchar(255) NOT NULL DEFAULT '',
  country varchar(100) NOT NULL DEFAULT '',
  source varchar(120) NOT NULL DEFAULT '',
  custom_fields_json longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  unsubscribed_at datetime NULL,
  unsubscribe_token char(43) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_normalized_email (normalized_email),
  UNIQUE KEY uq_unsubscribe_token (unsubscribe_token),
  KEY idx_created_at (created_at),
  KEY idx_status (status),
  KEY idx_country (country),
  KEY idx_source (source),
  KEY idx_last_name (last_name,first_name)
) {$charset};";

        // --------------------------------------------------------------- lists
        $definitions[] = "CREATE TABLE {$t('lists')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  description text NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_name (name)
) {$charset};";

        // Exclusive membership lives in UNIQUE KEY uq_contact.
        $definitions[] = "CREATE TABLE {$t('list_contacts')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_id bigint(20) unsigned NOT NULL,
  contact_id bigint(20) unsigned NOT NULL,
  assigned_at datetime NOT NULL,
  assigned_by bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_contact (contact_id),
  KEY idx_list (list_id,contact_id)
) {$charset};";

        // --------------------------------------------------------- suppressions
        $definitions[] = "CREATE TABLE {$t('suppressions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  normalized_email varchar(255) NOT NULL,
  reason varchar(40) NOT NULL,
  source varchar(120) NOT NULL DEFAULT 'system',
  campaign_id bigint(20) unsigned NULL,
  notes varchar(500) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_email (normalized_email),
  KEY idx_reason (reason,created_at)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('suppression_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  normalized_email varchar(255) NOT NULL,
  action varchar(20) NOT NULL,
  reason varchar(60) NOT NULL DEFAULT '',
  source varchar(120) NOT NULL DEFAULT 'system',
  actor_user_id bigint(20) unsigned NULL,
  notes varchar(500) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_email (normalized_email,created_at)
) {$charset};";

        // ----------------------------------------------------------- campaigns
        $definitions[] = "CREATE TABLE {$t('campaigns')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  list_id bigint(20) unsigned NULL,
  from_name varchar(120) NOT NULL DEFAULT '',
  from_email varchar(255) NOT NULL DEFAULT '',
  reply_to varchar(255) NOT NULL DEFAULT '',
  subject varchar(500) NOT NULL DEFAULT '',
  preview_text varchar(500) NOT NULL DEFAULT '',
  html_body longtext NULL,
  text_body longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  scheduled_at_utc datetime NULL,
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  send_rate_per_minute smallint(5) unsigned NOT NULL DEFAULT 60,
  track_opens tinyint(1) NOT NULL DEFAULT 1,
  track_clicks tinyint(1) NOT NULL DEFAULT 1,
  total_recipients int(10) unsigned NOT NULL DEFAULT 0,
  queued_at datetime NULL,
  started_at datetime NULL,
  completed_at datetime NULL,
  cancelled_at datetime NULL,
  last_error varchar(500) NOT NULL DEFAULT '',
  consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_status (status,scheduled_at_utc),
  KEY idx_list (list_id),
  KEY idx_created (created_at)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('campaign_links')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  url_hash char(64) NOT NULL,
  url text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_campaign_url (campaign_id,url_hash)
) {$charset};";

        // One row per campaign per contact: UNIQUE KEY uq_recipient is what
        // stops overlapping cron runs from sending twice.
        $definitions[] = "CREATE TABLE {$t('campaign_recipients')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  contact_id bigint(20) unsigned NULL,
  recipient_email_snapshot varchar(255) NOT NULL,
  merge_data_json longtext NULL,
  tracking_token char(32) NOT NULL,
  status varchar(24) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  ses_message_id varchar(255) NULL,
  last_error_code varchar(100) NOT NULL DEFAULT '',
  last_error_message varchar(500) NOT NULL DEFAULT '',
  claim_token char(32) NULL,
  claimed_at datetime NULL,
  next_attempt_at datetime NULL,
  accepted_at datetime NULL,
  delivered_at datetime NULL,
  bounced_at datetime NULL,
  complained_at datetime NULL,
  first_opened_at datetime NULL,
  first_clicked_at datetime NULL,
  unsubscribed_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_recipient (campaign_id,contact_id),
  UNIQUE KEY uq_tracking_token (tracking_token),
  KEY idx_dispatch (campaign_id,status,id),
  KEY idx_claimed (status,claimed_at),
  KEY idx_message (ses_message_id),
  KEY idx_email (recipient_email_snapshot)
) {$charset};";

        // -------------------------------------------------------------- events
        $definitions[] = "CREATE TABLE {$t('email_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NULL,
  recipient_id bigint(20) unsigned NULL,
  contact_id bigint(20) unsigned NULL,
  provider_event_id varchar(255) NULL,
  dedupe_key char(64) NOT NULL,
  event_type varchar(24) NOT NULL,
  event_subtype varchar(80) NOT NULL DEFAULT '',
  event_timestamp datetime NOT NULL,
  metadata_json longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_dedupe (dedupe_key),
  KEY idx_campaign (campaign_id,event_type,event_timestamp),
  KEY idx_recipient (recipient_id,event_type),
  KEY idx_time (event_timestamp,event_type)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('provider_notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(40) NOT NULL DEFAULT 'ses_sns',
  message_id varchar(255) NOT NULL,
  topic_arn varchar(255) NOT NULL DEFAULT '',
  payload_json longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_error varchar(500) NOT NULL DEFAULT '',
  received_at datetime NOT NULL,
  processed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_message (source,message_id),
  KEY idx_status (status,received_at)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('campaign_stats')} (
  campaign_id bigint(20) unsigned NOT NULL,
  total_recipients int(10) unsigned NOT NULL DEFAULT 0,
  attempted int(10) unsigned NOT NULL DEFAULT 0,
  accepted int(10) unsigned NOT NULL DEFAULT 0,
  delivered int(10) unsigned NOT NULL DEFAULT 0,
  hard_bounces int(10) unsigned NOT NULL DEFAULT 0,
  soft_bounces int(10) unsigned NOT NULL DEFAULT 0,
  rejects int(10) unsigned NOT NULL DEFAULT 0,
  complaints int(10) unsigned NOT NULL DEFAULT 0,
  unique_opens int(10) unsigned NOT NULL DEFAULT 0,
  total_opens int(10) unsigned NOT NULL DEFAULT 0,
  unique_clicks int(10) unsigned NOT NULL DEFAULT 0,
  total_clicks int(10) unsigned NOT NULL DEFAULT 0,
  unsubscribes int(10) unsigned NOT NULL DEFAULT 0,
  pending int(10) unsigned NOT NULL DEFAULT 0,
  failed int(10) unsigned NOT NULL DEFAULT 0,
  suppressed int(10) unsigned NOT NULL DEFAULT 0,
  refreshed_at datetime NOT NULL,
  PRIMARY KEY  (campaign_id)
) {$charset};";

        // ------------------------------------------------------------- imports
        $definitions[] = "CREATE TABLE {$t('import_jobs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  original_filename varchar(255) NOT NULL,
  stored_path varchar(500) NOT NULL,
  file_size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  file_checksum char(64) NOT NULL DEFAULT '',
  delimiter_char varchar(4) NOT NULL DEFAULT ',',
  has_header tinyint(1) NOT NULL DEFAULT 1,
  mapping_json longtext NULL,
  options_json longtext NULL,
  target_list_id bigint(20) unsigned NULL,
  duplicate_mode varchar(20) NOT NULL DEFAULT 'skip',
  status varchar(20) NOT NULL DEFAULT 'uploaded',
  total_rows int(10) unsigned NOT NULL DEFAULT 0,
  processed_rows int(10) unsigned NOT NULL DEFAULT 0,
  inserted_rows int(10) unsigned NOT NULL DEFAULT 0,
  updated_rows int(10) unsigned NOT NULL DEFAULT 0,
  skipped_rows int(10) unsigned NOT NULL DEFAULT 0,
  failed_rows int(10) unsigned NOT NULL DEFAULT 0,
  resume_byte_offset bigint(20) unsigned NOT NULL DEFAULT 0,
  resume_row_number int(10) unsigned NOT NULL DEFAULT 0,
  error_report_path varchar(500) NOT NULL DEFAULT '',
  last_error varchar(500) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NULL,
  started_at datetime NULL,
  completed_at datetime NULL,
  heartbeat_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_status (status,created_at)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('import_row_errors')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  import_job_id bigint(20) unsigned NOT NULL,
  csv_row_number int(10) unsigned NOT NULL,
  email varchar(255) NOT NULL DEFAULT '',
  error_code varchar(60) NOT NULL,
  error_message varchar(400) NOT NULL,
  raw_row_json longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_job (import_job_id,csv_row_number)
) {$charset};";

        // --------------------------------------------------------- operational
        $definitions[] = "CREATE TABLE {$t('processing_locks')} (
  lock_key varchar(120) NOT NULL,
  owner_token char(32) NOT NULL,
  owner_info varchar(190) NOT NULL DEFAULT '',
  acquired_at datetime NOT NULL,
  heartbeat_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (lock_key),
  KEY idx_expiry (expires_at)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('send_counters')} (
  bucket_type varchar(10) NOT NULL,
  bucket_key varchar(20) NOT NULL,
  sent_count int(10) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (bucket_type,bucket_key)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('rate_limit_counters')} (
  bucket_key varchar(190) NOT NULL,
  window_start datetime NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket_key,window_start),
  KEY idx_window (window_start)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('audit_logs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NULL,
  actor_label varchar(120) NOT NULL DEFAULT '',
  action varchar(100) NOT NULL,
  entity_type varchar(60) NOT NULL DEFAULT '',
  entity_id varchar(60) NOT NULL DEFAULT '',
  ip_address varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  details_json longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_created (created_at),
  KEY idx_action (action,created_at)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('alerts')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  alert_key varchar(190) NOT NULL,
  category varchar(60) NOT NULL,
  severity varchar(20) NOT NULL DEFAULT 'warning',
  status varchar(20) NOT NULL DEFAULT 'active',
  title varchar(190) NOT NULL,
  message varchar(800) NOT NULL DEFAULT '',
  metrics_json longtext NULL,
  campaign_id bigint(20) unsigned NULL,
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  acknowledged_at datetime NULL,
  acknowledged_by bigint(20) unsigned NULL,
  resolved_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_open (alert_key,status),
  KEY idx_status (status,severity,last_seen_at)
) {$charset};";

        $definitions[] = "CREATE TABLE {$t('cron_heartbeats')} (
  job_name varchar(80) NOT NULL,
  last_run_at datetime NOT NULL,
  last_status varchar(10) NOT NULL DEFAULT 'ok',
  last_message varchar(500) NOT NULL DEFAULT '',
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  run_count bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (job_name)
) {$charset};";

        return $definitions;
    }

    /** @return list<string> Tables the schema expects but the database lacks. */
    public static function missingTables(): array
    {
        $wpdb = Database::wpdb();
        $missing = [];

        foreach (Database::TABLES as $name) {
            $table = Database::table($name);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

            if ($found !== $table) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
