-- Horus :: self-open detection (the sender viewing their own Sent copy)

ALTER TABLE horus_messages ADD COLUMN sender_ip  varchar(45) DEFAULT NULL;
ALTER TABLE horus_messages ADD COLUMN self_count integer NOT NULL DEFAULT 0;
