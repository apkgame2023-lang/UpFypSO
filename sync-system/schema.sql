-- App sync system: the tables app_scraper.py writes and version_monitor.js reads.
--
--   mysql -u root -p -e "CREATE DATABASE app_sync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root -p app_sync < schema.sql
--
-- Works on MySQL 5.7+ and MariaDB 10.3+. All times are UTC: both scripts set
-- the session time zone to +00:00.

-- One row per catalog item, identified by its link. app_scraper.py owns every
-- column except notified_version / notified_at, which version_monitor.js owns.
-- Version columns compare byte for byte (utf8mb4_bin): "1.0-Beta" and
-- "1.0-beta" are different versions.
CREATE TABLE IF NOT EXISTS apps (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  link_hash          CHAR(64)        NOT NULL COMMENT 'SHA-256 of the normalized link',
  link               VARCHAR(2048)   NOT NULL,
  name               VARCHAR(255)    NOT NULL,
  source_url         VARCHAR(2048)   NOT NULL COMMENT 'Catalog page the item was last seen on',
  latest_version     VARCHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  previous_version   VARCHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  version_changed_at DATETIME        NULL,
  notified_version   VARCHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
                                     COMMENT 'Last version subscribers were told about; NULL until the monitor first sees the app',
  notified_at        DATETIME        NULL,
  first_seen_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apps_link_hash (link_hash),
  KEY idx_apps_version_changed (version_changed_at),
  KEY idx_apps_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every push version_monitor.js attempted, sent or failed. Failed rows count
-- towards MONITOR_MAX_ATTEMPTS for that app and version.
CREATE TABLE IF NOT EXISTS push_notifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_id       BIGINT UNSIGNED NOT NULL,
  from_version VARCHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  to_version   VARCHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  topic        VARCHAR(255)    NOT NULL,
  mode         ENUM('log', 'dry-run', 'live') NOT NULL,
  status       ENUM('sent', 'failed') NOT NULL,
  message_id   VARCHAR(255)    NULL,
  error        TEXT            NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_push_app_version (app_id, to_version, status),
  CONSTRAINT fk_push_app FOREIGN KEY (app_id) REFERENCES apps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
