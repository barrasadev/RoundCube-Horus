<?php

/**
 * Horus - end-to-end email tracking for Roundcube.
 *
 * Self-hosted and self-contained: the webmail itself serves the tracking pixel, the
 * click redirects and the tracked attachments. Nothing leaves the server.
 *
 *  - message_before_send : mint a uuid, inject the pixel, sign every link, swap
 *                          "tracked attachments" for links into the body
 *  - startup             : serve the public px|click|doc endpoints before auth
 *  - message_objects     : show the tracking state on a sent message
 *  - preferences_*       : plugin settings section
 *  - task 'horus'        : the dashboard
 *
 * @author  Built for the RoundCube-Horus project
 * @license GNU GPLv3+
 */
class horus extends rcube_plugin
{
    /** Endpoints must run on any task, including none at all. */
    public $task = '.*';

    /** @var rcmail */
    private $rc;

    /** @var horus_store */
    private $store;

    /** @var horus_storage */
    private $storage;

    /** @var bool True while send_per_recipient() is delivering its own copies. */
    private $splitting = false;

    public function init()
    {
        $this->rc = rcmail::get_instance();

        // Only the admin's own plugin config, never the shipped .dist. Roundcube's
        // rcube_config::merge() lets a plugin's config win over the main config.inc.php,
        // so loading the .dist here would silently override anything the admin set
        // globally. Defaults live in code, applied through config->get($key, $default).
        $this->load_config();

        require_once __DIR__ . '/lib/horus_db.php';
        require_once __DIR__ . '/lib/horus_signer.php';
        require_once __DIR__ . '/lib/horus_store.php';
        require_once __DIR__ . '/lib/horus_storage.php';
        require_once __DIR__ . '/lib/horus_settings.php';
        require_once __DIR__ . '/lib/horus_bots.php';
        require_once __DIR__ . '/lib/horus_icons.php';
        require_once __DIR__ . '/lib/horus_flags.php';
        require_once __DIR__ . '/lib/horus_geo.php';
        require_once __DIR__ . '/lib/horus_intel.php';
        require_once __DIR__ . '/lib/horus_selfview.php';
        require_once __DIR__ . '/lib/horus_classifier.php';
        require_once __DIR__ . '/lib/horus_injector.php';
        require_once __DIR__ . '/lib/horus_endpoints.php';

        $this->add_texts('localization/', true);

        // Plugins are loaded before the session starts and before the task is known
        // (rcmail::startup calls load_plugins() ahead of session_init()/set_task()),
        // so everything session- or task-dependent is wired from the startup hook.
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('message_before_send', [$this, 'message_before_send']);

        // Message-ID is what links a message in the Sent folder back to its tracking
        // record, but Roundcube does not fetch it when listing a folder. Ask for it
        // here: storage can be initialised at any point in the request, so this hook
        // cannot wait for the task to be known.
        $this->add_hook('storage_init', [$this, 'storage_init']);

        $this->register_task('horus');
    }

    // ------------------------------------------------------------------ wiring

    /**
     * Intercept the public tracking URLs, then wire up the UI for logged-in users.
     */
    public function startup($args)
    {
        // Accept the configured parameter, and `_horus` as a permanent fallback so
        // links in already-sent messages keep working if the name is ever changed.
        $mode = $_GET[horus_injector::param()] ?? ($_GET['_horus'] ?? null);

        if (is_string($mode) && $mode !== '') {
            // No session and no schema check: answer and get out.
            $endpoints = new horus_endpoints($this->store(), $this->storage());
            $endpoints->dispatch($mode);
            // dispatch() exits.
        }

        if (empty($_SESSION['user_id'])) {
            return $args;
        }

        horus_db::ensure_schema($this->home);

        $task   = $args['task'];
        $action = $args['action'];

        if ($task == 'horus') {
            $this->register_action('index',   [$this, 'dashboard_action']);
            $this->register_action('search',  [$this, 'dashboard_action']);
            $this->register_action('details', [$this, 'dashboard_action']);
            $this->api->register_action('plugin.horus.markbot', $this->ID, [$this, 'markbot_action']);
        }
        else if ($task == 'mail') {
            $this->add_hook('message_objects', [$this, 'message_objects']);
            $this->add_hook('messages_list', [$this, 'messages_list']);

            // Registered straight against the API rather than through
            // rcube_plugin::register_action(): that helper prefixes the action with
            // this plugin's own task (register_task('horus') sets $mytask), which
            // would turn these into "horus.plugin.horus.upload" and leave the mail
            // task with no handler.
            foreach (['upload', 'delete', 'list'] as $name) {
                $this->api->register_action('plugin.horus.' . $name, $this->ID, [$this, 'compose_action']);
            }

            $this->api->register_action('plugin.horus.markbot', $this->ID, [$this, 'markbot_action']);

            // Tracked attachments have to survive "save as draft" and reopening.
            $this->add_hook('message_draftsaved', [$this, 'message_draftsaved']);
            $this->add_hook('message_compose', [$this, 'message_compose']);

            if ($action == 'compose') {
                $this->compose_ui();
            }
        }
        else if ($task == 'settings') {
            $this->add_hook('preferences_sections_list', [$this, 'prefs_sections']);
            $this->add_hook('preferences_list', [$this, 'prefs_list']);
            $this->add_hook('preferences_save', [$this, 'prefs_save']);
        }

        if ($this->rc->output && !$this->rc->output->framed) {
            // The taskbar button's command has to exist on every page that renders
            // the taskbar, so the script goes in globally rather than per-action.
            $this->include_script('horus.js');

            // Sidebar entry.
            $this->add_button([
                    'command'    => 'horus',
                    'class'      => 'button-horus',
                    'classsel'   => 'button-horus button-selected',
                    'innerclass' => 'inner',
                    'label'      => 'horus.horus',
                    'type'       => 'link',
                ], 'taskbar'
            );
        }

        $this->include_stylesheet($this->local_skin_path() . '/horus.css');

        return $args;
    }

    private function store()
    {
        if (!$this->store) {
            $this->store = new horus_store();
        }

        return $this->store;
    }

    private function storage()
    {
        if (!$this->storage) {
            $this->storage = new horus_storage();
        }

        return $this->storage;
    }

    // -------------------------------------------------------------------- send

    /**
     * Mint the tracking record and rewrite the outgoing message.
     *
     * Runs on real sends only - Roundcube saves drafts through a different path - so
     * a draft is never rewritten and never consumes a uuid.
     */
    public function message_before_send($args)
    {
        // Re-entry from send_per_recipient(): that method has already rewritten the
        // body and recorded the tracking row for this copy.
        if ($this->splitting) {
            return $args;
        }

        horus_db::ensure_schema($this->home);

        $store   = $this->store();
        $message = $args['message'];
        $user_id = $this->rc->user->ID;

        $compose_id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
        $docs       = $compose_id ? $store->documents_for_compose($user_id, $compose_id) : [];

        $wanted = $this->tracking_requested();

        // Tracking off means tracking off. A tracked attachment is only ever reachable
        // through a tracking link, so sending one with tracking disabled would either
        // ship a link that reports back anyway or ship nothing the recipient can open.
        // The files stay staged and the orphan sweep collects them; the message goes
        // out as if they had never been added.
        if (!$wanted) {
            return $args;
        }

        $headers    = $message->headers();
        $recipients = $this->recipients($args, $headers);

        // Per-recipient mode: one copy each, one uuid each, so an open can be
        // attributed to a person rather than just to the message.
        if ($this->split_requested() && count($recipients) > 1) {
            return $this->send_per_recipient($args, $message, $headers, $recipients, $docs, $compose_id);
        }

        $uuid = horus_store::uuid();
        $html = $message->getHTMLBody();

        // Tracking needs somewhere to put a pixel and a rewritten link, and a
        // text/plain message has neither. Rather than silently sending an untrackable
        // message, promote it: the original text stays as the plain alternative, so a
        // plain-text reader sees exactly what they would have seen before.
        if ($html === null || $html === '') {
            $html = self::text_to_html((string) $message->getTXTBody());
        }

        $injected = false;

        if ($html !== null && $html !== '') {
            $message->setHTMLBody(horus_injector::process_html($html, $uuid, $docs));
            $injected = true;
        }

        // The plain-text alternative is generated before this hook runs, so the
        // document links have to be appended to it separately - otherwise a recipient
        // reading text/plain has no way to reach the files at all.
        if ($docs && ($text = $message->getTXTBody())) {
            $message->setTXTBody($text . horus_injector::documents_block_text($docs));
            $injected = true;
        }

        $message_id = $store->create_message($user_id, [
            'uuid'       => $uuid,
            'msgid'      => $headers['Message-ID'] ?? null,
            'subject'    => $headers['Subject'] ?? '',
            'recipients' => $recipients,
            'from_addr'  => $this->address_of($args['from'] ?? ($headers['From'] ?? '')),
            // Remembered so a later pixel hit from this same address can be
            // recognised as the sender re-reading their own copy.
            'sender_ip'  => rcube_utils::remote_addr() ?: null,
            'tracked'    => $injected,
        ]);

        if ($message_id && $docs) {
            $store->attach_documents_to_message($user_id, $compose_id, $message_id);
        }

        if (!$message_id) {
            rcube::raise_error([
                    'code' => 601, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => 'Horus: could not persist tracking record; message sent untracked'
                ], true, false);
        }

        $args['message'] = $message;

        return $args;
    }

    /**
     * Send one copy per recipient, each carrying its own tracking uuid.
     *
     * All recipients of a normal message share one body, so they share one pixel:
     * you learn that the message was opened but never by whom. The only way around
     * that is a separate copy each, which is what this does - so it is opt-in, and
     * only ever runs when there really is more than one recipient.
     *
     * The copies keep the original Message-ID. That is deliberate: it is what ties
     * the single copy in Sent back to all of the per-recipient records.
     *
     * @return array The hook arguments, with the normal single send aborted
     */
    private function send_per_recipient($args, $message, $headers, array $recipients, array $docs, $compose_id)
    {
        $store   = $this->store();
        $user_id = $this->rc->user->ID;
        $msgid   = $headers['Message-ID'] ?? null;
        $from    = $args['from'] ?? '';
        $sender  = rcube_utils::remote_addr() ?: null;

        // Captured before the loop: get() rewrites inline-image references in the
        // HTML body as a side effect, so every copy has to be built from the
        // pristine original rather than from whatever the previous send left behind.
        $orig_html = $message->getHTMLBody();
        $orig_text = (string) $message->getTXTBody();

        if ($orig_html === null || $orig_html === '') {
            $orig_html = self::text_to_html($orig_text);
        }

        $error   = null;
        $sent    = 0;
        $failed  = [];
        $first   = null;

        // deliver_message() fires this very hook again; the guard makes the nested
        // call fall straight through instead of recursing forever.
        $this->splitting = true;

        foreach ($recipients as $rcpt) {
            $uuid = horus_store::uuid();

            $message->setHTMLBody(horus_injector::process_html($orig_html, $uuid, $docs));

            if ($orig_text !== '' && $docs) {
                $message->setTXTBody($orig_text . horus_injector::documents_block_text($docs));
            }

            $ok = $this->deliver_one($message, $from, $rcpt, $args['options'] ?? null, $error);

            if (!$ok) {
                $failed[] = $rcpt;
                continue;
            }

            $sent++;

            $id = $store->create_message($user_id, [
                'uuid'       => $uuid,
                'msgid'      => $msgid,
                'subject'    => $headers['Subject'] ?? '',
                'recipients' => [$rcpt],
                'from_addr'  => $this->address_of($from ?: ($headers['From'] ?? '')),
                'sender_ip'  => $sender,
                'tracked'    => true,
            ]);

            $first = $first ?? $id;
        }

        $this->splitting = false;

        // The tracked attachments are shared by every copy, so they attach to the
        // first record; the document endpoints key off the document, not the message.
        if ($first && $docs) {
            $store->attach_documents_to_message($user_id, $compose_id, $first);
        }

        // Leave the copy that gets filed in Sent without any tracking markup: it is
        // the user's own archive, and a pixel there would only ever be fetched by
        // the user themselves.
        $message->setHTMLBody($docs ? horus_injector::documents_block($docs) . $orig_html : $orig_html);

        if ($orig_text !== '' && $docs) {
            $message->setTXTBody($orig_text . horus_injector::documents_block_text($docs));
        }

        if ($failed) {
            rcube::raise_error([
                    'code' => 601, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => 'Horus: per-recipient send failed for ' . implode(', ', $failed)
                ], true, false);
        }

        $args['message'] = $message;
        $args['abort']   = true;
        $args['result']  = $sent > 0;
        $args['error']   = $sent > 0 ? null : $error;

        return $args;
    }

    /**
     * Deliver the message to exactly one address.
     *
     * This deliberately does NOT go through rcube::deliver_message(). That method
     * builds its SMTP envelope as `$mailto` PLUS every address in the Cc and Bcc
     * headers, which is right for a single send but catastrophic in a loop: with a
     * Cc present, every one of the per-recipient copies would also be delivered to
     * the whole Cc list, so those people would receive one copy per recipient.
     *
     * The headers are left untouched (apart from stripping Bcc, as core does), so
     * each recipient still sees the real To and Cc lists and the message looks
     * exactly like an ordinary one. Only the envelope is narrowed.
     *
     * @param string $error Receives the SMTP error, by reference
     *
     * @return bool
     */
    private function deliver_one($message, $from, $rcpt, $options, &$error)
    {
        if (!is_object($this->rc->smtp)) {
            $this->rc->smtp_init(true);
        }

        $smtp_headers = $message->txtHeaders(['Bcc' => null], true);

        // Mirror core's handling of very large messages: spool the body to a file
        // rather than holding it in memory.
        $body_file = null;

        if ($message->getParam('delay_file_io')) {
            $body_file = rcube_utils::temp_filename('msg');
            $result    = $message->saveMessageBody($body_file);

            if (is_a($result, 'PEAR_Error')) {
                $error = 'Could not create message: ' . $result->getMessage();
                return false;
            }

            $body = fopen($body_file, 'r');
        }
        else {
            $body = $message->get();
        }

        $sent = $this->rc->smtp->send_mail($from, [$rcpt], $smtp_headers, $body, $options);

        if (is_resource($body)) {
            fclose($body);
        }

        if ($body_file) {
            @unlink($body_file);
        }

        if (!$sent) {
            $error = $this->rc->smtp->get_error();
        }
        else {
            // Other plugins hook this for logging and quota accounting; each copy
            // really was a send, so each one is announced.
            $this->rc->plugins->exec_hook('message_sent',
                ['headers' => $message->headers(), 'body' => '', 'message' => $message]);
        }

        return (bool) $sent;
    }

    /**
     * Did the user ask for a separate, individually tracked copy per recipient?
     */
    private function split_requested()
    {
        $posted = rcube_utils::get_input_value('_horus_split', rcube_utils::INPUT_POST);

        if ($posted !== null && $posted !== '') {
            return (bool) intval($posted);
        }

        if (isset($_POST['_horus_track_present'])) {
            return false; // our compose UI was on the form and the box was unticked
        }

        return (bool) $this->rc->config->get('horus_split_recipients', false);
    }

    /**
     * Wrap a plain-text body in minimal HTML so a tracked message always has an HTML
     * part. Deliberately plain: no styling, no wrapper chrome, nothing that would make
     * the message look different to the recipient than the text they typed.
     */
    private static function text_to_html($text)
    {
        if (trim($text) === '') {
            // An empty body still needs somewhere to hang the pixel.
            return '<div></div>';
        }

        return '<div style="white-space:pre-wrap;font-family:inherit">'
            . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }

    /**
     * Did the user ask for this message to be tracked?
     *
     * The compose form posts the checkbox; when it is absent (a send that did not come
     * from our UI, e.g. an external API call) fall back to the user's default.
     */
    private function tracking_requested()
    {
        $posted = rcube_utils::get_input_value('_horus_track', rcube_utils::INPUT_POST);

        if ($posted !== null && $posted !== '') {
            return (bool) intval($posted);
        }

        if (isset($_POST['_horus_track_present'])) {
            return false; // our checkbox was on the form and was unticked
        }

        return (bool) $this->rc->config->get('horus_default_enabled', true);
    }

    /**
     * Flatten every recipient of the message into plain addresses.
     */
    private function recipients($args, $headers)
    {
        $raw = [];

        foreach ([$args['mailto'] ?? null, $headers['Cc'] ?? null, $headers['Bcc'] ?? null] as $field) {
            if (!empty($field)) {
                $raw[] = is_array($field) ? implode(',', $field) : $field;
            }
        }

        $out = [];

        foreach (rcube_mime::decode_address_list(implode(',', $raw), null, false) as $addr) {
            if (!empty($addr['mailto'])) {
                $out[] = strtolower($addr['mailto']);
            }
        }

        return array_values(array_unique($out));
    }

    private function address_of($value)
    {
        $list = rcube_mime::decode_address_list((string) $value, 1, false);
        $first = reset($list);

        return $first && !empty($first['mailto']) ? strtolower($first['mailto']) : (string) $value;
    }

    // ------------------------------------------------------------------ drafts

    /**
     * A draft was saved: tie this compose session's staged files to it.
     *
     * Roundcube regenerates the Message-ID on every draft save, so this runs each
     * time and simply keeps the newest one.
     */
    public function message_draftsaved($args)
    {
        $compose_id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);

        if (empty($compose_id) || empty($args['msgid'])) {
            return $args;
        }

        horus_db::ensure_schema($this->home);

        $this->store()->set_draft_msgid($this->rc->user->ID, $compose_id, $args['msgid']);

        return $args;
    }

    /**
     * A compose screen is opening. If it is a draft being reopened, hand its staged
     * tracked attachments to the new compose session.
     */
    public function message_compose($args)
    {
        $uid = $args['param']['draft_uid'] ?? null;

        if (empty($uid) || empty($args['id'])) {
            return $args;
        }

        $folder = $this->rc->config->get('drafts_mbox');
        $header = $folder ? $this->rc->storage->get_message_headers($uid, $folder) : null;

        if (empty($header->messageID)) {
            return $args;
        }

        horus_db::ensure_schema($this->home);

        $this->store()->adopt_draft_documents($this->rc->user->ID, $header->messageID, $args['id']);

        return $args;
    }

    // ----------------------------------------------------------------- compose

    public function compose_ui()
    {
        require_once __DIR__ . '/lib/horus_compose.php';

        (new horus_compose($this, $this->store(), $this->storage()))->render();
    }

    public function compose_action()
    {
        require_once __DIR__ . '/lib/horus_compose.php';

        horus_db::ensure_schema($this->home);

        $ui = new horus_compose($this, $this->store(), $this->storage());
        $ui->handle_action();
    }

    // ------------------------------------------------------------ message view

    /**
     * Add Message-ID to the headers fetched for every message list.
     */
    public function storage_init($args)
    {
        if (isset($args['fetch_headers'])) {
            $args['fetch_headers'] = trim($args['fetch_headers'] . ' MESSAGE-ID');
        }
        else {
            $args['fetch_headers'] = 'MESSAGE-ID';
        }

        return $args;
    }

    public function messages_list($args)
    {
        require_once __DIR__ . '/lib/horus_list.php';

        return (new horus_list($this, $this->store()))->messages_list($args);
    }

    public function message_objects($args)
    {
        require_once __DIR__ . '/lib/horus_msgview.php';

        $view = new horus_msgview($this, $this->store());

        return $view->message_objects($args);
    }

    // ---------------------------------------------------------------- settings

    public function prefs_sections($args)
    {
        require_once __DIR__ . '/lib/horus_prefs.php';

        return (new horus_prefs($this, $this->store()))->sections_list($args);
    }

    public function prefs_list($args)
    {
        require_once __DIR__ . '/lib/horus_prefs.php';

        return (new horus_prefs($this, $this->store()))->prefs_list($args);
    }

    public function prefs_save($args)
    {
        require_once __DIR__ . '/lib/horus_prefs.php';

        return (new horus_prefs($this, $this->store()))->prefs_save($args);
    }

    // -------------------------------------------------------------- mark a bot

    /**
     * Let the user declare an address a bot when the classifier did not.
     *
     * The address is added to their own always-bot ranges (so future hits are caught
     * automatically) and every open already recorded from it is reclassified, which
     * can move a message from "opened" back to "possibly opened".
     */
    public function markbot_action()
    {
        $rc = $this->rc;

        if (!$rc->check_request()) {
            $rc->output->command('display_message', $this->gettext('markbotfailed'), 'error');
            $rc->output->send();
            return;
        }

        $ip = trim((string) rcube_utils::get_input_value('_ip', rcube_utils::INPUT_POST));

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $rc->output->command('display_message', $this->gettext('markbotfailed'), 'error');
            $rc->output->send();
            return;
        }

        horus_db::ensure_schema($this->home);

        // Persist it as a /32 or /128 in the user's own ranges, which is the same list
        // the Settings screen edits.
        $cidr   = $ip . (strpos($ip, ':') !== false ? '/128' : '/32');
        $ranges = horus_settings::parse_ranges((array) $rc->config->get('horus_bot_ranges', []));

        if (!in_array($cidr, $ranges, true)) {
            $ranges[] = $cidr;
            $rc->user->save_prefs(['horus_bot_ranges' => $ranges]);
        }

        list($events, $messages) = $this->store()->mark_ip_as_bot($rc->user->ID, $ip);

        $rc->output->command('display_message',
            sprintf($this->gettext('markbotdone'), $ip, $events, $messages), 'confirmation');
        $rc->output->command('plugin.horus_marked', ['ip' => $ip]);
        $rc->output->send();
    }

    // --------------------------------------------------------------- dashboard

    public function dashboard_action()
    {
        require_once __DIR__ . '/lib/horus_dashboard.php';

        horus_db::ensure_schema($this->home);

        $dashboard = new horus_dashboard($this, $this->store(), $this->storage());
        $dashboard->run();
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Remove staged uploads from composes that were never sent, plus their rows.
     * Called opportunistically from the dashboard so there is no cron dependency.
     */
    public function cleanup_orphans()
    {
        $days = intval($this->rc->config->get('horus_orphan_days', 7));

        if ($days <= 0) {
            return 0;
        }

        $store   = $this->store();
        $storage = $this->storage();
        $n       = 0;

        foreach ($store->orphan_documents($days) as $doc) {
            $storage->delete($doc['storage_key']);
            $store->purge_document($doc['doc_id']);
            $n++;
        }

        return $n;
    }
}
