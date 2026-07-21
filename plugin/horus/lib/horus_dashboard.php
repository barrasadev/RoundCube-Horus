<?php

/**
 * Horus :: the dashboard task.
 *
 * A single server-rendered page: headline metrics, an activity chart over a
 * selectable date range, a recipient roll-up and a filterable message list. The date
 * range, search and filters are all plain GET parameters, so every view is linkable
 * and the page works without JavaScript.
 *
 * @license GNU GPLv3+
 */
class horus_dashboard
{
    /** Categorical slots 1-3 (light | dark), validated for CVD separation. */
    const SERIES = [
        'sent'    => ['light' => '#2a78d6', 'dark' => '#3987e5'],
        'opened'  => ['light' => '#008300', 'dark' => '#008300'],
        'clicked' => ['light' => '#e87ba4', 'dark' => '#d55181'],
    ];

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

    public function run()
    {
        if ($this->rc->action === 'details') {
            $this->action_details();
            return;
        }

        $this->action_index();
    }

    // ------------------------------------------------------------------- index

    private function action_index()
    {
        // Piggyback the housekeeping the plugin would otherwise need a cron for.
        $this->plugin->cleanup_orphans();
        (new horus_bots($this->store, horus_settings::get()))->refresh_if_stale();

        $this->plugin->include_script('horus.js');
        $this->rc->output->set_pagetitle($this->plugin->gettext('horus'));
        $this->rc->output->add_handlers(['plugin.horus_dashboard' => [$this, 'render']]);
        $this->rc->output->send('horus.dashboard');
    }

    /**
     * Timeline for one message, loaded into the details drawer.
     */
    private function action_details()
    {
        $uuid   = rcube_utils::get_input_string('_uuid', rcube_utils::INPUT_GPC);
        $record = $this->store->get_message_by_uuid($uuid);

        if (!$record || $record['user_id'] != $this->rc->user->ID) {
            $this->rc->output->command('display_message', $this->plugin->gettext('notfound'), 'error');
            $this->rc->output->send();
            return;
        }

        require_once __DIR__ . '/horus_msgview.php';

        $this->rc->output->command('plugin.horus_details', [
            'uuid' => $uuid,
            'html' => $this->timeline_html($record),
        ]);

        $this->rc->output->send();
    }

    // ------------------------------------------------------------------ render

    public function render($attrib)
    {
        $user_id = $this->rc->user->ID;
        $search  = trim((string) rcube_utils::get_input_string('_q', rcube_utils::INPUT_GPC));
        $filter  = (string) rcube_utils::get_input_string('_filter', rcube_utils::INPUT_GPC);
        $range   = $this->range();

        $stats  = $this->store->global_stats($user_id, $range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59');
        $series = $this->store->series($user_id, $range['from'], $range['to']);
        $rows   = $this->store->list_messages($user_id, ['search' => $search, 'filter' => $filter, 'limit' => 200]);

        $out = $this->tiles($stats)
            . $this->chart($series, $range)
            . $this->controls($search, $filter)
            . ($search !== '' ? $this->recipient_card($user_id, $search) : '')
            . $this->message_table($rows);

        return html::div(['id' => 'horus-dashboard', 'class' => 'horus-root'], $out);
    }

    /**
     * The selected date window.
     *
     * `_range` is one of 7/30/90/365 or "custom"; a custom window reads `_from`/`_to`.
     * Anything unparseable falls back to the last 30 days rather than erroring - this
     * is a dashboard, not a form.
     */
    private function range()
    {
        $preset = (string) rcube_utils::get_input_string('_range', rcube_utils::INPUT_GPC);
        $today  = gmdate('Y-m-d');

        if ($preset === 'custom') {
            $from = self::valid_date(rcube_utils::get_input_string('_from', rcube_utils::INPUT_GPC));
            $to   = self::valid_date(rcube_utils::get_input_string('_to', rcube_utils::INPUT_GPC));

            if ($from && $to) {
                // Tolerate a reversed range instead of showing nothing.
                if ($from > $to) {
                    list($from, $to) = [$to, $from];
                }

                return ['preset' => 'custom', 'from' => $from, 'to' => $to];
            }
        }

        $days = in_array($preset, ['7', '30', '90', '365'], true) ? intval($preset) : 30;

        return [
            'preset' => (string) $days,
            'from'   => gmdate('Y-m-d', strtotime("$today -" . ($days - 1) . " days UTC")),
            'to'     => $today,
        ];
    }

    private static function valid_date($value)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) && strtotime($value) ? $value : null;
    }

    // ------------------------------------------------------------------- tiles

    /**
     * Headline numbers. Confirmed and "possibly" opens are two separate figures on
     * purpose: merging them is exactly the inflation this plugin exists to avoid.
     */
    private function tiles($stats)
    {
        $tiles = [
            $this->tile('sent', $stats['total'], $this->t('tilesent'),
                sprintf($this->t('tiletrackedof'), $stats['tracked'])),

            $this->tile('open', $stats['open_rate'] . '%', $this->t('tileopenrate'),
                sprintf($this->t('tileopencount'), $stats['opened_real'], $stats['tracked'])),

            $this->tile('maybe', $stats['open_rate_maybe'] . '%', $this->t('tilemayberate'),
                sprintf($this->t('tilemaybecount'), $stats['opened_maybe'])),

            $this->tile('click', $stats['click_rate'] . '%', $this->t('tileclickrate'),
                sprintf($this->t('tileclickcount'), $stats['clicked'])),

            $this->tile('docs', $stats['docs_downloaded'] . '/' . $stats['docs'], $this->t('tiledocs'),
                sprintf($this->t('tiledocsviewed'), $stats['docs_viewed'])),
        ];

        return html::div('horus-tiles', implode('', $tiles));
    }

    private function tile($kind, $value, $label, $sub)
    {
        return html::div("horus-tile horus-tile-$kind",
            html::div('horus-tile-value', rcube::Q((string) $value))
            . html::div('horus-tile-label', rcube::Q($label))
            . html::div('horus-tile-sub', rcube::Q($sub))
        );
    }

    // ------------------------------------------------------------------- chart

    /**
     * Activity over the selected range, as an inline multi-series line chart.
     *
     * Hand-built SVG rather than a charting library: the dashboard has to work with
     * no external assets, and this is one axis and three polylines. JavaScript only
     * adds the hover layer and the legend toggle on top of it.
     */
    private function chart(array $series, array $range)
    {
        $header = html::div('horus-card-head',
            html::div('horus-card-title', rcube::Q($this->t('chartactivity')))
            . $this->range_picker($range)
        );

        $total = array_sum(array_column($series, 'sent'));

        if (!$total) {
            return html::div('horus-card',
                $header . html::div('horus-empty', rcube::Q($this->t('nodatayet')))
            );
        }

        $w = 760; $h = 240;
        $pad = ['l' => 38, 'r' => 74, 't' => 18, 'b' => 30];

        $x0 = $pad['l'];
        $x1 = $w - $pad['r'];
        $y0 = $pad['t'];
        $y1 = $h - $pad['b'];

        $max = 0;
        foreach ($series as $point) {
            $max = max($max, $point['sent'], $point['opened'], $point['clicked']);
        }

        $max  = self::nice_ceiling($max);
        $n    = count($series);
        $step = $n > 1 ? ($x1 - $x0) / ($n - 1) : 0;

        $svg = '';

        // Recessive gridlines + y labels.
        $ticks = 4;
        for ($i = 0; $i <= $ticks; $i++) {
            $value = $max * $i / $ticks;
            $y     = $y1 - ($y1 - $y0) * ($i / $ticks);

            $svg .= sprintf('<line class="horus-grid" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>', $x0, $y, $x1, $y);
            $svg .= sprintf('<text class="horus-axis" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
                $x0 - 8, $y + 4, rcube::Q((string) round($value)));
        }

        // X labels, thinned to about six so they never collide whatever the span.
        $every = max(1, (int) ceil($n / 6));

        foreach ($series as $i => $point) {
            if ($i % $every !== 0 && $i !== $n - 1) {
                continue;
            }

            $x = $x0 + $step * $i;
            $svg .= sprintf('<text class="horus-axis" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
                $x, $y1 + 18, rcube::Q($point['label']));
        }

        // One 2px polyline per series. Draw the lines first, then the labels, so a
        // label is never overdrawn by a later series.
        $ends = [];

        foreach (array_keys(self::SERIES) as $idx => $key) {
            $points = [];
            $last_y = $y1;

            foreach ($series as $i => $point) {
                $x = $x0 + $step * $i;
                $y = $y1 - ($y1 - $y0) * ($max ? $point[$key] / $max : 0);

                $points[] = sprintf('%.1f,%.1f', $x, $y);
                $last_y   = $y;
            }

            // data-series lets the legend toggle hide a line without a round trip.
            $svg .= sprintf('<polyline class="horus-line horus-s%d" data-series="%s" points="%s"/>',
                $idx + 1, $key, implode(' ', $points));

            $ends[] = ['slot' => $idx + 1, 'key' => $key, 'y' => $last_y, 'label' => $this->t('series' . $key)];
        }

        // Direct end-labels. Series that finish at similar values would otherwise
        // print on top of each other, so nudge them apart vertically first. The
        // labels also carry the relief rule for the low-contrast slot in light mode.
        foreach (self::spread($ends, 14, $y0, $y1) as $end) {
            $svg .= sprintf('<circle class="horus-dot horus-s%d" data-series="%s" cx="%.1f" cy="%.1f" r="4"/>',
                $end['slot'], $end['key'], $x1, $end['y']);
            $svg .= sprintf('<text class="horus-endlabel horus-s%d" data-series="%s" x="%.1f" y="%.1f">%s</text>',
                $end['slot'], $end['key'], $x1 + 10, $end['label_y'] + 4, rcube::Q($end['label']));
        }

        // Hover layer: one full-height band per bucket. Bands are invisible but are
        // much easier to hit than a 2px line, which is what makes the tooltip usable.
        $bands = '';

        foreach ($series as $i => $point) {
            $bands .= sprintf(
                '<rect class="horus-band" x="%.1f" y="%.1f" width="%.1f" height="%.1f" data-i="%d"/>',
                $x0 + $step * ($i - 0.5), $y0, max($step, 1), $y1 - $y0, $i
            );
        }

        $svg .= sprintf('<line class="horus-crosshair" x1="0" y1="%.1f" x2="0" y2="%.1f" style="display:none"/>', $y0, $y1);
        $svg .= $bands;

        $chart = '<svg class="horus-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
            . 'aria-label="' . rcube::Q($this->t('chartactivity')) . '" preserveAspectRatio="xMidYMid meet">'
            . $svg . '</svg>';

        // The tooltip is built client-side from this, so hovering costs no request.
        $data = [];

        foreach ($series as $point) {
            $data[] = [
                'l' => $point['label'],
                'd' => $point['date'],
                's' => intval($point['sent']),
                'o' => intval($point['opened']),
                'c' => intval($point['clicked']),
            ];
        }

        $figure = html::div([
            'class'       => 'horus-figure',
            'data-points' => json_encode($data),
            'data-labels' => json_encode([
                'sent'    => $this->t('seriessent'),
                'opened'  => $this->t('seriesopened'),
                'clicked' => $this->t('seriesclicked'),
            ]),
        ], $chart . html::div('horus-tooltip', ''));

        return html::div('horus-card', $header . $this->legend() . $figure . $this->table_view($series));
    }

    /**
     * Legend entries double as the series toggle: clicking one isolates it, clicking
     * again restores the rest. Each keeps its own colour slot whatever is hidden, so
     * hiding a series never repaints the survivors.
     */
    private function legend()
    {
        $items = '';

        foreach (array_keys(self::SERIES) as $idx => $key) {
            $items .= html::tag('button', [
                'type'          => 'button',
                'class'         => 'horus-legend-item',
                'data-series'   => $key,
                'aria-pressed'  => 'true',
                'onclick'       => "rcmail.command('plugin.horus.series', '" . rcube::JQ($key) . "')",
            ],
                html::span('horus-swatch horus-s' . ($idx + 1), '')
                . rcube::Q($this->t('series' . $key))
            );
        }

        return html::div('horus-legend', $items
            . html::span('horus-legend-hint', rcube::Q($this->t('legendhint'))));
    }

    /**
     * The same numbers as a table, collapsed. A chart alone is not an accessible
     * presentation of the data, and this is also the quickest way to read an exact
     * value out of a long range.
     */
    private function table_view(array $series)
    {
        $rows = '';

        foreach (array_reverse($series) as $point) {
            if (!$point['sent'] && !$point['opened'] && !$point['clicked']) {
                continue;
            }

            $rows .= html::tag('tr', null,
                html::tag('td', null, rcube::Q($point['date']))
                . html::tag('td', null, rcube::Q($point['sent']))
                . html::tag('td', null, rcube::Q($point['opened']))
                . html::tag('td', null, rcube::Q($point['clicked']))
            );
        }

        if ($rows === '') {
            return '';
        }

        $head = html::tag('tr', null,
            html::tag('th', null, rcube::Q($this->t('colwhen')))
            . html::tag('th', null, rcube::Q($this->t('seriessent')))
            . html::tag('th', null, rcube::Q($this->t('seriesopened')))
            . html::tag('th', null, rcube::Q($this->t('seriesclicked')))
        );

        return html::tag('details', 'horus-tableview',
            html::tag('summary', null, rcube::Q($this->t('showtable')))
            . html::tag('table', 'horus-table',
                html::tag('thead', null, $head) . html::tag('tbody', null, $rows))
        );
    }

    /**
     * Preset windows plus a custom range. Plain links and a GET form, so every view
     * stays linkable and survives a page reload.
     */
    private function range_picker(array $range)
    {
        $presets = ['7' => 'range7', '30' => 'range30', '90' => 'range90', '365' => 'range365'];
        $buttons = '';

        foreach ($presets as $days => $label) {
            // PHP turns numeric array keys into ints, so compare as strings.
            $buttons .= html::a([
                'href'  => './?_task=horus&_action=index&_range=' . $days,
                'class' => 'horus-range' . ($range['preset'] === (string) $days ? ' selected' : ''),
            ], rcube::Q($this->t($label)));
        }

        $custom = html::tag('form', ['class' => 'horus-range-custom', 'method' => 'get', 'action' => './'],
            html::tag('input', ['type' => 'hidden', 'name' => '_task', 'value' => 'horus'])
            . html::tag('input', ['type' => 'hidden', 'name' => '_action', 'value' => 'index'])
            . html::tag('input', ['type' => 'hidden', 'name' => '_range', 'value' => 'custom'])
            . html::tag('input', ['type' => 'date', 'name' => '_from', 'value' => $range['from'],
                'class' => 'form-control', 'aria-label' => $this->t('rangefrom')])
            . html::span('horus-range-sep', '&ndash;')
            . html::tag('input', ['type' => 'date', 'name' => '_to', 'value' => $range['to'],
                'class' => 'form-control', 'aria-label' => $this->t('rangeto')])
            . html::tag('button', ['type' => 'submit', 'class' => 'horus-range'], rcube::Q($this->t('rangeapply')))
        );

        return html::div('horus-ranges', $buttons . $custom);
    }

    /**
     * Push overlapping end-labels apart, keeping each series' marker on its real
     * value and moving only the text.
     *
     * Walks top to bottom enforcing a minimum gap, then, if that pushed the last
     * label past the bottom of the plot, walks back up doing the same. Two passes
     * are enough for the handful of series this chart ever draws.
     *
     * @param array $ends Each with slot, y, label
     * @param int   $gap  Minimum vertical distance between labels
     *
     * @return array Same entries with label_y added, in the original order
     */
    private static function spread(array $ends, $gap, $top, $bottom)
    {
        usort($ends, function ($a, $b) { return $a['y'] <=> $b['y']; });

        $previous = null;

        foreach ($ends as $i => $end) {
            $y = $end['y'];

            if ($previous !== null && $y - $previous < $gap) {
                $y = $previous + $gap;
            }

            $ends[$i]['label_y'] = $previous = $y;
        }

        // Ran off the bottom: re-seat upwards from the last label.
        $overflow = end($ends)['label_y'] - $bottom;

        if ($overflow > 0) {
            $previous = null;

            foreach (array_reverse(array_keys($ends)) as $i) {
                $y = $ends[$i]['label_y'] - $overflow;

                if ($previous !== null && $previous - $y < $gap) {
                    $y = $previous - $gap;
                }

                $ends[$i]['label_y'] = $previous = max($top, $y);
            }
        }

        return $ends;
    }

    /**
     * Round an axis maximum up to something readable (1, 2, 5 x 10^n).
     */
    private static function nice_ceiling($max)
    {
        if ($max <= 4) {
            return 4;
        }

        $magnitude = pow(10, floor(log10($max)));

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($max <= $magnitude * $factor) {
                return (int) ceil($magnitude * $factor);
            }
        }

        return (int) ceil($max);
    }

    // ---------------------------------------------------------------- controls

    private function controls($search, $filter)
    {
        $filters = [
            ''           => 'filterall',
            'tracked'    => 'filtertracked',
            'untracked'  => 'filteruntracked',
            'opened'     => 'filteropened',
            'maybe'      => 'filtermaybe',
            'unopened'   => 'filterunopened',
            'clicked'    => 'filterclicked',
            'notclicked' => 'filternotclicked',
        ];

        $select = new html_select(['name' => '_filter', 'id' => 'horus-filter', 'class' => 'form-control']);

        foreach ($filters as $value => $label) {
            $select->add($this->t($label), $value);
        }

        $input = new html_inputfield([
            'name' => '_q', 'id' => 'horus-q', 'class' => 'form-control',
            'placeholder' => $this->t('searchplaceholder'), 'size' => 32,
        ]);

        return html::tag('form', ['class' => 'horus-controls', 'method' => 'get', 'action' => './'],
            html::tag('input', ['type' => 'hidden', 'name' => '_task', 'value' => 'horus'])
            . html::tag('input', ['type' => 'hidden', 'name' => '_action', 'value' => 'index'])
            . $input->show($search)
            . $select->show($filter)
            . html::tag('button', ['type' => 'submit', 'class' => 'btn btn-primary'], rcube::Q($this->t('search')))
        );
    }

    /**
     * When the user searches for an address, lead with that person's roll-up: this is
     * the "what has this contact ever done with my email" question.
     */
    private function recipient_card($user_id, $search)
    {
        $rows = $this->store->recipient_summary($user_id, $search, 10);

        if (!$rows) {
            return '';
        }

        $body = '';

        foreach ($rows as $row) {
            $body .= html::tag('tr', null,
                html::tag('td', 'horus-addr', rcube::Q($row['to_addr']))
                . html::tag('td', null, rcube::Q($row['sent']))
                . html::tag('td', null, html::span('horus-tag horus-tag-ok', rcube::Q($row['opened'])))
                . html::tag('td', null, html::span('horus-tag horus-tag-unknown', rcube::Q($row['maybe'])))
                . html::tag('td', null, html::span('horus-tag horus-tag-link', rcube::Q($row['clicked'])))
                . html::tag('td', 'horus-when', rcube::Q($this->when($row['last_sent'])))
            );
        }

        $head = html::tag('tr', null,
            html::tag('th', null, rcube::Q($this->t('colrecipient')))
            . html::tag('th', null, rcube::Q($this->t('colsent')))
            . html::tag('th', null, rcube::Q($this->t('colopened')))
            . html::tag('th', null, rcube::Q($this->t('colmaybe')))
            . html::tag('th', null, rcube::Q($this->t('colclicked')))
            . html::tag('th', null, rcube::Q($this->t('collastsent')))
        );

        return html::div('horus-card',
            html::div('horus-card-title', rcube::Q($this->t('byrecipient')))
            . html::tag('table', 'horus-table',
                html::tag('thead', null, $head) . html::tag('tbody', null, $body))
        );
    }

    // ------------------------------------------------------------------- table

    private function message_table(array $rows)
    {
        if (!$rows) {
            return html::div('horus-card',
                html::div('horus-card-title', rcube::Q($this->t('messages')))
                . html::div('horus-empty', rcube::Q($this->t('nomessages')))
            );
        }

        $body = '';

        foreach ($rows as $row) {
            $body .= $this->message_row($row);
        }

        $head = html::tag('tr', null,
            html::tag('th', null, rcube::Q($this->t('colwhen')))
            . html::tag('th', null, rcube::Q($this->t('colrecipient')))
            . html::tag('th', null, rcube::Q($this->t('colsubject')))
            . html::tag('th', null, rcube::Q($this->t('colstatus')))
            . html::tag('th', null, rcube::Q($this->t('coldocs')))
            . html::tag('th', null, '')
        );

        return html::div('horus-card',
            html::div('horus-card-title', rcube::Q($this->t('messages')) . ' ' . html::span('horus-count', count($rows)))
            . html::tag('table', 'horus-table horus-messages',
                html::tag('thead', null, $head) . html::tag('tbody', null, $body))
        );
    }

    private function message_row($row)
    {
        $status = $this->status_tags($row);
        $docs   = intval($row['doc_count'])
            ? rcube::Q(sprintf('%d/%d/%d', $row['doc_downloaded'], $row['doc_viewed'], $row['doc_count']))
            : html::span('horus-muted', '&mdash;');

        $button = html::tag('button', [
            'type'    => 'button',
            'class'   => 'horus-toggle',
            'onclick' => "rcmail.command('plugin.horus.row', '" . rcube::JQ($row['uuid']) . "')",
        ], rcube::Q($this->t('details')));

        $main = html::tag('tr', ['class' => 'horus-row', 'data-uuid' => $row['uuid']],
            html::tag('td', 'horus-when', rcube::Q($this->when($row['sent_at'])))
            . html::tag('td', 'horus-addr', rcube::Q($row['to_addr']))
            . html::tag('td', 'horus-subject', rcube::Q($row['subject']))
            . html::tag('td', null, $status)
            . html::tag('td', 'horus-docs-cell', $docs)
            . html::tag('td', null, $button)
        );

        // Pre-rendered, collapsed drawer: no round trip when the user expands a row.
        $drawer = html::tag('tr', ['class' => 'horus-drawer', 'style' => 'display:none'],
            html::tag('td', ['colspan' => 6], $this->timeline_html($row))
        );

        return $main . $drawer;
    }

    private function status_tags($row)
    {
        if (empty($row['tracked'])) {
            return html::span('horus-tag horus-tag-off',
                horus_icons::get('off') . ' ' . rcube::Q($this->t('nottracked')));
        }

        $tags = [];

        // real_open_count OR human_confirmed: a click proves a person was there, so a
        // message that only ever showed bot pixel fetches still counts as opened.
        if ($row['real_open_count'] > 0 || !empty($row['human_confirmed'])) {
            $tags[] = html::span('horus-tag horus-tag-ok',
                horus_icons::get('opened') . ' ' . rcube::Q($this->t('opened')));
        }
        else if ($row['open_count'] > 0) {
            $tags[] = html::span('horus-tag horus-tag-unknown',
                horus_icons::get('maybe') . ' ' . rcube::Q($this->t('maybeopened')));
        }
        else {
            $tags[] = html::span('horus-tag horus-tag-off',
                horus_icons::get('sent') . ' ' . rcube::Q($this->t('noopens')));
        }

        if ($row['click_count'] > 0) {
            $tags[] = html::span('horus-tag horus-tag-link',
                horus_icons::get('clicked') . ' ' . rcube::Q($this->t('clicked')));
        }

        return implode(' ', $tags);
    }

    /**
     * Event timeline shared by the dashboard drawer and the sent-message box.
     */
    private function timeline_html($record)
    {
        $events = $this->store->events($record['message_id']);
        $docs   = $this->store->documents_for_message($record['message_id']);

        $out = '';

        if ($docs) {
            $items = '';

            foreach ($docs as $doc) {
                $state = $doc['download_count'] > 0
                    ? html::span('horus-tag horus-tag-ok', rcube::Q($this->t('downloaded')) . ' ' . rcube::Q($this->when($doc['first_download_at'])))
                    : ($doc['view_count'] > 0
                        ? html::span('horus-tag horus-tag-unknown', rcube::Q($this->t('viewed')) . ' ' . rcube::Q($this->when($doc['first_view_at'])))
                        : html::span('horus-tag horus-tag-off', rcube::Q($this->t('notopened'))));

                $items .= html::div('horus-docline',
                    horus_icons::get('attachment') . ' ' . rcube::Q($doc['filename'])
                    . ' ' . html::span('horus-muted', rcube::Q('(' . horus_storage::format_size($doc['size']) . ')'))
                    . ' ' . $state
                );
            }

            $out .= html::div('horus-drawer-section',
                html::div('horus-drawer-title', rcube::Q($this->t('trackedattachments'))) . $items);
        }

        if (!$events) {
            $out .= html::div('horus-empty', rcube::Q($this->t('noevents')));

            return $out;
        }

        $rows = '';

        foreach ($events as $event) {
            $rows .= html::tag('tr', null,
                html::tag('td', 'horus-t-when', rcube::Q($this->when($event['created_at'])))
                . html::tag('td', 'horus-t-what', $this->event_tag($event))
                . html::tag('td', 'horus-t-note', $this->event_note($event))
            );
        }

        return $out . html::div('horus-drawer-section',
            html::div('horus-drawer-title', rcube::Q($this->t('timeline')))
            . html::tag('table', 'horus-timeline', html::tag('tbody', null, $rows))
        );
    }

    private function event_tag($event)
    {
        switch ($event['type']) {
            case horus_store::EVENT_OPEN:
                $cls = $event['status'] === horus_store::STATUS_CONFIRMED ? 'ok'
                    : ($event['status'] === horus_store::STATUS_BOT ? 'bot'
                    : ($event['status'] === horus_store::STATUS_SELF ? 'self' : 'unknown'));

                return html::span("horus-tag horus-tag-$cls", rcube::Q($this->t('event' . $event['status'])));

            case horus_store::EVENT_CLICK:
                return html::span('horus-tag horus-tag-link', rcube::Q($this->t('eventclick')));

            case horus_store::EVENT_DOC_VIEW:
                return html::span('horus-tag horus-tag-unknown', rcube::Q($this->t('eventdocview')));

            case horus_store::EVENT_DOC_DOWNLOAD:
                return html::span('horus-tag horus-tag-ok', rcube::Q($this->t('eventdocdownload')));
        }

        return rcube::Q($event['type']);
    }

    private function event_note($event)
    {
        return self::render_event_note($event, $this->rc);
    }

    /**
     * Everything recorded about one event: what it touched, why it was classified the
     * way it was, and who the client was - address, reverse DNS name, parsed client
     * and OS, language, referrer and proxy chain.
     *
     * Shared with the sent-message box so both views show identical detail.
     */
    public static function render_event_note($event, $rc)
    {
        $head = [];

        if (!empty($event['filename'])) {
            $head[] = rcube::Q($event['filename']);
        }
        else if (!empty($event['url'])) {
            $head[] = rcube::Q(mb_strimwidth($event['url'], 0, 70, '...'));
        }

        if ($key = horus_classifier::reason_label($event['reason'])) {
            $head[] = rcube::Q($rc->gettext($key));
        }

        // Client identity line.
        $who = [];

        if (!empty($event['client'])) {
            $who[] = rcube::Q(trim($event['client'] . ' ' . ($event['client_ver'] ?? '')));
        }
        if (!empty($event['os'])) {
            $who[] = rcube::Q($event['os']);
        }
        if (!empty($event['device'])) {
            $who[] = rcube::Q($event['device']);
        }
        if (!empty($event['language'])) {
            $who[] = rcube::Q(strtok($event['language'], ','));
        }

        // Network identity line. The reverse DNS name is the most informative field
        // here, so it is shown next to the address rather than hidden in the raw data.
        $net = [];

        if (!empty($event['ip'])) {
            $net[] = html::span('horus-ip', rcube::Q($event['ip'])
                . (!empty($event['ip_version']) ? ' (v' . intval($event['ip_version']) . ')' : ''));

            // Offer the override only where it would change something: an event
            // already classified as a bot, or as the user's own view, would not move.
            if ($event['status'] !== horus_store::STATUS_BOT
                && $event['status'] !== horus_store::STATUS_SELF
            ) {
                $net[] = html::tag('button', [
                    'type'    => 'button',
                    'class'   => 'horus-markbot',
                    'title'   => $rc->gettext('horus.markbottitle'),
                    'onclick' => "rcmail.command('plugin.horus.markbot', '" . rcube::JQ($event['ip']) . "')",
                ], horus_icons::get('bot', '', 11) . ' ' . rcube::Q($rc->gettext('horus.markbot')));
            }
        }
        if (!empty($event['hostname'])) {
            $net[] = html::span('horus-host', rcube::Q($event['hostname']));
        }
        if (!empty($event['forwarded']) && $event['forwarded'] !== ($event['ip'] ?? '')) {
            $net[] = html::span('horus-muted', 'via ' . rcube::Q($event['forwarded']));
        }
        if (!empty($event['referer'])) {
            $net[] = html::span('horus-muted', 'ref ' . rcube::Q(mb_strimwidth($event['referer'], 0, 50, '...')));
        }

        $out = implode(' &middot; ', $head);

        if ($who) {
            $out .= html::div('horus-evline', implode(' &middot; ', $who));
        }
        if ($net) {
            $out .= html::div('horus-evline', implode(' &middot; ', $net));
        }

        // Raw user agent and extra headers, collapsed - useful when a client is not
        // recognised, noise otherwise.
        $raw = [];

        if (!empty($event['user_agent'])) {
            $raw[] = rcube::Q($event['user_agent']);
        }
        if (!empty($event['headers'])) {
            $decoded = json_decode($event['headers'], true);

            if (is_array($decoded)) {
                foreach ($decoded as $name => $value) {
                    $raw[] = rcube::Q($name . ': ' . $value);
                }
            }
        }

        if ($raw) {
            $out .= html::tag('details', 'horus-raw',
                html::tag('summary', null, rcube::Q($rc->gettext('horus.rawrequest')))
                . html::div('horus-rawbody', implode('<br>', $raw))
            );
        }

        return $out;
    }

    private function when($value)
    {
        $ts = horus_store::ts($value);

        return $ts ? $this->rc->format_date($ts) : '';
    }

    private function t($key)
    {
        return $this->plugin->gettext($key);
    }
}
