-- Horus :: initial schema (MySQL / MariaDB)

CREATE TABLE IF NOT EXISTS `horus_messages` (
  `message_id`     int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`           varchar(36) NOT NULL,
  `user_id`        int(10) UNSIGNED NOT NULL,
  `msgid`          varchar(255) DEFAULT NULL,
  `subject`        varchar(512) DEFAULT NULL,
  `recipients`     text DEFAULT NULL,
  `to_addr`        varchar(255) DEFAULT NULL,
  `from_addr`      varchar(255) DEFAULT NULL,
  `tracked`        tinyint(1) NOT NULL DEFAULT 1,
  `sent_at`        datetime NOT NULL,
  `open_count`     int(11) NOT NULL DEFAULT 0,
  `real_open_count` int(11) NOT NULL DEFAULT 0,
  `click_count`    int(11) NOT NULL DEFAULT 0,
  `first_open_at`  datetime DEFAULT NULL,
  `first_real_open_at` datetime DEFAULT NULL,
  `last_open_at`   datetime DEFAULT NULL,
  `first_click_at` datetime DEFAULT NULL,
  `human_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`message_id`),
  UNIQUE KEY `horus_messages_uuid` (`uuid`),
  KEY `horus_messages_user` (`user_id`, `sent_at`),
  KEY `horus_messages_to` (`to_addr`),
  KEY `horus_messages_msgid` (`msgid`),
  CONSTRAINT `horus_messages_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `horus_documents` (
  `doc_id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`           varchar(36) NOT NULL,
  `message_id`     int(11) UNSIGNED DEFAULT NULL,
  `user_id`        int(10) UNSIGNED NOT NULL,
  `compose_id`     varchar(64) DEFAULT NULL,
  `filename`       varchar(255) NOT NULL,
  `mimetype`       varchar(255) NOT NULL DEFAULT 'application/octet-stream',
  `size`           int(11) NOT NULL DEFAULT 0,
  `storage_key`    varchar(255) NOT NULL,
  `created_at`     datetime NOT NULL,
  `view_count`     int(11) NOT NULL DEFAULT 0,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `first_view_at`  datetime DEFAULT NULL,
  `first_download_at` datetime DEFAULT NULL,
  PRIMARY KEY (`doc_id`),
  UNIQUE KEY `horus_documents_uuid` (`uuid`),
  KEY `horus_documents_message` (`message_id`),
  KEY `horus_documents_compose` (`user_id`, `compose_id`),
  CONSTRAINT `horus_documents_message_fk` FOREIGN KEY (`message_id`)
    REFERENCES `horus_messages` (`message_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `horus_documents_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `horus_events` (
  `event_id`   int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` int(11) UNSIGNED NOT NULL,
  `doc_id`     int(11) UNSIGNED DEFAULT NULL,
  `type`       varchar(16) NOT NULL,
  `status`     varchar(16) NOT NULL DEFAULT 'unknown',
  `reason`     varchar(64) DEFAULT NULL,
  `url`        text DEFAULT NULL,
  `ip`         varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `horus_events_message` (`message_id`, `created_at`),
  KEY `horus_events_doc` (`doc_id`),
  CONSTRAINT `horus_events_message_fk` FOREIGN KEY (`message_id`)
    REFERENCES `horus_messages` (`message_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache for externally published bot/proxy IP ranges (Apple MPP, iCloud Private Relay...).
CREATE TABLE IF NOT EXISTS `horus_netranges` (
  `range_id`   int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`     varchar(32) NOT NULL,
  `cidr`       varchar(64) NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`range_id`),
  UNIQUE KEY `horus_netranges_uniq` (`source`, `cidr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
