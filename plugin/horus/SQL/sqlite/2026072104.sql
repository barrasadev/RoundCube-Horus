-- Horus :: cached geolocation for observed addresses

ALTER TABLE horus_ipinfo ADD COLUMN country      varchar(64)  DEFAULT NULL;
ALTER TABLE horus_ipinfo ADD COLUMN country_code varchar(2)   DEFAULT NULL;
ALTER TABLE horus_ipinfo ADD COLUMN region       varchar(64)  DEFAULT NULL;
ALTER TABLE horus_ipinfo ADD COLUMN city         varchar(64)  DEFAULT NULL;
ALTER TABLE horus_ipinfo ADD COLUMN org          varchar(128) DEFAULT NULL;
ALTER TABLE horus_ipinfo ADD COLUMN geo_at datetime DEFAULT NULL;
