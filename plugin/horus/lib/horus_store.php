<?php

/**
 * Horus :: data access layer.
 *
 * Every timestamp written here is UTC ('Y-m-d H:i:s'), never the database's idea of
 * "now". The tracking endpoints run without a session and the dashboard runs with one;
 * keeping a single, explicit clock is what makes their timestamps comparable.
 *
 * @license GNU GPLv3+
 */
class horus_store
{
    /** Open classification states. */
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_BOT       = 'bot';
    const STATUS_UNKNOWN   = 'unknown';

    /**
     * The sender looking at their own copy in Sent. Recorded so it is visible in the
     * timeline, but it moves no counter: it is not the recipient.
     */
    const STATUS_SELF      = 'self';

    /** Event types. */
    const EVENT_OPEN          = 'open';
    const EVENT_CLICK         = 'click';
    const EVENT_DOC_VIEW      = 'doc_view';
    const EVENT_DOC_DOWNLOAD  = 'doc_download';

    /**
     * A click landing this soon after the send is the mail gateway following links,
     * not a person. Same threshold the open classifier uses for prefetch.
     */
    const HUMAN_CLICK_DELAY = 60;

    /** @var rcube_db */
    private $db;

    public function __construct()
    {
        $this->db = rcmail::get_instance()->get_dbh();
    }

    public static function now()
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Turn a stored UTC string into a unix timestamp (null-safe).
     */
    public static function ts($value)
    {
        return $value ? strtotime($value . ' UTC') : null;
    }

    public static function uuid()
    {
        // 16 random bytes formatted as a v4 UUID.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function t($table)
    {
        return $this->db->table_name($table, true);
    }

    // ---------------------------------------------------------------- messages

    /**
     * Record an outgoing message.
     *
     * @param int   $user_id Roundcube user
     * @param array $data    uuid, msgid, subject, recipients[], from_addr, tracked
     *
     * @return int|false New message_id
     */
    public function create_message($user_id, array $data)
    {
        $recipients = array_values(array_filter((array) ($data['recipients'] ?? [])));

        $this->db->query(
            'INSERT INTO ' . $this->t('horus_messages')
            . ' (`uuid`, `user_id`, `msgid`, `subject`, `recipients`, `to_addr`, `from_addr`,'
            . '  `sender_ip`, `tracked`, `sent_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $data['uuid'],
            $user_id,
            $data['msgid'] ?? null,
            mb_substr((string) ($data['subject'] ?? ''), 0, 500),
            implode(', ', $recipients),
            mb_substr((string) ($recipients[0] ?? ''), 0, 250),
            mb_substr((string) ($data['from_addr'] ?? ''), 0, 250),
            $data['sender_ip'] ?? null,
            !empty($data['tracked']) ? 1 : 0,
            self::now()
        );

        return $this->db->insert_id('horus_messages');
    }

    public function get_message($message_id)
    {
        $sql = $this->db->query(
            'SELECT * FROM ' . $this->t('horus_messages') . ' WHERE `message_id` = ?', intval($message_id)
        );

        return $this->db->fetch_assoc($sql) ?: null;
    }

    public function get_message_by_uuid($uuid)
    {
        if (!self::valid_uuid($uuid)) {
            return null;
        }

        $sql = $this->db->query('SELECT * FROM ' . $this->t('horus_messages') . ' WHERE `uuid` = ?', $uuid);

        return $this->db->fetch_assoc($sql) ?: null;
    }

    /**
     * Look up a tracked message from the RFC Message-ID of the copy sitting in Sent.
     */
    public function get_message_by_msgid($user_id, $msgid)
    {
        if (empty($msgid)) {
            return null;
        }

        $sql = $this->db->query(
            'SELECT * FROM ' . $this->t('horus_messages') . ' WHERE `user_id` = ? AND `msgid` = ?',
            $user_id, $msgid
        );

        return $this->db->fetch_assoc($sql) ?: null;
    }

    /**
     * Counters for a page of Sent messages, in one query.
     *
     * @param array $msgids RFC Message-ID values
     *
     * @return array Rows with msgid plus everything horus_list::state_of() needs
     */
    public function states_by_msgid($user_id, array $msgids)
    {
        $msgids = array_values(array_filter($msgids));

        if (!$msgids) {
            return [];
        }

        $sql = $this->db->query(
            'SELECT m.`msgid`, m.`tracked`, m.`open_count`, m.`real_open_count`,'
            . ' m.`click_count`, m.`human_confirmed`,'
            . ' (SELECT COUNT(*) FROM ' . $this->t('horus_documents') . ' d'
            . '  WHERE d.`message_id` = m.`message_id` AND d.`download_count` > 0) AS doc_downloaded'
            . ' FROM ' . $this->t('horus_messages') . ' m'
            . ' WHERE m.`user_id` = ? AND m.`msgid` IN (' . rtrim(str_repeat('?,', count($msgids)), ',') . ')',
            $user_id, ...$msgids
        );

        return $this->fetch_all($sql);
    }

    /**
     * Every tracking record filed under one Message-ID.
     *
     * In per-recipient mode a single sent message produces one record per recipient,
     * all sharing the Message-ID of the copy that was filed in Sent.
     */
    public function get_messages_by_msgid($user_id, $msgid)
    {
        if (empty($msgid)) {
            return [];
        }

        $sql = $this->db->query(
            'SELECT * FROM ' . $this->t('horus_messages')
            . ' WHERE `user_id` = ? AND `msgid` = ? ORDER BY `message_id` ASC',
            $user_id, $msgid
        );

        return $this->fetch_all($sql);
    }

    public static function valid_uuid($uuid)
    {
        return is_string($uuid) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
    }

    // ------------------------------------------------------------------ events

    /**
     * Register a pixel open that has already been classified.
     *
     * Only 'confirmed' opens move the real_open_count / first_real_open_at counters —
     * that separation is what lets the UI show "opened" and "possibly opened" apart
     * instead of inflating one number.
     */
    public function record_open(array $message, $status, $reason, array $intel)
    {
        $now = self::now();

        $this->add_event($message['message_id'], null, self::EVENT_OPEN, $status, $reason, null, $intel);

        // A self view is logged and counted separately, never as a recipient open.
        if ($status === self::STATUS_SELF) {
            $this->db->query(
                'UPDATE ' . $this->t('horus_messages') . ' SET `self_count` = `self_count` + 1'
                . ' WHERE `message_id` = ?',
                $message['message_id']
            );

            return;
        }

        $sets   = ['`open_count` = `open_count` + 1', '`last_open_at` = ?'];
        $params = [$now];

        if (empty($message['first_open_at'])) {
            $sets[]   = '`first_open_at` = ?';
            $params[] = $now;
        }

        if ($status === self::STATUS_CONFIRMED) {
            $sets[] = '`real_open_count` = `real_open_count` + 1';

            if (empty($message['first_real_open_at'])) {
                $sets[]   = '`first_real_open_at` = ?';
                $params[] = $now;
            }
        }

        $params[] = $message['message_id'];

        $this->db->query(
            'UPDATE ' . $this->t('horus_messages') . ' SET ' . implode(', ', $sets) . ' WHERE `message_id` = ?',
            ...$params
        );
    }

    /**
     * Register a click. A click that arrives well after the send is the strongest
     * signal we ever get that a human was involved, so it also settles the message's
     * previously ambiguous opens.
     */
    public function record_click(array $message, $url, array $intel)
    {
        $now     = self::now();
        $elapsed = time() - (self::ts($message['sent_at']) ?: time());
        $human   = $elapsed >= self::HUMAN_CLICK_DELAY;

        $this->add_event(
            $message['message_id'], null, self::EVENT_CLICK,
            $human ? self::STATUS_CONFIRMED : self::STATUS_UNKNOWN,
            $human ? 'human_click' : 'immediate_click',
            $url, $intel
        );

        $sets   = ['`click_count` = `click_count` + 1'];
        $params = [];

        if (empty($message['first_click_at'])) {
            $sets[]   = '`first_click_at` = ?';
            $params[] = $now;
        }

        if ($human) {
            $sets[] = '`human_confirmed` = 1';
        }

        $params[] = $message['message_id'];

        $this->db->query(
            'UPDATE ' . $this->t('horus_messages') . ' SET ' . implode(', ', $sets) . ' WHERE `message_id` = ?',
            ...$params
        );

        if ($human) {
            $this->promote_unknown_opens($message);
        }
    }

    /**
     * A confirmed human interaction retroactively resolves 'unknown' opens on the same
     * message. Rows already classified as 'bot' are left alone: they really were bots,
     * and rewriting them would be exactly the metric inflation we are avoiding.
     */
    protected function promote_unknown_opens(array $message)
    {
        $this->db->query(
            'UPDATE ' . $this->t('horus_events')
            . ' SET `status` = ?, `reason` = ? WHERE `message_id` = ? AND `type` = ? AND `status` = ?',
            self::STATUS_CONFIRMED, 'click_reinforced', $message['message_id'], self::EVENT_OPEN, self::STATUS_UNKNOWN
        );

        if (!($promoted = $this->db->affected_rows())) {
            return;
        }

        $sets = ['`real_open_count` = `real_open_count` + ' . intval($promoted)];

        if (empty($message['first_real_open_at']) && !empty($message['first_open_at'])) {
            $sets[] = '`first_real_open_at` = ' . $this->db->quote($message['first_open_at']);
        }

        $this->db->query(
            'UPDATE ' . $this->t('horus_messages') . ' SET ' . implode(', ', $sets) . ' WHERE `message_id` = ?',
            $message['message_id']
        );
    }

    /**
     * Persist one event with its full request context.
     *
     * @param array $intel Output of horus_intel::collect()
     */
    public function add_event($message_id, $doc_id, $type, $status, $reason, $url, array $intel = [])
    {
        $this->db->query(
            'INSERT INTO ' . $this->t('horus_events')
            . ' (`message_id`, `doc_id`, `type`, `status`, `reason`, `url`,'
            . '  `ip`, `hostname`, `ip_version`, `user_agent`, `client`, `client_ver`,'
            . '  `os`, `device`, `language`, `referer`, `forwarded`, `headers`, `created_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $message_id, $doc_id, $type, $status, $reason,
            $url !== null ? mb_substr($url, 0, 2000) : null,
            $intel['ip']         ?? null,
            $intel['hostname']   ?? null,
            $intel['ip_version'] ?? null,
            $intel['user_agent'] ?? null,
            $intel['client']     ?? null,
            $intel['client_ver'] ?? null,
            $intel['os']         ?? null,
            $intel['device']     ?? null,
            $intel['language']   ?? null,
            $intel['referer']    ?? null,
            $intel['forwarded']  ?? null,
            $intel['headers']    ?? null,
            self::now()
        );
    }

    /**
     * Reclassify every open this user ever recorded from one address as a bot, and
     * bring the affected messages' counters back in line.
     *
     * This is the "I know that address is a scanner even though Horus did not" case.
     * It is retroactive on purpose: a message that read as Opened because of that
     * address should fall back to Possibly opened, not stay overstated.
     *
     * @return array [events reclassified, messages recomputed]
     */
    public function mark_ip_as_bot($user_id, $ip)
    {
        $events = $this->t('horus_events');
        $msgs   = $this->t('horus_messages');

        // Which messages are about to change, captured before the update.
        $sql = $this->db->query(
            "SELECT DISTINCT e.`message_id` FROM $events e"
            . " INNER JOIN $msgs m ON m.`message_id` = e.`message_id`"
            . ' WHERE m.`user_id` = ? AND e.`ip` = ?',
            $user_id, $ip
        );

        $ids = [];
        while ($row = $this->db->fetch_assoc($sql)) {
            $ids[] = intval($row['message_id']);
        }

        if (!$ids) {
            return [0, 0];
        }

        $in = implode(',', $ids);

        // Self views are excluded: they were never counted as anything, and flipping
        // them to 'bot' would leave self_count pointing at rows that no longer say
        // 'self' - recount() would then have nothing to rebuild it from.
        $this->db->query(
            "UPDATE $events SET `status` = ?, `reason` = ?"
            . " WHERE `ip` = ? AND `message_id` IN ($in) AND `status` NOT IN (?, ?)",
            self::STATUS_BOT, 'user_marked', $ip, self::STATUS_BOT, self::STATUS_SELF
        );

        $changed = $this->db->affected_rows();

        foreach ($ids as $id) {
            $this->recount($id);
        }

        return [$changed, count($ids)];
    }

    /**
     * Rebuild a message's cached counters from its events.
     *
     * The counters exist so the list and the dashboard do not have to aggregate the
     * event table on every read; anything that rewrites history has to call this or
     * the two will disagree.
     */
    public function recount($message_id)
    {
        $events = $this->t('horus_events');
        $msgs   = $this->t('horus_messages');

        $sql = $this->db->query(
            "SELECT `type`, `status`, `created_at` FROM $events"
            . ' WHERE `message_id` = ? ORDER BY `created_at` ASC, `event_id` ASC',
            intval($message_id)
        );

        $open = $real = $click = 0;
        $first_open = $first_real = $last_open = $first_click = null;
        $human = false;

        while ($row = $this->db->fetch_assoc($sql)) {
            $confirmed = $row['status'] === self::STATUS_CONFIRMED;

            switch ($row['type']) {
                case self::EVENT_OPEN:
                    // Self views were never counted and are not counted now.
                    if ($row['status'] === self::STATUS_SELF) {
                        break;
                    }

                    $open++;
                    $last_open = $row['created_at'];
                    $first_open = $first_open ?? $row['created_at'];

                    if ($confirmed) {
                        $real++;
                        $first_real = $first_real ?? $row['created_at'];
                    }
                    break;

                case self::EVENT_CLICK:
                    $click++;
                    $first_click = $first_click ?? $row['created_at'];

                    if ($confirmed) {
                        $human = true;
                    }
                    break;

                case self::EVENT_DOC_VIEW:
                case self::EVENT_DOC_DOWNLOAD:
                    if ($confirmed) {
                        $human = true;
                    }
                    break;
            }
        }

        $this->db->query(
            "UPDATE $msgs SET `open_count` = ?, `real_open_count` = ?, `click_count` = ?,"
            . ' `first_open_at` = ?, `first_real_open_at` = ?, `last_open_at` = ?,'
            . ' `first_click_at` = ?, `human_confirmed` = ? WHERE `message_id` = ?',
            $open, $real, $click, $first_open, $first_real, $last_open, $first_click,
            $human ? 1 : 0, intval($message_id)
        );
    }

    /**
     * Is this address already marked as a bot by this user?
     */
    public function ip_is_marked($user_id, $ip)
    {
        $settings = horus_settings::get($user_id);

        return horus_bots::cidr_match_any($ip, (array) ($settings['horus_bot_ranges'] ?? []));
    }

    // --------------------------------------------------------- reverse DNS cache

    public function get_ipinfo($ip)
    {
        $sql = $this->db->query('SELECT * FROM ' . $this->t('horus_ipinfo') . ' WHERE `ip` = ?', $ip);

        return $this->db->fetch_assoc($sql) ?: null;
    }

    public function put_ipinfo($ip, $hostname)
    {
        $table = $this->t('horus_ipinfo');
        $now   = self::now();

        $this->db->query("UPDATE $table SET `hostname` = ?, `resolved_at` = ? WHERE `ip` = ?", $hostname, $now, $ip);

        if (!$this->db->affected_rows()) {
            // Two concurrent tracking hits from the same address can race here; the
            // insert is allowed to fail rather than taking a lock on the hot path.
            $this->db->query("INSERT INTO $table (`ip`, `hostname`, `resolved_at`) VALUES (?, ?, ?)",
                $ip, $hostname, $now);
        }
    }

    public function events($message_id)
    {
        $sql = $this->db->query(
            'SELECT e.*, d.`filename` FROM ' . $this->t('horus_events') . ' e'
            . ' LEFT JOIN ' . $this->t('horus_documents') . ' d ON d.`doc_id` = e.`doc_id`'
            . ' WHERE e.`message_id` = ? ORDER BY e.`created_at` ASC, e.`event_id` ASC',
            $message_id
        );

        $out = [];
        while ($row = $this->db->fetch_assoc($sql)) {
            $out[] = $row;
        }

        return $out;
    }

    // --------------------------------------------------------------- documents

    /**
     * Register a tracked attachment uploaded while composing. It is bound to the
     * compose session first and only attached to a message when that message is sent.
     */
    public function create_document($user_id, $compose_id, array $file)
    {
        $uuid = self::uuid();

        $this->db->query(
            'INSERT INTO ' . $this->t('horus_documents')
            . ' (`uuid`, `user_id`, `compose_id`, `filename`, `mimetype`, `size`, `storage_key`, `created_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            $uuid, $user_id, $compose_id,
            mb_substr($file['name'], 0, 250),
            mb_substr($file['mimetype'], 0, 250),
            intval($file['size']),
            $file['storage_key'],
            self::now()
        );

        $doc_id = $this->db->insert_id('horus_documents');

        return $doc_id ? $this->get_document($doc_id) : null;
    }

    public function get_document($doc_id)
    {
        $sql = $this->db->query('SELECT * FROM ' . $this->t('horus_documents') . ' WHERE `doc_id` = ?', intval($doc_id));

        return $this->db->fetch_assoc($sql) ?: null;
    }

    public function get_document_by_uuid($uuid)
    {
        if (!self::valid_uuid($uuid)) {
            return null;
        }

        $sql = $this->db->query('SELECT * FROM ' . $this->t('horus_documents') . ' WHERE `uuid` = ?', $uuid);

        return $this->db->fetch_assoc($sql) ?: null;
    }

    /**
     * Tracked attachments staged for a compose session (i.e. not yet sent).
     */
    public function documents_for_compose($user_id, $compose_id)
    {
        $sql = $this->db->query(
            'SELECT * FROM ' . $this->t('horus_documents')
            . ' WHERE `user_id` = ? AND `compose_id` = ? AND `message_id` IS NULL ORDER BY `doc_id` ASC',
            $user_id, $compose_id
        );

        return $this->fetch_all($sql);
    }

    public function documents_for_message($message_id)
    {
        $sql = $this->db->query(
            'SELECT * FROM ' . $this->t('horus_documents') . ' WHERE `message_id` = ? ORDER BY `doc_id` ASC',
            $message_id
        );

        return $this->fetch_all($sql);
    }

    /**
     * Remember which draft a compose session's staged files belong to.
     *
     * Roundcube mints a new compose id every time a draft is reopened, so the compose
     * id alone cannot survive a save. The draft's Message-ID can - and unlike a custom
     * header it lives only in our own table, so nothing about tracking is ever written
     * into the message itself.
     */
    public function set_draft_msgid($user_id, $compose_id, $msgid)
    {
        $this->db->query(
            'UPDATE ' . $this->t('horus_documents') . ' SET `draft_msgid` = ?'
            . ' WHERE `user_id` = ? AND `compose_id` = ? AND `message_id` IS NULL',
            $msgid, $user_id, $compose_id
        );

        return $this->db->affected_rows();
    }

    /**
     * Hand a reopened draft's staged files to its new compose session.
     *
     * @return int Number of documents adopted
     */
    public function adopt_draft_documents($user_id, $msgid, $compose_id)
    {
        if (empty($msgid)) {
            return 0;
        }

        $this->db->query(
            'UPDATE ' . $this->t('horus_documents') . ' SET `compose_id` = ?'
            . ' WHERE `user_id` = ? AND `draft_msgid` = ? AND `message_id` IS NULL',
            $compose_id, $user_id, $msgid
        );

        return $this->db->affected_rows();
    }

    public function attach_documents_to_message($user_id, $compose_id, $message_id)
    {
        $this->db->query(
            'UPDATE ' . $this->t('horus_documents')
            . ' SET `message_id` = ? WHERE `user_id` = ? AND `compose_id` = ? AND `message_id` IS NULL',
            $message_id, $user_id, $compose_id
        );

        return $this->db->affected_rows();
    }

    public function delete_document($doc_id, $user_id)
    {
        $this->db->query(
            'DELETE FROM ' . $this->t('horus_documents')
            . ' WHERE `doc_id` = ? AND `user_id` = ? AND `message_id` IS NULL',
            intval($doc_id), $user_id
        );

        return $this->db->affected_rows() > 0;
    }

    /**
     * Register a document open or download.
     *
     * @param string $type EVENT_DOC_VIEW or EVENT_DOC_DOWNLOAD
     */
    public function record_document_event(array $doc, array $message, $type, array $intel)
    {
        $now       = self::now();
        $is_dl     = $type === self::EVENT_DOC_DOWNLOAD;
        $col_count = $is_dl ? 'download_count' : 'view_count';
        $col_first = $is_dl ? 'first_download_at' : 'first_view_at';

        // Fetching a document is a deliberate act on a link, so it is human evidence
        // in the same way a click is - classify it as such.
        $this->add_event(
            $message['message_id'], $doc['doc_id'], $type,
            self::STATUS_CONFIRMED, $is_dl ? 'doc_download' : 'doc_view',
            $doc['filename'], $intel
        );

        $sets = ["`$col_count` = `$col_count` + 1"];

        if (empty($doc[$col_first])) {
            $sets[] = "`$col_first` = " . $this->db->quote($now);
        }

        $this->db->query(
            'UPDATE ' . $this->t('horus_documents') . ' SET ' . implode(', ', $sets) . ' WHERE `doc_id` = ?',
            $doc['doc_id']
        );

        // Someone followed a link and asked for a file. Like a delayed click, that
        // settles any opens on this message we could not classify at the time.
        $this->db->query(
            'UPDATE ' . $this->t('horus_messages') . ' SET `human_confirmed` = 1 WHERE `message_id` = ?',
            $message['message_id']
        );

        $this->promote_unknown_opens($message);
    }

    /**
     * Staged uploads from compose sessions that were simply abandoned.
     *
     * Files belonging to a saved draft are deliberately excluded: that draft is still
     * sitting in the user's folder waiting to be finished, and deleting its
     * attachments out from under it would be the same bug we just fixed. They are
     * cleaned up when the draft is sent, or when the user deletes the draft.
     */
    public function orphan_documents($older_than_days)
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $older_than_days * 86400);

        $sql = $this->db->query(
            'SELECT * FROM ' . $this->t('horus_documents')
            . ' WHERE `message_id` IS NULL AND `draft_msgid` IS NULL AND `created_at` < ?',
            $cutoff
        );

        return $this->fetch_all($sql);
    }

    public function purge_document($doc_id)
    {
        $this->db->query('DELETE FROM ' . $this->t('horus_documents') . ' WHERE `doc_id` = ?', intval($doc_id));
    }

    // ------------------------------------------------------------- reporting

    /**
     * Headline metrics for the dashboard.
     */
    public function global_stats($user_id, $since = null, $until = null)
    {
        $where  = '`user_id` = ?';
        $params = [$user_id];

        if ($since) {
            $where   .= ' AND `sent_at` >= ?';
            $params[] = $since;
        }

        if ($until) {
            $where   .= ' AND `sent_at` <= ?';
            $params[] = $until;
        }

        $sql = $this->db->query(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN `tracked` = 1 THEN 1 ELSE 0 END) AS tracked,'
            // A message with a confirmed click counts as opened even if every pixel
            // fetch was automated - the click proves a person saw it. The two buckets
            // stay mutually exclusive so the rates can never double-count.
            . ' SUM(CASE WHEN `tracked` = 1 AND (`real_open_count` > 0 OR `human_confirmed` = 1) THEN 1 ELSE 0 END) AS opened_real,'
            . ' SUM(CASE WHEN `tracked` = 1 AND `real_open_count` = 0 AND `human_confirmed` = 0 AND `open_count` > 0 THEN 1 ELSE 0 END) AS opened_maybe,'
            . ' SUM(CASE WHEN `tracked` = 1 AND `click_count` > 0 THEN 1 ELSE 0 END) AS clicked'
            . ' FROM ' . $this->t('horus_messages') . " WHERE $where",
            ...$params
        );

        $row = $this->db->fetch_assoc($sql) ?: [];

        $stats = [
            'total'        => intval($row['total'] ?? 0),
            'tracked'      => intval($row['tracked'] ?? 0),
            'opened_real'  => intval($row['opened_real'] ?? 0),
            'opened_maybe' => intval($row['opened_maybe'] ?? 0),
            'clicked'      => intval($row['clicked'] ?? 0),
        ];

        // Document counters live on their own table.
        $doc_where  = 'd.`user_id` = ? AND d.`message_id` IS NOT NULL';
        $doc_params = [$user_id];

        if ($since) {
            $doc_where   .= ' AND m.`sent_at` >= ?';
            $doc_params[] = $since;
        }
        if ($until) {
            $doc_where   .= ' AND m.`sent_at` <= ?';
            $doc_params[] = $until;
        }

        $sql = $this->db->query(
            'SELECT COUNT(*) AS docs,'
            . ' SUM(CASE WHEN d.`view_count` > 0 THEN 1 ELSE 0 END) AS viewed,'
            . ' SUM(CASE WHEN d.`download_count` > 0 THEN 1 ELSE 0 END) AS downloaded'
            . ' FROM ' . $this->t('horus_documents') . ' d'
            . ' INNER JOIN ' . $this->t('horus_messages') . ' m ON m.`message_id` = d.`message_id`'
            . " WHERE $doc_where",
            ...$doc_params
        );

        $row = $this->db->fetch_assoc($sql) ?: [];

        $stats['docs']            = intval($row['docs'] ?? 0);
        $stats['docs_viewed']     = intval($row['viewed'] ?? 0);
        $stats['docs_downloaded'] = intval($row['downloaded'] ?? 0);

        // Rates are always expressed over TRACKED messages: untracked sends could never
        // have reported an open, so including them would understate the real rate.
        $base = max(1, $stats['tracked']);

        $stats['open_rate']       = round($stats['opened_real'] * 100 / $base, 1);
        $stats['open_rate_maybe'] = round($stats['opened_maybe'] * 100 / $base, 1);
        $stats['click_rate']      = round($stats['clicked'] * 100 / $base, 1);

        return $stats;
    }

    /**
     * Send/open/click counts over an arbitrary date range, for the activity chart.
     *
     * Buckets adapt to the span so a long custom range stays readable instead of
     * turning into hundreds of unlabelled points: daily up to a quarter, weekly up to
     * roughly two years, monthly beyond that.
     *
     * @param string $from Inclusive start, 'Y-m-d'
     * @param string $to   Inclusive end, 'Y-m-d'
     *
     * @return array Buckets with date (bucket start), label, sent, opened, clicked
     */
    public function series($user_id, $from, $to)
    {
        $start = strtotime($from . ' 00:00:00 UTC');
        $end   = strtotime($to . ' 23:59:59 UTC');

        if (!$start || !$end || $end < $start) {
            return [];
        }

        $days = (int) floor(($end - $start) / 86400) + 1;
        $unit = $days <= 92 ? 'day' : ($days <= 730 ? 'week' : 'month');

        // Pre-create every bucket so gaps render as zero rather than disappearing.
        $buckets = [];

        for ($ts = $start; $ts <= $end; $ts += 86400) {
            $key = self::bucket_key($ts, $unit);

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'date'    => gmdate('Y-m-d', $ts),
                    'label'   => self::bucket_label($ts, $unit),
                    'sent'    => 0,
                    'opened'  => 0,
                    'clicked' => 0,
                ];
            }
        }

        $sql = $this->db->query(
            'SELECT `sent_at`, `real_open_count`, `click_count`, `human_confirmed` FROM ' . $this->t('horus_messages')
            . ' WHERE `user_id` = ? AND `sent_at` >= ? AND `sent_at` <= ?',
            $user_id, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end)
        );

        while ($row = $this->db->fetch_assoc($sql)) {
            $key = self::bucket_key(self::ts($row['sent_at']), $unit);

            if (!isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['sent']++;

            if ($row['real_open_count'] > 0 || !empty($row['human_confirmed'])) {
                $buckets[$key]['opened']++;
            }
            if ($row['click_count'] > 0) {
                $buckets[$key]['clicked']++;
            }
        }

        return array_values($buckets);
    }

    private static function bucket_key($ts, $unit)
    {
        switch ($unit) {
            case 'month':
                return gmdate('Y-m', $ts);
            case 'week':
                // ISO week, so a bucket never straddles a year boundary ambiguously.
                return gmdate('o-\WW', $ts);
            default:
                return gmdate('Y-m-d', $ts);
        }
    }

    private static function bucket_label($ts, $unit)
    {
        switch ($unit) {
            case 'month':
                return gmdate('M Y', $ts);
            case 'week':
                return gmdate('d/m', $ts);
            default:
                return gmdate('d/m', $ts);
        }
    }

    /**
     * Message list for the dashboard, with optional recipient search and filters.
     *
     * @param array $opts search, filter, limit, offset
     */
    public function list_messages($user_id, array $opts = [])
    {
        $where  = ['`user_id` = ?'];
        $params = [$user_id];

        if (!empty($opts['search'])) {
            $where[]  = '(' . $this->db->ilike('to_addr', '%' . $opts['search'] . '%')
                . ' OR ' . $this->db->ilike('recipients', '%' . $opts['search'] . '%')
                . ' OR ' . $this->db->ilike('subject', '%' . $opts['search'] . '%') . ')';
        }

        switch ($opts['filter'] ?? '') {
            case 'tracked':
                $where[] = '`tracked` = 1';
                break;
            case 'untracked':
                $where[] = '`tracked` = 0';
                break;
            case 'opened':
                $where[] = '(`real_open_count` > 0 OR `human_confirmed` = 1)';
                break;
            case 'maybe':
                $where[] = '`real_open_count` = 0 AND `human_confirmed` = 0 AND `open_count` > 0';
                break;
            case 'unopened':
                $where[] = '`tracked` = 1 AND `open_count` = 0';
                break;
            case 'clicked':
                $where[] = '`click_count` > 0';
                break;
            case 'notclicked':
                $where[] = '`tracked` = 1 AND `click_count` = 0';
                break;
        }

        $limit  = min(500, max(1, intval($opts['limit'] ?? 100)));
        $offset = max(0, intval($opts['offset'] ?? 0));

        $sql = $this->db->limitquery(
            'SELECT * FROM ' . $this->t('horus_messages')
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY `sent_at` DESC, `message_id` DESC',
            $offset, $limit, ...$params
        );

        $rows = $this->fetch_all($sql);

        // Attach document counters in one extra query rather than one per message.
        if ($rows) {
            $ids = array_map(function ($r) { return intval($r['message_id']); }, $rows);
            $doc_sql = $this->db->query(
                'SELECT `message_id`, COUNT(*) AS n,'
                . ' SUM(CASE WHEN `view_count` > 0 THEN 1 ELSE 0 END) AS viewed,'
                . ' SUM(CASE WHEN `download_count` > 0 THEN 1 ELSE 0 END) AS downloaded'
                . ' FROM ' . $this->t('horus_documents')
                . ' WHERE `message_id` IN (' . implode(',', $ids) . ') GROUP BY `message_id`'
            );

            $docs = [];
            while ($row = $this->db->fetch_assoc($doc_sql)) {
                $docs[$row['message_id']] = $row;
            }

            foreach ($rows as &$row) {
                $d = $docs[$row['message_id']] ?? null;
                $row['doc_count']       = intval($d['n'] ?? 0);
                $row['doc_viewed']      = intval($d['viewed'] ?? 0);
                $row['doc_downloaded']  = intval($d['downloaded'] ?? 0);
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * Per-recipient roll-up, for the "who engages with me" view.
     */
    public function recipient_summary($user_id, $search = null, $limit = 50)
    {
        $where  = ['`user_id` = ?', "`to_addr` <> ''"];
        $params = [$user_id];

        if ($search) {
            $where[] = $this->db->ilike('to_addr', '%' . $search . '%');
        }

        $sql = $this->db->limitquery(
            'SELECT `to_addr`, COUNT(*) AS sent,'
            . ' SUM(CASE WHEN `real_open_count` > 0 OR `human_confirmed` = 1 THEN 1 ELSE 0 END) AS opened,'
            . ' SUM(CASE WHEN `real_open_count` = 0 AND `human_confirmed` = 0 AND `open_count` > 0 THEN 1 ELSE 0 END) AS maybe,'
            . ' SUM(CASE WHEN `click_count` > 0 THEN 1 ELSE 0 END) AS clicked,'
            . ' MAX(`sent_at`) AS last_sent'
            . ' FROM ' . $this->t('horus_messages')
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY `to_addr` ORDER BY MAX(`sent_at`) DESC',
            0, $limit, ...$params
        );

        return $this->fetch_all($sql);
    }

    private function fetch_all($sql)
    {
        $out = [];

        while ($row = $this->db->fetch_assoc($sql)) {
            $out[] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------- netranges

    public function replace_netranges($source, array $cidrs)
    {
        $this->db->query('DELETE FROM ' . $this->t('horus_netranges') . ' WHERE `source` = ?', $source);

        $now = self::now();

        foreach ($cidrs as $cidr) {
            $this->db->query(
                'INSERT INTO ' . $this->t('horus_netranges') . ' (`source`, `cidr`, `updated_at`) VALUES (?, ?, ?)',
                $source, $cidr, $now
            );
        }
    }

    public function get_netranges($source = null)
    {
        if ($source) {
            $sql = $this->db->query('SELECT * FROM ' . $this->t('horus_netranges') . ' WHERE `source` = ?', $source);
        }
        else {
            $sql = $this->db->query('SELECT * FROM ' . $this->t('horus_netranges'));
        }

        return $this->fetch_all($sql);
    }

    public function netranges_age($source)
    {
        $sql = $this->db->query(
            'SELECT MAX(`updated_at`) AS updated FROM ' . $this->t('horus_netranges') . ' WHERE `source` = ?',
            $source
        );

        $row = $this->db->fetch_assoc($sql);

        return !empty($row['updated']) ? self::ts($row['updated']) : null;
    }
}
