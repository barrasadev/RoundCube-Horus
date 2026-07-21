<?php

/**
 * Horus :: settings section.
 *
 * @license GNU GPLv3+
 */
class horus_prefs
{
    const SECTION = 'horus';

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

    public function sections_list($args)
    {
        $args['list'][self::SECTION] = [
            'id'      => self::SECTION,
            'section' => $this->plugin->gettext('horus'),
            'class'   => 'horus',
        ];

        return $args;
    }

    public function prefs_list($args)
    {
        if ($args['section'] !== self::SECTION) {
            return $args;
        }

        $settings = horus_settings::get();
        $blocks   = [];

        // --- tracking defaults ------------------------------------------------
        $blocks['main']['name'] = $this->plugin->gettext('prefstracking');

        $checkbox = new html_checkbox(['value' => 1, 'id' => 'horus_default_enabled', 'name' => '_horus_default_enabled']);

        $blocks['main']['options']['horus_default_enabled'] = [
            'title'   => html::label('horus_default_enabled', rcube::Q($this->plugin->gettext('prefsautotrack'))),
            'content' => $checkbox->show(!empty($settings['horus_default_enabled']) ? 1 : 0),
        ];

        $split = new html_checkbox(['value' => 1, 'id' => 'horus_split', 'name' => '_horus_split']);

        $blocks['main']['options']['horus_split_recipients'] = [
            'title'   => html::label('horus_split', rcube::Q($this->plugin->gettext('prefssplit'))),
            'content' => $split->show(!empty($settings['horus_split_recipients']) ? 1 : 0)
                . html::div('horus-hint', rcube::Q($this->plugin->gettext('prefssplithint'))),
        ];

        $window = new html_inputfield([
            'id' => 'horus_prefetch_window', 'name' => '_horus_prefetch_window',
            'size' => 5, 'type' => 'number', 'min' => 0, 'max' => 3600,
        ]);

        $blocks['main']['options']['horus_prefetch_window'] = [
            'title'   => html::label('horus_prefetch_window', rcube::Q($this->plugin->gettext('prefsprefetchwindow'))),
            'content' => $window->show(intval($settings['horus_prefetch_window']))
                . html::div('horus-hint', rcube::Q($this->plugin->gettext('prefsprefetchwindowhint'))),
        ];

        // --- bot ranges -------------------------------------------------------
        $blocks['bots']['name'] = $this->plugin->gettext('prefsbots');

        $ranges = new html_textarea([
            'id' => 'horus_bot_ranges', 'name' => '_horus_bot_ranges',
            'rows' => 6, 'cols' => 40, 'spellcheck' => 'false',
        ]);

        $blocks['bots']['options']['horus_bot_ranges'] = [
            'title'   => html::label('horus_bot_ranges', rcube::Q($this->plugin->gettext('prefsbotranges'))),
            'content' => $ranges->show(implode("\n", (array) $settings['horus_bot_ranges']))
                . html::div('horus-hint', rcube::Q($this->plugin->gettext('prefsbotrangeshint'))),
        ];

        $blocks['bots']['options']['horus_apple_status'] = [
            'title'   => rcube::Q($this->plugin->gettext('prefsapplestatus')),
            'content' => $this->apple_status(),
        ];

        $refresh = new html_checkbox(['value' => 1, 'id' => 'horus_refresh_ranges', 'name' => '_horus_refresh_ranges']);

        $blocks['bots']['options']['horus_refresh_ranges'] = [
            'title'   => html::label('horus_refresh_ranges', rcube::Q($this->plugin->gettext('prefsrefreshranges'))),
            'content' => $refresh->show(0)
                . html::div('horus-hint', rcube::Q($this->plugin->gettext('prefsrefreshrangeshint'))),
        ];

        $args['blocks'] = $blocks;

        return $args;
    }

    /**
     * How many Apple egress ranges are cached, and how old they are. Shown because a
     * stale or empty cache directly weakens MPP detection - the user should be able
     * to see that rather than silently get worse classification.
     */
    private function apple_status()
    {
        $count = count($this->store->get_netranges(horus_bots::SOURCE_APPLE_RELAY));
        $age   = $this->store->netranges_age(horus_bots::SOURCE_APPLE_RELAY);

        if (!$count) {
            return html::span('horus-hint', rcube::Q($this->plugin->gettext('prefsapplenone')));
        }

        return html::span('horus-hint', rcube::Q(sprintf(
            $this->plugin->gettext('prefsapplecount'), $count, $this->rc->format_date($age)
        )));
    }

    public function prefs_save($args)
    {
        if ($args['section'] !== self::SECTION) {
            return $args;
        }

        $args['prefs']['horus_default_enabled'] = (bool) rcube_utils::get_input_value('_horus_default_enabled', rcube_utils::INPUT_POST);
        $args['prefs']['horus_split_recipients'] = (bool) rcube_utils::get_input_value('_horus_split', rcube_utils::INPUT_POST);

        $window = intval(rcube_utils::get_input_value('_horus_prefetch_window', rcube_utils::INPUT_POST));
        $args['prefs']['horus_prefetch_window'] = max(0, min(3600, $window));

        $raw    = rcube_utils::get_input_value('_horus_bot_ranges', rcube_utils::INPUT_POST);
        $parsed = horus_settings::parse_ranges($raw);

        // Tell the user which lines were dropped rather than silently discarding them.
        $submitted = array_filter(preg_split('/[\s,;]+/', (string) $raw));

        if (count($submitted) > count($parsed)) {
            $this->rc->output->show_message($this->plugin->gettext('prefsbadranges'), 'warning');
        }

        $args['prefs']['horus_bot_ranges'] = $parsed;

        if (rcube_utils::get_input_value('_horus_refresh_ranges', rcube_utils::INPUT_POST)) {
            $bots = new horus_bots($this->store, horus_settings::get());

            if ($bots->refresh_if_stale_now()) {
                $this->rc->output->show_message($this->plugin->gettext('prefsrangesupdated'), 'confirmation');
            }
            else {
                $this->rc->output->show_message($this->plugin->gettext('prefsrangesfailed'), 'warning');
            }
        }

        return $args;
    }
}
