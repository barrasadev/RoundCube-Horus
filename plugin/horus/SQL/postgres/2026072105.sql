-- Horus :: scheduled messages (PostgreSQL)

CREATE TABLE IF NOT EXISTS horus_scheduled (
    scheduled_id        SERIAL PRIMARY KEY,
    uuid                varchar(36) NOT NULL,
    user_id             integer NOT NULL REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    status              varchar(16) NOT NULL DEFAULT 'pending',
    send_at             timestamp NOT NULL,
    created_at          timestamp NOT NULL,
    subject             varchar(512) DEFAULT NULL,
    recipients          text DEFAULT NULL,
    envelope_to         text DEFAULT NULL,
    from_addr           varchar(255) DEFAULT NULL,
    sent_mbox           varchar(255) DEFAULT NULL,
    storage_key         varchar(64) NOT NULL,
    compose_id          varchar(64) DEFAULT NULL,
    imap_host           varchar(255) DEFAULT NULL,
    imap_port           integer DEFAULT NULL,
    imap_ssl            varchar(16) DEFAULT NULL,
    imap_user           varchar(255) DEFAULT NULL,
    imap_pass_enc       text DEFAULT NULL,
    tracked             smallint NOT NULL DEFAULT 0,
    attempts            integer NOT NULL DEFAULT 0,
    last_error          text DEFAULT NULL,
    sent_message_id     integer DEFAULT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS horus_scheduled_uuid ON horus_scheduled (uuid);
CREATE INDEX IF NOT EXISTS horus_scheduled_user ON horus_scheduled (user_id, send_at);
CREATE INDEX IF NOT EXISTS horus_scheduled_due ON horus_scheduled (status, send_at);
