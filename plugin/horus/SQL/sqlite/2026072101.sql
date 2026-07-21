-- Horus :: richer event forensics (reverse DNS, parsed client, raw request context)

ALTER TABLE horus_events ADD COLUMN hostname   varchar(255) DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN ip_version integer      DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN client     varchar(64)  DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN client_ver varchar(32)  DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN os         varchar(64)  DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN device     varchar(32)  DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN language   varchar(64)  DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN referer    varchar(512) DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN forwarded  varchar(255) DEFAULT NULL;
ALTER TABLE horus_events ADD COLUMN headers    text         DEFAULT NULL;

CREATE INDEX IF NOT EXISTS horus_events_hostname ON horus_events (hostname);

CREATE TABLE IF NOT EXISTS horus_ipinfo (
    ip          varchar(45) NOT NULL PRIMARY KEY,
    hostname    varchar(255) DEFAULT NULL,
    resolved_at datetime NOT NULL
);
