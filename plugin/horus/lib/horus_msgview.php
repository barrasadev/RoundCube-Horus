<?php

/**
 * Horus :: tracking status on a sent message.
 *
 * Renders into the `message_objects` container, which Elastic places directly under
 * the message headers - the natural home for "what happened to this email".
 *
 * @license GNU GPLv3+
 */
class horus_msgview
{
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

    public function message_objects($args)
    {
        $message = $args['message'] ?? null;

        if (!$message || empty($message->headers)) {
            return $args;
        }

        // Only meaningful for messages this user sent.
        if (!$this->is_outgoing_folder($message->folder)) {
            return $args;
        }

        $msgid   = $message->headers->messageID ?? null;
        $records = $msgid ? $this->store->get_messages_by_msgid($this->rc->user->ID, $msgid) : [];

        if (!$records) {
            $args['content'][] = $this->render_untracked();
        }
        // Per-recipient mode files one record per recipient under the same
        // Message-ID, so the report has to roll them up rather than show the first.
        else if (count($records) > 1) {
            $args['content'][] = $this->render_group($records);
        }
        else {
            $args['content'][] = $this->render($records[0]);
        }

        return $args;
    }

    /**
     * True for the user's Sent folder (and Drafts is deliberately excluded: a draft
     * has never been sent, so there is nothing to report).
     */
    private function is_outgoing_folder($folder)
    {
        $sent = $this->rc->config->get('sent_mbox');

        return $sent && $folder === $sent;
    }

    // ---------------------------------------------------------------- rendering

    private function render_untracked()
    {
        return $this->collapsed('off', self::STATE_UNTRACKED, $this->t('nottracked'), '');
    }

    /** Row states, mirroring horus_list so the list and the message agree. */
    const STATE_UNTRACKED = 'untracked';

    private function render($record)
    {
        if (empty($record['tracked'])) {
            return $this->render_untracked();
        }

        require_once __DIR__ . '/horus_list.php';

        $docs = $this->store->documents_for_message($record['message_id']);

        // The eye reports whether the message was OPENED, and nothing else. A click
        // or a download does not change its colour: they happen after an open, so
        // green stays green.
        $state = horus_list::state_of($record);

        $summary = $this->summary_line($record, $docs);
        $panel   = $this->panel($record, $docs);

        return $this->collapsed(self::open_icon($state), $state, $summary, $panel);
    }

    /**
     * A message that was sent as one copy per recipient.
     *
     * Those copies share a Message-ID, so several records resolve from one message in
     * Sent. The headline is how many recipients opened it; the panel breaks that down
     * by address and then gives each recipient's own timeline.
     */
    private function render_group(array $records)
    {
        require_once __DIR__ . '/horus_list.php';

        // Only the open axis is ranked: the headline is how many recipients opened
        // it, and the eye reports opens. Clicks and downloads are separate badges.
        $rank = [
            horus_list::STATE_UNTRACKED => 0,
            horus_list::STATE_NOTOPENED => 1,
            horus_list::STATE_MAYBE     => 2,
            horus_list::STATE_OPENED    => 3,
        ];

        $total  = count($records);
        $opened = 0;
        $best   = horus_list::STATE_NOTOPENED;
        $rows   = '';
        $detail = '';

        foreach ($records as $record) {
            $docs  = $this->store->documents_for_message($record['message_id']);
            $state = horus_list::state_of($record);

            if (($rank[$state] ?? 0) >= $rank[horus_list::STATE_OPENED]) {
                $opened++;
            }

            if (($rank[$state] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $state;
            }

            $rows .= html::tag('tr', null,
                html::tag('td', 'horus-addr', rcube::Q($record['to_addr']))
                . html::tag('td', null, $this->state_tags(
                    $record + ['doc_downloaded' => $this->downloaded_count($docs)]))
                . html::tag('td', 'horus-when', rcube::Q($this->when(
                    $record['first_real_open_at'] ?: $record['first_open_at'])))
            );

            // Each recipient keeps their own events, so "who did what" stays answerable.
            if ($events = $this->details($record, $docs)) {
                $detail .= html::div('horus-panel-section',
                    html::div('horus-drawer-title', rcube::Q($record['to_addr'])) . $events);
            }
        }

        $panel = html::div('horus-panel-section',
            html::div('horus-drawer-title', rcube::Q($this->t('perrecipient')))
            . html::tag('table', 'horus-table', html::tag('tbody', null, $rows))
        ) . $detail;

        $summary = rcube::Q(sprintf('%d/%d %s', $opened, $total, $this->t('opened')));

        return $this->collapsed(self::open_icon($best), $best, $summary,
            html::div('horus-panel', $panel));
    }

    /** Icon for an open state. */
    private static function open_icon($state)
    {
        switch ($state) {
            case horus_list::STATE_OPENED: return 'opened';
            case horus_list::STATE_MAYBE:  return 'maybe';
        }

        return 'sent';
    }

    /**
     * One recipient's states as coloured tags - all of them, not just the strongest.
     */
    private function state_tags(array $record)
    {
        $out = '';

        foreach (horus_list::states_of($record) as $state) {
            $out .= $this->state_tag($state) . ' ';
        }

        return trim($out);
    }

    /**
     * One state as a coloured tag.
     */
    private function state_tag($state)
    {
        require_once __DIR__ . '/horus_list.php';

        $map = [
            horus_list::STATE_UNTRACKED  => ['off', 'off', 'nottracked'],
            horus_list::STATE_NOTOPENED  => ['off', 'sent', 'notopenedyet'],
            horus_list::STATE_MAYBE      => ['unknown', 'maybe', 'maybeopened'],
            horus_list::STATE_OPENED     => ['ok', 'opened', 'opened'],
            horus_list::STATE_CLICKED    => ['link', 'clicked', 'clicked'],
            horus_list::STATE_DOWNLOADED => ['ok', 'downloaded', 'downloaded'],
        ];

        list($cls, $icon, $label) = $map[$state] ?? $map[horus_list::STATE_NOTOPENED];

        return html::span("horus-tag horus-tag-$cls",
            horus_icons::get($icon) . ' ' . rcube::Q($this->t($label)));
    }

    /**
     * Emit the panel, hidden.
     *
     * Nothing is drawn in the message body area. horus.js adds a "Horus" entry to the
     * skin's `.header-links` row - beside Details / Headers / Plain text, where the
     * other per-message tools live - and opens this markup in a dialog on click.
     * Rendering it here rather than fetching it on demand keeps the dialog instant.
     */
    private function collapsed($icon, $state, $summary, $panel)
    {
        $this->plugin->include_script('horus.js');

        $this->rc->output->set_env('horus_state', $state);
        $this->rc->output->set_env('horus_summary', strip_tags($summary));
        $this->rc->output->add_label('horus.horusdetails', 'horus.markbot', 'horus.markbotfailed');

        return html::div(['id' => 'horus-msgdata', 'class' => 'horus-msgdata', 'style' => 'display:none'],
            html::div('horus-dialog-head',
                // Always the eye, and always neutral: the state colour lives on the
                // header-links entry outside, not in here.
                horus_icons::get('horus', 'horus-dialog-icon', 20)
                . html::span('horus-dialog-state horus-state-' . $state, $summary))
            . $panel
        );
    }

    private function downloaded_count(array $docs)
    {
        $n = 0;

        foreach ($docs as $doc) {
            if ($doc['download_count'] > 0) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * One short line of state for the collapsed button.
     */
    private function summary_line($record, array $docs)
    {
        $parts = [];
        $real  = intval($record['real_open_count']);

        // Counts in parentheses, no timestamps: every date is already in the
        // timeline directly below, and repeating them made the line unreadable.
        if ($real > 0 || !empty($record['human_confirmed'])) {
            $parts[] = rcube::Q($this->t('opened')) . self::times(max($real, 1));
        }
        else if ($record['open_count'] > 0) {
            $parts[] = rcube::Q($this->t('maybeopened')) . self::times($record['open_count']);
        }
        else {
            $parts[] = rcube::Q($this->t('notopenedyet'));
        }

        if ($record['click_count'] > 0) {
            $parts[] = rcube::Q($this->t('clicked')) . self::times($record['click_count']);
        }

        if ($docs) {
            $parts[] = rcube::Q(sprintf('%d/%d %s',
                $this->downloaded_count($docs), count($docs), $this->t('files')));
        }

        return implode(' &middot; ', $parts);
    }

    /**
     * Everything known about this message: the badges, the attachments, and the full
     * event timeline with classification, addresses and client details.
     */
    private function panel($record, array $docs)
    {
        $out = html::div('horus-badges', implode('', $this->badges($record, $docs)));

        if (!empty($record['self_count'])) {
            $out .= html::div('horus-selfnote',
                horus_icons::get('viewed') . ' '
                . rcube::Q(sprintf('%s: %d', $this->t('selfviews'), $record['self_count'])));
        }

        if ($details = $this->details($record, $docs)) {
            $out .= html::div('horus-panel-section',
                html::div('horus-drawer-title', rcube::Q($this->t('timeline'))) . $details);
        }

        return $out;
    }

    /**
     * The headline state. Confirmed and "possibly" opens are always shown as separate
     * badges - never summed - so the UI cannot overstate what is actually known.
     */
    private function badges($record, array $docs)
    {
        $badges = [];

        $real   = intval($record['real_open_count']);
        $all    = intval($record['open_count']);
        $clicks = intval($record['click_count']);

        // A confirmed human interaction settles the question even when every pixel
        // fetch looked automated: someone clicked, so the message was opened.
        if ($real > 0 || !empty($record['human_confirmed'])) {
            $badges[] = $this->badge('ok', 'opened', $this->t('opened') . self::times(max($real, 1)));
        }
        else if ($all > 0) {
            $badges[] = $this->badge('maybe', 'maybe', $this->t('maybeopened') . self::times($all));
        }
        else {
            $badges[] = $this->badge('muted', 'sent', $this->t('noopens'));
        }

        if ($clicks > 0) {
            $badges[] = $this->badge('link', 'clicked', $this->t('clicked') . self::times($clicks));
        }

        foreach ($docs as $doc) {
            $state = [];

            if ($doc['download_count'] > 0) {
                $state[] = $this->t('downloaded');
            }
            else if ($doc['view_count'] > 0) {
                $state[] = $this->t('viewed');
            }
            else {
                // A file is downloaded or it is not; "opened" is the message's word.
                $state[] = $this->t('notdownloaded');
            }

            $badges[] = $this->badge(
                $doc['download_count'] > 0 ? 'ok' : ($doc['view_count'] > 0 ? 'maybe' : 'muted'),
                $doc['download_count'] > 0 ? 'downloaded' : ($doc['view_count'] > 0 ? 'viewed' : 'attachment'),
                rcube::Q($doc['filename']) . ' &middot; ' . implode(' ', $state)
            );
        }

        return $badges;
    }

    private function badge($kind, $icon, $html)
    {
        return html::span("horus-badge horus-badge-$kind", horus_icons::get($icon) . ' ' . $html);
    }

    /**
     * Full event timeline, including the classification of each open, so the user can
     * audit why Horus called something a bot.
     */
    private function details($record, array $docs)
    {
        $events = $this->store->events($record['message_id']);

        if (!$events) {
            return '';
        }

        require_once __DIR__ . '/horus_dashboard.php';

        $geo  = horus_dashboard::geo_for($events, $this->store);
        $rows = '';

        foreach ($events as $event) {
            $rows .= html::tag('tr', null,
                html::tag('td', 'horus-t-when', rcube::Q($this->when($event['created_at'])))
                . html::tag('td', 'horus-t-what', $this->event_label($event))
                . html::tag('td', 'horus-t-note', $this->event_note($event, $geo))
            );
        }

        return html::tag('table', 'horus-timeline', html::tag('tbody', null, $rows))
            . horus_dashboard::render_client_table($events, $this->rc, $geo)
            . horus_dashboard::render_action_tables($events, $this->rc, $geo, $docs);
    }

    private function event_label($event)
    {
        switch ($event['type']) {
            case horus_store::EVENT_OPEN:
                $status = $event['status'];
                $cls    = $status === horus_store::STATUS_CONFIRMED ? 'ok'
                    : ($status === horus_store::STATUS_BOT ? 'bot'
                    : ($status === horus_store::STATUS_SELF ? 'self' : 'unknown'));

                return html::span("horus-tag horus-tag-$cls", rcube::Q($this->t('event' . $status)));

            case horus_store::EVENT_CLICK:
                return html::span('horus-tag horus-tag-link', rcube::Q($this->t('eventclick')));

            case horus_store::EVENT_DOC_VIEW:
                return html::span('horus-tag horus-tag-maybe', rcube::Q($this->t('eventdocview')));

            case horus_store::EVENT_DOC_DOWNLOAD:
                return html::span('horus-tag horus-tag-ok', rcube::Q($this->t('eventdocdownload')));
        }

        return rcube::Q($event['type']);
    }

    private function event_note($event, array $geo = [])
    {
        require_once __DIR__ . '/horus_dashboard.php';

        return horus_dashboard::render_event_note($event, $this->rc, $geo);
    }

    /** "(3)", or nothing at all when it happened once. */
    private static function times($n)
    {
        return $n > 1 ? ' <span class="horus-times">(' . intval($n) . ')</span>' : '';
    }

    private function count_label($n)
    {
        return rcube::Q($n . ' ' . $this->plugin->gettext($n == 1 ? 'timeonce' : 'timesmany'));
    }

    private function when($value)
    {
        $ts = horus_store::ts($value);

        return $ts ? rcube::Q($this->rc->format_date($ts)) : '';
    }

    private function t($key)
    {
        return $this->plugin->gettext($key);
    }
}
