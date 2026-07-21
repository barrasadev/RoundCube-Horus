<?php

/**
 * Horus :: tracking state in the Sent message list.
 *
 * Adds one status flag per row so the list shows, at a glance, whether each message
 * was opened, clicked, or had a file downloaded - without opening anything.
 *
 * The flag rides along on `list_flags['extra_flags']`, which Roundcube passes through
 * to `rcmail.env.messages[uid].flags`; horus.js turns it into a badge on the row.
 *
 * @license GNU GPLv3+
 */
class horus_list
{
    /** Ranked weakest to strongest: the strongest signal is what the row shows. */
    const STATE_UNTRACKED  = 'untracked';
    const STATE_NOTOPENED  = 'notopened';
    const STATE_MAYBE      = 'maybe';
    const STATE_OPENED     = 'opened';
    const STATE_CLICKED    = 'clicked';
    const STATE_DOWNLOADED = 'downloaded';

    /** @var horus */
    private $plugin;

    /** @var horus_store */
    private $store;

    /** @var rcmail */
    private $rc;

    public function __construct($plugin, horus_store $store)
    {
        $this->plugin = $plugin;
        $this->store  = $store;
        $this->rc     = rcmail::get_instance();
    }

    /**
     * `messages_list` hook: tag every row that belongs to a tracked message.
     */
    public function messages_list($args)
    {
        $messages = $args['messages'] ?? [];

        if (!$messages || !$this->is_outgoing_folder()) {
            return $args;
        }

        // One lookup for the whole page rather than one per row.
        $by_msgid = $this->states_for($messages);

        if ($by_msgid === []) {
            return $args;
        }

        foreach ($messages as $header) {
            $msgid = $header->messageID ?? null;

            if ($msgid !== null && isset($by_msgid[$msgid])) {
                $header->list_flags['extra_flags']['horus'] = $by_msgid[$msgid];
            }
        }

        $this->rc->output->set_env('horus_list_labels', $this->labels());

        return $args;
    }

    /**
     * Resolve the display state for every message on this page, keyed by Message-ID.
     */
    private function states_for(array $messages)
    {
        $msgids = [];

        foreach ($messages as $header) {
            if (!empty($header->messageID)) {
                $msgids[] = $header->messageID;
            }
        }

        if (!$msgids) {
            return [];
        }

        // One row per record. In per-recipient mode several records share a
        // Message-ID, so the row shows the strongest state any recipient reached -
        // the detail lives in the report.
        $rank = [
            self::STATE_UNTRACKED  => 0,
            self::STATE_NOTOPENED  => 1,
            self::STATE_MAYBE      => 2,
            self::STATE_OPENED     => 3,
            self::STATE_CLICKED    => 4,
            self::STATE_DOWNLOADED => 5,
        ];

        $out = [];

        foreach ($this->store->states_by_msgid($this->rc->user->ID, $msgids) as $row) {
            $state = self::state_of($row);
            $seen  = $out[$row['msgid']] ?? null;

            if ($seen === null || ($rank[$state] ?? 0) > ($rank[$seen] ?? 0)) {
                $out[$row['msgid']] = $state;
            }
        }

        return $out;
    }

    /**
     * Reduce a message's counters to the single strongest thing that happened to it.
     */
    public static function state_of(array $row)
    {
        if (empty($row['tracked'])) {
            return self::STATE_UNTRACKED;
        }

        if (!empty($row['doc_downloaded'])) {
            return self::STATE_DOWNLOADED;
        }

        if ($row['click_count'] > 0) {
            return self::STATE_CLICKED;
        }

        // A confirmed click or download already returned above; human_confirmed here
        // covers the case where the only pixel hits were bots but a person still
        // interacted, which makes this an open rather than a "possibly".
        if ($row['real_open_count'] > 0 || !empty($row['human_confirmed'])) {
            return self::STATE_OPENED;
        }

        if ($row['open_count'] > 0) {
            return self::STATE_MAYBE;
        }

        return self::STATE_NOTOPENED;
    }

    /**
     * Row labels, sent to the client once per list rather than per row.
     */
    private function labels()
    {
        return [
            self::STATE_UNTRACKED  => $this->plugin->gettext('nottracked'),
            self::STATE_NOTOPENED  => $this->plugin->gettext('notopenedyet'),
            self::STATE_MAYBE      => $this->plugin->gettext('maybeopened'),
            self::STATE_OPENED     => $this->plugin->gettext('opened'),
            self::STATE_CLICKED    => $this->plugin->gettext('clicked'),
            self::STATE_DOWNLOADED => $this->plugin->gettext('downloaded'),
        ];
    }

    private function is_outgoing_folder()
    {
        $sent = $this->rc->config->get('sent_mbox');

        return $sent && $this->rc->storage->get_folder() === $sent;
    }
}
