<?php

/**
 * Horus :: the Scheduled view and its actions.
 *
 * The sidebar's "Scheduled" entry opens this. It reads like the inbox — a list of the
 * messages waiting to go out on the left, the message that will actually be sent
 * previewed on the right — but it is not an IMAP folder: the rows live in
 * horus_scheduled, so a mail client can never move or delete one. The toolbar carries
 * Edit / Reschedule / Delete instead of Reply / Forward. Delivery itself is the cron's
 * job (bin/horus_cron.php); this is only the cockpit.
 *
 * @license GNU GPLv3+
 */
class horus_scheduler
{
    /** @var horus */
    private $plugin;
    /** @var horus_store */
    private $store;
    /** @var horus_storage */
    private $storage;
    /** @var rcmail */
    private $rc;

    public function __construct($plugin, horus_store $store, horus_storage $storage)
    {
        $this->plugin  = $plugin;
        $this->store   = $store;
        $this->storage = $storage;
        $this->rc      = rcmail::get_instance();
    }

    // -------------------------------------------------------------------- view

    public function run()
    {
        $this->plugin->include_script('horus.js');
        $this->rc->output->set_pagetitle($this->plugin->gettext('scheduledfolder'));
        $this->rc->output->add_handlers(['plugin.horus_scheduled_list' => [$this, 'render_list']]);
        $this->rc->output->send('horus.scheduled');
    }

    public function render_list($attrib)
    {
        $rows = $this->store->scheduled_for_user($this->rc->user->ID);

        if (!$rows) {
            return html::div(['id' => 'horus-scheduled', 'class' => 'horus-sched-empty'],
                html::div('horus-empty', rcube::Q($this->plugin->gettext('scheduledempty'))));
        }

        $out = '';

        foreach ($rows as $row) {
            $out .= $this->row_html($row);
        }

        return html::div(['id' => 'horus-scheduled', 'class' => 'horus-sched-list'], $out);
    }

    private function row_html($row)
    {
        $id      = intval($row['scheduled_id']);
        $pending = $row['status'] === 'pending';
        $ts      = horus_store::ts($row['send_at']);

        return html::div([
                'class' => 'horus-sched-item' . ($pending ? '' : ' horus-sched-done'),
                'data-id' => $id,
                'data-status' => $row['status'],
            ],
            html::span('horus-sched-to', rcube::Q($row['recipients'] ?: '—'))
            . html::span(['class' => 'horus-sched-when horus-ts', 'data-ts' => $ts],
                rcube::Q($this->rc->format_date($ts)))
            . html::span('horus-sched-subject', rcube::Q($row['subject'] ?: '—'))
            . html::span('horus-sched-state', $this->status_pill($row))
        );
    }

    private function status_pill($row)
    {
        switch ($row['status']) {
            case 'sent':
                return html::span('horus-pill horus-pill-opened', rcube::Q($this->plugin->gettext('scheduledsent')));
            case 'failed':
                return html::span('horus-pill horus-pill-notopened', rcube::Q($this->plugin->gettext('scheduledfailed')));
            default:
                return html::span('horus-pill horus-pill-clicked', rcube::Q($this->plugin->gettext('scheduledpending')));
        }
    }

    // ----------------------------------------------------------------- actions

    /**
     * All the endpoints the view talks to. Preview is a read; the rest mutate, so all
     * of them are CSRF-guarded and scoped to a row this user owns.
     */
    public function handle_action()
    {
        if (!$this->rc->check_request()) {
            return $this->fail('invalidrequest');
        }

        $id  = intval(rcube_utils::get_input_value('_sched', rcube_utils::INPUT_GPC));
        $row = $id ? $this->store->get_scheduled($id) : null;

        if (!$row || $row['user_id'] != $this->rc->user->ID) {
            return $this->fail('notfound');
        }

        switch ($this->rc->action) {
            case 'plugin.horus.schedpreview': return $this->do_preview($row);
            case 'plugin.horus.schedcancel':  return $this->do_cancel($row);
            case 'plugin.horus.schedmove':    return $this->do_reschedule($row);
            case 'plugin.horus.schededit':    return $this->do_edit($row);
            case 'plugin.horus.scheddelete':  return $this->do_delete($row);
            default:                          return $this->fail('invalidrequest');
        }
    }

    private function fail($label)
    {
        $this->rc->output->command('display_message', $this->plugin->gettext($label), 'error');
        $this->rc->output->send();
    }

    /**
     * Render the frozen message for the right-hand pane: its address lines and send
     * time, then the body — HTML washed, or plain text if that is all there is.
     */
    private function do_preview($row)
    {
        $raw  = $this->storage->read($row['storage_key']);
        $body = $raw !== null ? $this->extract_body($raw) : ['html' => '', 'text' => ''];

        $ts = horus_store::ts($row['send_at']);

        $meta = html::div('horus-preview-head',
            $this->meta_line('colrecipient', $row['recipients'])
            . $this->meta_line('colsubject', $row['subject'])
            . html::div('horus-preview-metaline',
                html::span('horus-preview-metalabel', rcube::Q($this->plugin->gettext('scheduleat')) . ': ')
                . html::span(['class' => 'horus-preview-metaval horus-ts', 'data-ts' => $ts],
                    rcube::Q($this->rc->format_date($ts))))
        );

        if (!empty($body['html'])) {
            $content = html::div('horus-preview-body', $this->wash($body['html']));
        }
        else {
            $content = html::tag('pre', 'horus-preview-body horus-preview-text',
                rcube::Q((string) $body['text']));
        }

        $this->rc->output->command('plugin.horus_sched_preview', [
            'id'      => intval($row['scheduled_id']),
            'status'  => $row['status'],
            'html'    => $meta . $content,
        ]);
        $this->rc->output->send();
    }

    private function meta_line($label, $value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        return html::div('horus-preview-metaline',
            html::span('horus-preview-metalabel', rcube::Q($this->plugin->gettext($label)) . ': ')
            . html::span('horus-preview-metaval', rcube::Q($value)));
    }

    private function do_cancel($row)
    {
        if ($row['status'] === 'pending') {
            $this->store->cancel_scheduled($row['scheduled_id']);
            $this->storage->delete($row['storage_key']);
        }

        $this->rc->output->command('display_message', $this->plugin->gettext('schedulecanceled'), 'confirmation');
        $this->rc->output->command('plugin.horus_sched_reload');
        $this->rc->output->send();
    }

    private function do_reschedule($row)
    {
        // Same rule as scheduling: pinned zone, else the user's own Roundcube zone.
        $tz = horus_settings::get()['horus_schedule_tz'] ?: (string) $this->rc->config->get('timezone');
        $send_at = horus::parse_schedule_time(rcube_utils::get_input_value('_when', rcube_utils::INPUT_POST), $tz);

        if ($row['status'] !== 'pending' || $send_at === null) {
            return $this->fail('schedulepast');
        }

        $this->store->update_scheduled_time($row['scheduled_id'], $send_at);

        $this->rc->output->command('display_message', $this->plugin->gettext('schedulemoved'), 'confirmation');
        $this->rc->output->command('plugin.horus_sched_reload');
        $this->rc->output->send();
    }

    /**
     * Delete: take the message out of the queue and drop it in Drafts, so nothing is
     * lost — the user can pick it up there like any other draft.
     */
    private function do_delete($row)
    {
        if ($row['status'] !== 'pending') {
            return $this->fail('notfound');
        }

        $raw = $this->storage->read($row['storage_key']);

        if ($raw !== null) {
            $raw    = $this->strip_pixel($raw);
            $drafts = $this->rc->config->get('drafts_mbox') ?: 'Drafts';
            $this->rc->get_storage()->save_message($drafts, $raw, '', false, ['SEEN', 'DRAFT']);
        }

        $this->store->cancel_scheduled($row['scheduled_id']);
        $this->storage->delete($row['storage_key']);

        $this->rc->output->command('display_message', $this->plugin->gettext('scheduledtodrafts'), 'confirmation');
        $this->rc->output->command('plugin.horus_sched_reload');
        $this->rc->output->send();
    }

    /**
     * Edit: reopen the frozen message as a draft and go to compose, WITHOUT touching
     * the schedule. The original stays queued, tagged with _horus_editing so that if
     * the user reschedules from the edit, the send path replaces it; if they just close
     * the compose, the original still goes out unchanged.
     */
    private function do_edit($row)
    {
        if ($row['status'] !== 'pending') {
            return $this->fail('notfound');
        }

        $raw = $this->storage->read($row['storage_key']);

        if ($raw === null) {
            return $this->fail('notfound');
        }

        $raw    = $this->strip_pixel($raw);
        $drafts = $this->rc->config->get('drafts_mbox') ?: 'Drafts';
        $uid    = $this->rc->get_storage()->save_message($drafts, $raw, '', false, ['SEEN', 'DRAFT']);

        if ($uid === false) {
            return $this->fail('uploaderror');
        }

        $url = $this->rc->url([
            '_task'          => 'mail',
            '_action'        => 'compose',
            '_draft_uid'     => $uid,
            '_mbox'          => $drafts,
            '_horus_editing' => intval($row['scheduled_id']),
        ]);

        $this->rc->output->command('plugin.horus_sched_edit', ['url' => $url]);
        $this->rc->output->send();
    }

    // ----------------------------------------------------------------- helpers

    /** Remove the tracking pixel from a stored message before it is reopened. */
    private function strip_pixel($raw)
    {
        return preg_replace('#<img[^>]*' . preg_quote(horus_injector::param(), '#') . '=px[^>]*>#i', '', $raw);
    }

    /** Pull the HTML and plain-text bodies out of a raw MIME message. */
    private function extract_body($raw)
    {
        $out = ['html' => '', 'text' => ''];

        $decoder = new rcube_mime_decode(['include_bodies' => true, 'decode_bodies' => true, 'decode_headers' => true]);
        $struct  = $decoder->decode($raw);

        if ($struct) {
            $this->walk_parts($struct, $out);
        }

        return $out;
    }

    private function walk_parts($part, &$out)
    {
        $type = trim(($part->ctype_primary ?? '') . '/' . ($part->ctype_secondary ?? ''));

        if ($type === 'text/html' && $out['html'] === '' && isset($part->body)) {
            $out['html'] = $part->body;
        }
        else if ($type === 'text/plain' && $out['text'] === '' && isset($part->body)) {
            $out['text'] = $part->body;
        }

        foreach ((array) ($part->parts ?? []) as $child) {
            $this->walk_parts($child, $out);
        }
    }

    /** Sanitise recipient-authored HTML before showing it in our own page. */
    private function wash($html)
    {
        $washer = new rcube_washtml([
            'html_elements' => ['body', 'div', 'p', 'br', 'span', 'a', 'b', 'i', 'u', 'strong', 'em',
                'ul', 'ol', 'li', 'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'table', 'tr', 'td', 'th',
                'thead', 'tbody', 'img', 'hr'],
            'html_attribs'  => ['href', 'src', 'alt', 'title', 'style', 'class', 'colspan', 'rowspan'],
        ]);

        return $washer->wash($html);
    }
}
