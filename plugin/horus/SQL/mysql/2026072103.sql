-- Horus :: keep tracked attachments across draft save / reopen

ALTER TABLE `horus_documents`
  ADD COLUMN `draft_msgid` varchar(255) DEFAULT NULL AFTER `compose_id`;

CREATE INDEX `horus_documents_draft` ON `horus_documents` (`user_id`, `draft_msgid`);
