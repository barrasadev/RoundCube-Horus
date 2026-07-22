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

        // In per-recipient mode several records share a Message-ID. The row then
        // shows the furthest any recipient got on the open axis, plus a click or
        // download badge if any of them did that. The per-person breakdown lives in
        // the report.
        $rank = [
            self::STATE_UNTRACKED => 0,
            self::STATE_NOTOPENED => 1,
            self::STATE_MAYBE     => 2,
            self::STATE_OPENED    => 3,
        ];

        $open  = [];
        $extra = [];

        foreach ($this->store->states_by_msgid($this->rc->user->ID, $msgids) as $row) {
            $id = $row['msgid'];

            foreach (self::states_of($row) as $i => $state) {
                if ($i === 0) {
                    if (!isset($open[$id]) || ($rank[$state] ?? 0) > ($rank[$open[$id]] ?? 0)) {
                        $open[$id] = $state;
                    }
                }
                else {
                    $extra[$id][$state] = true;
                }
            }
        }

        $out = [];

        foreach ($open as $id => $state) {
            // Joined rather than an array: extra_flags reaches the client as a plain
            // value, and horus.js splits it back apart.
            $out[$id] = implode(',', array_merge([$state], array_keys($extra[$id] ?? [])));
        }

        return $out;
    }

    /**
     * Whether the message was opened - and nothing else.
     *
     * Deliberately independent of clicks and downloads. Those are things the
     * recipient did *after* opening; letting them override the open state meant a
     * message that was read and then clicked stopped reporting that it was read,
     * which is the one fact you always want to see.
     */
    public static function state_of(array $row)
    {
        if (empty($row['tracked'])) {
            return self::STATE_UNTRACKED;
        }

        // human_confirmed covers the case where every pixel hit looked automated but
        // a person still interacted - that is an open, not a "possibly".
        if ($row['real_open_count'] > 0 || !empty($row['human_confirmed'])) {
            return self::STATE_OPENED;
        }

        if ($row['open_count'] > 0) {
            return self::STATE_MAYBE;
        }

        return self::STATE_NOTOPENED;
    }

    /**
     * Everything true about a message at once: its open state, plus whether it was
     * clicked and whether a file was downloaded.
     *
     * These are separate facts and are shown as separate badges. A message that was
     * opened, clicked and downloaded reports all three.
     *
     * @return array Ordered states, open state first
     */
    public static function states_of(array $row)
    {
        $open = self::state_of($row);

        if ($open === self::STATE_UNTRACKED) {
            return [self::STATE_UNTRACKED];
        }

        $out = [$open];

        if (!empty($row['click_count'])) {
            $out[] = self::STATE_CLICKED;
        }

        if (!empty($row['doc_downloaded'])) {
            $out[] = self::STATE_DOWNLOADED;
        }

        return $out;
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
