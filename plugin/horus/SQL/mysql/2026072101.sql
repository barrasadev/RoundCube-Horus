-- Horus :: richer event forensics (reverse DNS, parsed client, raw request context)

ALTER TABLE `horus_events`
  ADD COLUMN `hostname`    varchar(255) DEFAULT NULL AFTER `ip`,
  ADD COLUMN `ip_version`  tinyint(4)   DEFAULT NULL AFTER `hostname`,
  ADD COLUMN `client`      varchar(64)  DEFAULT NULL AFTER `user_agent`,
  ADD COLUMN `client_ver`  varchar(32)  DEFAULT NULL AFTER `client`,
  ADD COLUMN `os`          varchar(64)  DEFAULT NULL AFTER `client_ver`,
  ADD COLUMN `device`      varchar(32)  DEFAULT NULL AFTER `os`,
  ADD COLUMN `language`    varchar(64)  DEFAULT NULL AFTER `device`,
  ADD COLUMN `referer`     varchar(512) DEFAULT NULL AFTER `language`,
  ADD COLUMN `forwarded`   varchar(255) DEFAULT NULL AFTER `referer`,
  ADD COLUMN `headers`     text         DEFAULT NULL AFTER `forwarded`;

CREATE INDEX `horus_events_hostname` ON `horus_events` (`hostname`);

-- Reverse-DNS cache. A PTR lookup is a blocking network call, so each address is
-- resolved once and reused; negative results are cached too, otherwise every hit
-- from a PTR-less address would pay the full DNS timeout again.
CREATE TABLE IF NOT EXISTS `horus_ipinfo` (
  `ip`          varchar(45) NOT NULL,
  `hostname`    varchar(255) DEFAULT NULL,
  `resolved_at` datetime NOT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
