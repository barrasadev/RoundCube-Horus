-- Horus :: initial schema (PostgreSQL)

CREATE TABLE IF NOT EXISTS horus_messages (
    message_id          SERIAL PRIMARY KEY,
    uuid                varchar(36) NOT NULL,
    user_id             integer NOT NULL REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msgid               varchar(255) DEFAULT NULL,
    subject             varchar(512) DEFAULT NULL,
    recipients          text DEFAULT NULL,
    to_addr             varchar(255) DEFAULT NULL,
    from_addr           varchar(255) DEFAULT NULL,
    tracked             smallint NOT NULL DEFAULT 1,
    sent_at             timestamp NOT NULL,
    open_count          integer NOT NULL DEFAULT 0,
    real_open_count     integer NOT NULL DEFAULT 0,
    click_count         integer NOT NULL DEFAULT 0,
    first_open_at       timestamp DEFAULT NULL,
    first_real_open_at  timestamp DEFAULT NULL,
    last_open_at        timestamp DEFAULT NULL,
    first_click_at      timestamp DEFAULT NULL,
    human_confirmed     smallint NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX IF NOT EXISTS horus_messages_uuid ON horus_messages (uuid);
CREATE INDEX IF NOT EXISTS horus_messages_user ON horus_messages (user_id, sent_at);
CREATE INDEX IF NOT EXISTS horus_messages_to ON horus_messages (to_addr);
CREATE INDEX IF NOT EXISTS horus_messages_msgid ON horus_messages (msgid);

CREATE TABLE IF NOT EXISTS horus_documents (
    doc_id              SERIAL PRIMARY KEY,
    uuid                varchar(36) NOT NULL,
    message_id          integer DEFAULT NULL REFERENCES horus_messages(message_id) ON DELETE CASCADE ON UPDATE CASCADE,
    user_id             integer NOT NULL REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    compose_id          varchar(64) DEFAULT NULL,
    filename            varchar(255) NOT NULL,
    mimetype            varchar(255) NOT NULL DEFAULT 'application/octet-stream',
    size                integer NOT NULL DEFAULT 0,
    storage_key         varchar(255) NOT NULL,
    created_at          timestamp NOT NULL,
    view_count          integer NOT NULL DEFAULT 0,
    download_count      integer NOT NULL DEFAULT 0,
    first_view_at       timestamp DEFAULT NULL,
    first_download_at   timestamp DEFAULT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS horus_documents_uuid ON horus_documents (uuid);
CREATE INDEX IF NOT EXISTS horus_documents_message ON horus_documents (message_id);
CREATE INDEX IF NOT EXISTS horus_documents_compose ON horus_documents (user_id, compose_id);

CREATE TABLE IF NOT EXISTS horus_events (
    event_id    SERIAL PRIMARY KEY,
    message_id  integer NOT NULL REFERENCES horus_messages(message_id) ON DELETE CASCADE ON UPDATE CASCADE,
    doc_id      integer DEFAULT NULL,
    type        varchar(16) NOT NULL,
    status      varchar(16) NOT NULL DEFAULT 'unknown',
    reason      varchar(64) DEFAULT NULL,
    url         text DEFAULT NULL,
    ip          varchar(45) DEFAULT NULL,
    user_agent  varchar(512) DEFAULT NULL,
    created_at  timestamp NOT NULL
);

CREATE INDEX IF NOT EXISTS horus_events_message ON horus_events (message_id, created_at);
CREATE INDEX IF NOT EXISTS horus_events_doc ON horus_events (doc_id);

CREATE TABLE IF NOT EXISTS horus_netranges (
    range_id    SERIAL PRIMARY KEY,
    source      varchar(32) NOT NULL,
    cidr        varchar(64) NOT NULL,
    updated_at  timestamp NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS horus_netranges_uniq ON horus_netranges (source, cidr);
