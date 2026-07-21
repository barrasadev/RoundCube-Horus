<?php

/**
 * Horus :: compose-time UI.
 *
 * Adds two things to the compose screen:
 *
 *  - a "track this message" toggle, defaulting to the user's preference;
 *  - a second attachment zone. Files dropped there are NOT attached to the message.
 *    They are stored server-side and the body gets a link block instead, so opening
 *    and downloading each file can be tracked individually.
 *
 * @license GNU GPLv3+
 */
class horus_compose
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

    // ---------------------------------------------------------------- rendering

    /**
     * Inject the Horus block into the compose options container.
     *
     * The compose id is not known yet at this point in the request, so the markup
     * carries no id and the JS reads `rcmail.env.compose_id` when it uploads.
     */
    public function render()
    {
        $enabled = (bool) $this->rc->config->get('horus_default_enabled', true);

        $this->rc->output->set_env('horus_max_doc_size', horus_storage::parse_size(
            $this->rc->config->get('horus_max_doc_size', '25M')
        ));
        $this->rc->output->set_env('horus_track_default', $enabled);

        $this->rc->output->add_label(
            'horus.uploading', 'horus.uploaderror', 'horus.uploadtoolarge',
            'horus.uploadblockedtype', 'horus.removedoc', 'horus.nodocs'
        );

        $this->plugin->include_script('horus.js');
        $this->plugin->api->add_content($this->options_html($enabled), 'composeoptions');
    }

    /**
     * Markup for the compose options container.
     *
     * Uses the same form-group/row structure as core and enigma so it lines up with
     * the surrounding Elastic options instead of looking bolted on.
     */
    private function options_html($enabled)
    {
        $checkbox = new html_checkbox([
            'value'   => 1,
            'id'      => 'horus-track',
            'name'    => '_horus_track',
            'class'   => 'form-check-input',
            'onclick' => 'rcmail.command("plugin.horus.toggle", this.checked)',
        ]);

        $toggle = html::div('form-group form-check row',
            html::label(['for' => 'horus-track', 'class' => 'col-form-label col-6'],
                rcube::Q($this->plugin->gettext('enabletracking'))
            )
            . html::div('col-6',
                $checkbox->show($enabled ? 1 : 0)
                // Lets the send handler tell "unticked" apart from "our UI was not
                // involved at all", which fall back to different defaults.
                . html::tag('input', ['type' => 'hidden', 'name' => '_horus_track_present', 'value' => '1'])
            )
        );

        // Only meaningful with more than one recipient, and it changes how the mail
        // actually goes out, so it is off unless the user asks for it.
        $split_box = new html_checkbox([
            'value' => 1,
            'id'    => 'horus-split',
            'name'  => '_horus_split',
            'class' => 'form-check-input',
        ]);

        $split = html::div('form-group form-check row',
            html::label(['for' => 'horus-split', 'class' => 'col-form-label col-6'],
                rcube::Q($this->plugin->gettext('splitrecipients'))
            )
            . html::div('col-6',
                $split_box->show($this->rc->config->get('horus_split_recipients', false) ? 1 : 0)
                . html::div('horus-hint', rcube::Q($this->plugin->gettext('splitrecipientshint')))
            )
        );

        $docs = html::div(['class' => 'form-group row', 'id' => 'horus-docs-group'],
            html::label(['class' => 'col-form-label col-6'],
                rcube::Q($this->plugin->gettext('trackedattachments'))
            )
            . html::div('col-6',
                html::div(['id' => 'horus-doclist', 'class' => 'horus-doclist'], '')
                . html::tag('input', [
                    'type'  => 'file',
                    'id'    => 'horus-docfile',
                    'multiple' => 'multiple',
                    'style' => 'display:none',
                    'onchange' => 'rcmail.command("plugin.horus.upload", this)',
                ])
                . html::tag('button', [
                    'type'    => 'button',
                    'class'   => 'btn btn-secondary horus-addbtn',
                    'onclick' => "document.getElementById('horus-docfile').click()",
                ], rcube::Q($this->plugin->gettext('adddocument')))
                . html::div('horus-hint', rcube::Q($this->plugin->gettext('trackedattachmentshint')))
            )
        );

        return html::div('horus-compose-options', $toggle . $split . $docs);
    }

    // ----------------------------------------------------------------- actions

    /**
     * Handle plugin.horus.upload / .delete / .list. Always terminates the request
     * with a JSON body; the compose UI talks to these over fetch(), not the
     * Roundcube AJAX envelope.
     */
    public function handle_action()
    {
        // Every one of these mutates state, so the CSRF token is mandatory.
        if (!$this->rc->check_request()) {
            $this->respond(['error' => 'invalidrequest'], 403);
        }

        $compose_id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);

        if (empty($compose_id) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $compose_id)) {
            $this->respond(['error' => 'invalidrequest'], 400);
        }

        switch ($this->rc->action) {
            case 'plugin.horus.upload':
                $this->action_upload($compose_id);
                break;
            case 'plugin.horus.delete':
                $this->action_delete($compose_id);
                break;
            default:
                $this->action_list($compose_id);
        }
    }

    private function action_upload($compose_id)
    {
        $user_id = $this->rc->user->ID;
        $saved   = [];
        $errors  = [];

        foreach ($this->normalize_uploads() as $upload) {
            $result = $this->storage->store_upload($upload);

            if (is_string($result)) {
                $errors[] = ['name' => horus_storage::sanitize_name($upload['name']), 'error' => $result];
                continue;
            }

            $doc = $this->store->create_document($user_id, $compose_id, $result);

            if (!$doc) {
                $this->storage->delete($result['storage_key']);
                $errors[] = ['name' => $result['name'], 'error' => 'horus.uploaderror'];
                continue;
            }

            $saved[] = $this->doc_json($doc);
        }

        $this->respond(['docs' => $saved, 'errors' => $errors]);
    }

    private function action_delete($compose_id)
    {
        $doc_id = intval(rcube_utils::get_input_value('_doc', rcube_utils::INPUT_POST));
        $doc    = $doc_id ? $this->store->get_document($doc_id) : null;

        // Only an unsent document, owned by this user, staged in this very compose.
        if (!$doc || $doc['user_id'] != $this->rc->user->ID
            || $doc['compose_id'] !== $compose_id || !empty($doc['message_id'])
        ) {
            $this->respond(['error' => 'invalidrequest'], 404);
        }

        $this->storage->delete($doc['storage_key']);
        $this->store->delete_document($doc_id, $this->rc->user->ID);

        $this->respond(['deleted' => $doc_id]);
    }

    private function action_list($compose_id)
    {
        $docs = array_map([$this, 'doc_json'], $this->store->documents_for_compose($this->rc->user->ID, $compose_id));

        $this->respond(['docs' => $docs]);
    }

    private function doc_json(array $doc)
    {
        return [
            'id'   => intval($doc['doc_id']),
            'name' => $doc['filename'],
            'size' => horus_storage::format_size($doc['size']),
        ];
    }

    /**
     * PHP presents multi-file inputs as parallel arrays; flatten them into one
     * upload descriptor per file, dropping anything that errored in transit.
     */
    private function normalize_uploads()
    {
        $field = $_FILES['_horus_file'] ?? null;

        if (empty($field) || empty($field['name'])) {
            return [];
        }

        $names = (array) $field['name'];
        $out   = [];

        foreach (array_keys($names) as $i) {
            $entry = [];

            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $entry[$key] = is_array($field[$key]) ? ($field[$key][$i] ?? null) : $field[$key];
            }

            if (($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function respond(array $data, $code = 200)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8', true, $code);
        header('X-Content-Type-Options: nosniff');

        echo json_encode($data);
        exit;
    }
}
