-- Techzapp CRM — default operational settings.
-- Secrets (AWS keys, DB credentials, app secret) are NEVER stored here;
-- they live in backend/config/config.php outside the web root.

INSERT INTO system_settings (setting_key, setting_value, value_type) VALUES
  ('sending_enabled',            '1',        'bool'),
  ('kill_switch_engaged',        '0',        'bool'),
  ('kill_switch_reason',         '',         'string'),
  ('max_send_rate_per_minute',   '120',      'int'),
  ('max_sends_per_day',          '50000',    'int'),
  ('max_sends_per_cron_run',     '400',      'int'),
  ('ses_daily_quota_cached',     '',         'string'),
  ('ses_quota_checked_at',       '',         'string'),
  ('bounce_rate_warning_pct',    '2.0',      'float'),
  ('bounce_rate_critical_pct',   '5.0',      'float'),
  ('complaint_rate_warning_pct', '0.05',     'float'),
  ('complaint_rate_critical_pct','0.1',      'float'),
  ('complaint_spike_auto_pause', '1',        'bool'),
  ('alert_rolling_window_hours', '72',       'int'),
  ('alert_min_sample_size',      '200',      'int'),
  ('cron_stale_minutes',         '30',       'int'),
  ('campaign_overdue_minutes',   '20',       'int'),
  ('monitoring_stale_minutes',   '60',       'int'),
  ('import_retention_hours',     '48',       'int'),
  ('import_max_file_mb',         '64',       'int'),
  ('import_batch_rows',          '500',      'int'),
  ('recipient_build_batch',      '2000',     'int'),
  ('soft_bounce_max_attempts',   '3',        'int'),
  ('reconcile_stale_minutes',    '15',       'int'),
  ('default_timezone',           'UTC',      'string'),
  ('tracking_base_url',          '',         'string'),
  ('company_postal_address',     '',         'string'),
  ('test_send_allowed_domains',  '[]',       'json'),
  ('personalization_fallbacks',  '{"first_name":"there","last_name":"","company_name":"your company","website":"","email":""}', 'json')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO schema_migrations (version) VALUES ('002_default_settings')
  ON DUPLICATE KEY UPDATE version = version;
