<?php

/**
 * Horus :: the Scheduled view and its actions.
 *
 * The sidebar's "Scheduled" entry opens this: a plain list of the messages waiting to
 * go out, each with cancel / reschedule / edit. It is not an IMAP folder — the rows
 * live in horus_scheduled — so a recipient can never move or delete one by touching a
 * mailbox. Delivery itself is the cron's job (bin/horus_cron.php); this is only the
 * cockpit.
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
        $this->rc->output->add_handlers(['plugin.horus_scheduled' => [$this, 'render']]);
        $this->rc->output->send('horus.scheduled');
    }

    public function render($attrib)
    {
        $rows = $this->store->scheduled_for_user($this->rc->user->ID);

        if (!$rows) {
            return html::div(['id' => 'horus-scheduled', 'class' => 'horus-root'],
                html::div('horus-empty', rcube::Q($this->plugin->gettext('scheduledempty'))));
        }

        $body = '';

        foreach ($rows as $row) {
            $body .= $this->row_html($row);
        }

        $head = html::tag('tr', null,
            html::tag('th', 'horus-sched-when', rcube::Q($this->plugin->gettext('colwhen')))
            . html::tag('th', null, rcube::Q($this->plugin->gettext('colrecipient')))
            . html::tag('th', null, rcube::Q($this->plugin->gettext('colsubject')))
            . html::tag('th', 'horus-sched-status', rcube::Q($this->plugin->gettext('colstatus')))
            . html::tag('th', 'horus-sched-actions', '')
        );

        return html::div(['id' => 'horus-scheduled', 'class' => 'horus-root'],
            html::tag('table', 'horus-table horus-sched',
                html::tag('thead', null, $head) . html::tag('tbody', null, $body))
        );
    }

    private function row_html($row)
    {
        $id      = intval($row['scheduled_id']);
        $pending = $row['status'] === 'pending';
        $when    = $this->rc->format_date(horus_store::ts($row['send_at']));

        $status = $this->status_pill($row);

        $actions = '';

        if ($pending) {
            $actions = html::a([
                    'href'  => '#',
                    'class' => 'horus-sched-edit',
                    'rel'   => $id,
                    'title' => $this->plugin->gettext('editschedule'),
                ], rcube::Q($this->plugin->gettext('editschedule')))
                . html::a([
                    'href'  => '#',
                    'class' => 'horus-sched-move',
                    'rel'   => $id,
                    'data-when' => intval(horus_store::ts($row['send_at'])),
                    'title' => $this->plugin->gettext('reschedule'),
                ], rcube::Q($this->plugin->gettext('reschedule')))
                . html::a([
                    'href'  => '#',
                    'class' => 'horus-sched-cancel',
                    'rel'   => $id,
                    'title' => $this->plugin->gettext('cancelschedule'),
                ], rcube::Q($this->plugin->gettext('cancelschedule')));
        }

        return html::tag('tr', ['class' => 'horus-sched-row' . ($pending ? '' : ' horus-sched-done')],
            html::tag('td', 'horus-sched-when', rcube::Q($when))
            . html::tag('td', null, rcube::Q($row['recipients'] ?: '—'))
            . html::tag('td', null, rcube::Q($row['subject'] ?: '—'))
            . html::tag('td', 'horus-sched-status', $status)
            . html::tag('td', 'horus-sched-actions', $actions)
        );
    }

    private function status_pill($row)
    {
        switch ($row['status']) {
            case 'sent':
                return html::span('horus-pill horus-pill-opened', rcube::Q($this->plugin->gettext('scheduledsent')));
            case 'failed':
                return html::span('horus-pill horus-pill-notopened',
                    rcube::Q($this->plugin->gettext('scheduledfailed'))
                    . ($row['last_error'] ? ' ' . html::span('horus-muted', rcube::Q(mb_substr($row['last_error'], 0, 120))) : ''));
            default:
                return html::span('horus-pill horus-pill-clicked', rcube::Q($this->plugin->gettext('scheduledpending')));
        }
    }

    // ----------------------------------------------------------------- actions

    /**
     * Cancel / reschedule / edit, all mutating so all CSRF-guarded. They answer the
     * Roundcube AJAX envelope: a message plus a reload command the view listens for.
     */
    public function handle_action()
    {
        if (!$this->rc->check_request()) {
            $this->rc->output->command('display_message', $this->plugin->gettext('invalidrequest'), 'error');
            $this->rc->output->send();
            return;
        }

        $id  = intval(rcube_utils::get_input_value('_sched', rcube_utils::INPUT_POST));
        $row = $id ? $this->store->get_scheduled($id) : null;

        if (!$row || $row['user_id'] != $this->rc->user->ID) {
            $this->rc->output->command('display_message', $this->plugin->gettext('notfound'), 'error');
            $this->rc->output->send();
            return;
        }

        switch ($this->rc->action) {
            case 'plugin.horus.schedcancel':
                $this->do_cancel($row);
                break;
            case 'plugin.horus.schedmove':
                $this->do_reschedule($row);
                break;
            case 'plugin.horus.schededit':
                $this->do_edit($row);
                break;
            default:
                $this->rc->output->command('display_message', $this->plugin->gettext('invalidrequest'), 'error');
                $this->rc->output->send();
        }
    }

    private function do_cancel($row)
    {
        // Only a still-pending message can be cancelled; a sent one has already gone.
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
        $epoch = intval(rcube_utils::get_input_value('_when', rcube_utils::INPUT_POST));

        if ($row['status'] !== 'pending' || $epoch < time() + 30) {
            $this->rc->output->command('display_message', $this->plugin->gettext('schedulepast'), 'error');
            $this->rc->output->send();
            return;
        }

        $this->store->update_scheduled_time($row['scheduled_id'], gmdate('Y-m-d H:i:s', $epoch));

        $this->rc->output->command('display_message', $this->plugin->gettext('schedulemoved'), 'confirmation');
        $this->rc->output->command('plugin.horus_sched_reload');
        $this->rc->output->send();
    }

    /**
     * Edit: drop the frozen message into Drafts, cancel the schedule, and hand the
     * browser a compose URL that reopens that draft — Roundcube's own draft-reopen then
     * restores the body and attachments. The temporary pixel is stripped first so the
     * reopened draft is clean; when the user sends again, Horus tracks it afresh.
     */
    private function do_edit($row)
    {
        if ($row['status'] !== 'pending') {
            $this->rc->output->command('display_message', $this->plugin->gettext('notfound'), 'error');
            $this->rc->output->send();
            return;
        }

        $raw = $this->storage->read($row['storage_key']);

        if ($raw === null) {
            $this->rc->output->command('display_message', $this->plugin->gettext('notfound'), 'error');
            $this->rc->output->send();
            return;
        }

        // Strip the tracking pixel so a reopened-and-resent draft is not left with a
        // stale one; the rewritten links are left alone (they still resolve) and a fresh
        // send re-tracks the message under a new id.
        $raw = preg_replace('#<img[^>]*' . preg_quote(horus_injector::param(), '#') . '=px[^>]*>#i', '', $raw);

        $drafts = $this->rc->config->get('drafts_mbox') ?: 'Drafts';
        $uid    = $this->rc->get_storage()->save_message($drafts, $raw, '', false, ['SEEN', 'DRAFT']);

        if ($uid === false) {
            $this->rc->output->command('display_message', $this->plugin->gettext('uploaderror'), 'error');
            $this->rc->output->send();
            return;
        }

        // The schedule is being turned back into an editable draft; drop it.
        $this->store->cancel_scheduled($row['scheduled_id']);
        $this->storage->delete($row['storage_key']);

        $url = $this->rc->url([
            '_task'      => 'mail',
            '_action'    => 'compose',
            '_draft_uid' => $uid,
            '_mbox'      => $drafts,
        ]);

        $this->rc->output->command('plugin.horus_sched_edit', ['url' => $url]);
        $this->rc->output->send();
    }
}
