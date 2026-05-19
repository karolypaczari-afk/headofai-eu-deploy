-- Head of AI — leads tábla
--
-- Futtatás: Hostinger hPanel → Databases → phpMyAdmin →
--          a `headofai_*` adatbázist kiválasztva → SQL fül → beillesztés → Go.
--
-- Egyszer kell lefuttatni az új MySQL adatbázis létrehozása után.
-- A `contact.php` minden sikeres lead-et ide is bemásol (a
-- submissions.log file-alapú log mellé, redundancia + lekérdezhetőség).

CREATE TABLE IF NOT EXISTS `leads` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `event_id`       VARCHAR(64)     NOT NULL,
  `name`           VARCHAR(120)    NOT NULL,
  `company`        VARCHAR(200)    NOT NULL,
  `email`          VARCHAR(190)    NOT NULL,
  `email_hash`     CHAR(64)        NOT NULL,
  `phone`          VARCHAR(40)     DEFAULT NULL,
  `phone_hash`     CHAR(64)        DEFAULT NULL,
  `challenge`      TEXT            NOT NULL,
  `attribution`    JSON            DEFAULT NULL,
  `ip_hash`        CHAR(64)        DEFAULT NULL,
  `user_agent`     VARCHAR(500)    DEFAULT NULL,
  `meta_capi_ok`   TINYINT(1)      DEFAULT NULL,
  `mailerlite_ok`  TINYINT(1)      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_event_id` (`event_id`),
  KEY `idx_email` (`email`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
