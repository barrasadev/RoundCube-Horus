-- Horus :: cached geolocation for observed addresses

ALTER TABLE `horus_ipinfo`
  ADD COLUMN `country`      varchar(64)  DEFAULT NULL,
  ADD COLUMN `country_code` varchar(2)   DEFAULT NULL,
  ADD COLUMN `region`       varchar(64)  DEFAULT NULL,
  ADD COLUMN `city`         varchar(64)  DEFAULT NULL,
  ADD COLUMN `org`          varchar(128) DEFAULT NULL,
  ADD COLUMN `geo_at`       datetime     DEFAULT NULL;
