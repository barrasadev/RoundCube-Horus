-- Horus :: scheduled messages (MySQL / MariaDB)

CREATE TABLE IF NOT EXISTS `horus_scheduled` (
  `scheduled_id`     int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             varchar(36) NOT NULL,
  `user_id`          int(10) UNSIGNED NOT NULL,
  `status`           varchar(16) NOT NULL DEFAULT 'pending',
  `send_at`          datetime NOT NULL,
  `created_at`       datetime NOT NULL,
  `subject`          varchar(512) DEFAULT NULL,
  `recipients`       text DEFAULT NULL,
  `envelope_to`      text DEFAULT NULL,
  `from_addr`        varchar(255) DEFAULT NULL,
  `sent_mbox`        varchar(255) DEFAULT NULL,
  `storage_key`      varchar(64) NOT NULL,
  `compose_id`       varchar(64) DEFAULT NULL,
  `imap_host`        varchar(255) DEFAULT NULL,
  `imap_port`        int(11) DEFAULT NULL,
  `imap_ssl`         varchar(16) DEFAULT NULL,
  `imap_user`        varchar(255) DEFAULT NULL,
  `imap_pass_enc`    text DEFAULT NULL,
  `tracked`          tinyint(1) NOT NULL DEFAULT 0,
  `attempts`         int(11) NOT NULL DEFAULT 0,
  `last_error`       text DEFAULT NULL,
  `sent_message_id`  int(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`scheduled_id`),
  UNIQUE KEY `horus_scheduled_uuid` (`uuid`),
  KEY `horus_scheduled_user` (`user_id`, `send_at`),
  KEY `horus_scheduled_due` (`status`, `send_at`),
  CONSTRAINT `horus_scheduled_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
