-- Horus :: self-open detection (the sender viewing their own Sent copy)

ALTER TABLE `horus_messages`
  ADD COLUMN `sender_ip`      varchar(45) DEFAULT NULL AFTER `from_addr`,
  ADD COLUMN `self_count`     int(11) NOT NULL DEFAULT 0 AFTER `real_open_count`;
