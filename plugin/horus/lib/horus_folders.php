<?php

/**
 * Horus :: coloured folders.
 *
 * A colour picked in the folder's own settings page, remembered per user, and used
 * to paint that folder's row in the mail sidebar - the row and nothing else.
 * Messages, headers and the message list are left alone.
 *
 * The colours live in the user's preferences keyed by IMAP folder name, so no
 * schema and no server-side state are involved; renaming a folder carries the
 * colour (and its children's colours) over, deleting one drops it.
 *
 * @license GNU GPLv3+
 */
class horus_folders
{
    /** User preference holding [folder name => #rrggbb]. */
    const PREF = 'horus_folder_colors';

    /**
     * The luminance at which black and white contrast equally against a background:
     * above it black is the readable choice, below it white is.
     */
    const CROSSOVER = 0.179;

    /** Offered as swatches in the folder form. A custom colour is always allowed. */
    const PALETTE = [
        '#e5484d', // red
        '#f76b15', // orange
        '#ffb224', // amber
        '#46a758', // green
        '#12a594', // teal
        '#0091ff', // blue
        '#8e4ec6', // purple
        '#8b8d98', // grey
    ];

    /** @var horus */
    private $plugin;

    /** @var rcmail */
    private $rc;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->rc     = rcmail::get_instance();
    }

    // ------------------------------------------------------------------- read

    /**
     * Off unless the user turns it on in the Horus settings section.
     */
    public static function enabled()
    {
        return !empty(rcmail::get_instance()->config->get('horus_folder_colors_enabled', false));
    }

    /**
     * @return array [imap folder name => '#rrggbb']
     */
    public static function colors()
    {
        $rc  = rcmail::get_instance();
        $map = $rc->config->get(self::PREF);

        return is_array($map) ? $map : [];
    }

    /**
     * The stylesheet that paints the sidebar, or '' when nothing is coloured.
     *
     * Written as one <style> block rather than inline attributes because the folder
     * list is rendered by Roundcube itself and re-rendered on refresh; a rule keyed
     * by the list item's id survives that without hooking into the markup.
     */
    public static function styles()
    {
        if (!self::enabled()) {
            return '';
        }

        $css = '';

        foreach (self::colors() as $folder => $color) {
            if (!self::is_color($color)) {
                continue;
            }

            // Attribute selector, not #id: the identifier is base64url, so it can
            // start with a digit or a hyphen and would not be a valid CSS ident.
            $li = '#mailboxlist li[id="rcmli' . rcube_utils::html_identifier($folder, true) . '"]';
            $fg = self::foreground($color);

            // The row is painted, so the text and the icon have to be re-set against
            // it - Elastic's own foreground is chosen for a white list.
            $css .= $li . ' > a { background-color: ' . $color . '; color: ' . $fg . '; }' . "\n"
                 .  $li . ' > a::before,' . "\n"
                 .  $li . ' > a .unreadcount { color: ' . $fg . '; }' . "\n";

            // Hover and selection are Elastic's own background changes, which this
            // rule has just overridden. They come back as a translucent wash over the
            // folder's colour, so they read the same whatever colour was picked.
            $css .= $li . ' > a:hover { background-image: ' . self::wash(0.10) . '; }' . "\n"
                 .  $li . '.selected > a { background-image: ' . self::wash(0.22) . '; }' . "\n";
        }

        return $css;
    }

    /**
     * Black or white, whichever is readable on the given background.
     *
     * Uses the WCAG relative luminance rather than a plain average: a saturated
     * green and a saturated blue of the same "brightness" need opposite text.
     */
    public static function foreground($color)
    {
        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $c = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > self::CROSSOVER ? '#1f2328' : '#ffffff';
    }

    /**
     * A flat overlay used to darken a row - hover and selection - without needing to
     * know the colour underneath it.
     */
    private static function wash($alpha)
    {
        return 'linear-gradient(rgba(0, 0, 0, ' . $alpha . '), rgba(0, 0, 0, ' . $alpha . '))';
    }

    // ------------------------------------------------------------------- form

    /**
     * Add the colour picker to the folder properties form.
     */
    public function folder_form($args)
    {
        $folder = $args['name'] ?? '';
        $colors = self::colors();
        $value  = $folder !== '' && isset($colors[$folder]) && self::is_color($colors[$folder])
            ? $colors[$folder]
            : '';

        // A posted value wins, so a failed save comes back with what the user chose.
        if (isset($_POST['_horus_color'])) {
            $posted = trim((string) rcube_utils::get_input_string('_horus_color', rcube_utils::INPUT_POST));
            $value  = self::is_color($posted) ? strtolower($posted) : '';
        }

        $this->plugin->include_script('horus_folders.js');

        $swatches = '';

        foreach (self::PALETTE as $color) {
            $swatches .= html::tag('button', [
                    'type'       => 'button',
                    'class'      => 'horus-swatch' . ($value === $color ? ' selected' : ''),
                    'style'      => 'background-color: ' . $color,
                    'data-color' => $color,
                    'title'      => $color,
                ], '&nbsp;');
        }

        // "No colour" first: it is the way back, and it is where every folder starts.
        $none = html::tag('button', [
                'type'       => 'button',
                'class'      => 'horus-swatch horus-swatch-none' . ($value === '' ? ' selected' : ''),
                'data-color' => '',
                'title'      => $this->plugin->gettext('nofoldercolor'),
            ], '&nbsp;');

        // The native picker doubles as the custom-colour swatch: it shows the current
        // colour and opens the operating system's colour map when clicked.
        $custom = html::tag('input', [
                'type'  => 'color',
                'class' => 'horus-swatch horus-swatch-custom',
                'id'    => 'horus-color-custom',
                'value' => $value !== '' ? $value : '#0091ff',
                'title' => $this->plugin->gettext('customfoldercolor'),
            ]);

        // ...and the same colour as text, for anyone who arrives with a hex in hand.
        $hex = html::tag('input', [
                'type'         => 'text',
                'class'        => 'horus-color-hex form-control',
                'id'           => 'horus-color-hex',
                'value'        => $value,
                'size'         => 9,
                'maxlength'    => 7,
                'spellcheck'   => 'false',
                'autocomplete' => 'off',
                'placeholder'  => '#rrggbb',
                'title'        => $this->plugin->gettext('foldercolorhex'),
            ]);

        $hidden = new html_hiddenfield(['name' => '_horus_color', 'id' => 'horus-color-value', 'value' => $value]);

        // What the sidebar will look like, painted by the same rules that will paint
        // it - so the choice is made against the result rather than against a chip.
        $preview = html::div('horus-color-preview',
            html::span('horus-color-preview-row', rcube::Q($this->display_name($folder))));

        $args['form']['props']['fieldsets']['settings']['content']['horus_color'] = [
            'label' => $this->plugin->gettext('foldercolor'),
            'value' => html::div('horus-color-picker',
                html::div('horus-color-swatches', $none . $swatches . $custom . $hex)
                . $preview . $hidden->show()),
        ];

        return $args;
    }

    /**
     * The folder's own name as the sidebar shows it: the leaf, decoded, localised
     * for the special folders. Empty for a folder that does not exist yet.
     */
    private function display_name($folder)
    {
        if ($folder === '') {
            return $this->plugin->gettext('foldercolor');
        }

        $path = explode($this->delimiter(), $folder);
        $leaf = rcube_charset::convert(array_pop($path), 'UTF7-IMAP');
        $key  = rcmail_action::folder_classname($folder);

        return $key && $this->rc->text_exists($key) ? $this->rc->gettext($key) : $leaf;
    }

    // ------------------------------------------------------------------ write

    /**
     * Store the colour chosen for a folder being created.
     *
     * The hook runs before the folder exists, so a failed creation can leave an
     * entry behind. It is keyed by a name nothing points at, costs a few bytes, and
     * is overwritten the next time that name is created.
     */
    public function folder_create($args)
    {
        $this->apply_posted_color($args['record']['name'] ?? '');

        return $args;
    }

    /**
     * Store the colour, and carry colours across when the folder is being renamed.
     */
    public function folder_update($args)
    {
        $record = $args['record'];
        $old    = $record['oldname'] ?? '';
        $new    = $record['name'] ?? '';

        if ($old !== '' && $new !== '' && $old !== $new) {
            $this->move($old, $new);
        }

        $this->apply_posted_color($new);

        return $args;
    }

    /**
     * Renaming from the folder list (drag & drop or the rename control).
     */
    public function folder_rename($args)
    {
        $this->move($args['oldname'] ?? '', $args['newname'] ?? '');

        return $args;
    }

    /**
     * Drop the colour of a deleted folder and of everything under it.
     */
    public function folder_delete($args)
    {
        $name = $args['name'] ?? '';

        if ($name === '') {
            return $args;
        }

        $colors = self::colors();
        $prefix = $name . $this->delimiter();
        $keep   = [];

        foreach ($colors as $folder => $color) {
            if ($folder !== $name && strpos($folder, $prefix) !== 0) {
                $keep[$folder] = $color;
            }
        }

        if (count($keep) != count($colors)) {
            $this->save($keep);
        }

        return $args;
    }

    // --------------------------------------------------------------- internal

    /**
     * Take the colour out of the submitted form and remember it, or forget it.
     */
    private function apply_posted_color($folder)
    {
        if ($folder === '' || !isset($_POST['_horus_color'])) {
            return;
        }

        $color  = trim((string) rcube_utils::get_input_string('_horus_color', rcube_utils::INPUT_POST));
        $colors = self::colors();

        if (self::is_color($color)) {
            $colors[$folder] = strtolower($color);
        }
        else if (isset($colors[$folder])) {
            unset($colors[$folder]);
        }
        else {
            return;
        }

        $this->save($colors);
    }

    /**
     * Move a folder's colour, and its subfolders', to the new name.
     */
    private function move($old, $new)
    {
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }

        $colors    = self::colors();
        $delimiter = $this->delimiter();
        $prefix    = $old . $delimiter;
        $moved     = [];
        $changed   = false;

        foreach ($colors as $folder => $color) {
            if ($folder === $old) {
                $moved[$new] = $color;
                $changed     = true;
            }
            else if (strpos($folder, $prefix) === 0) {
                $moved[$new . $delimiter . substr($folder, strlen($prefix))] = $color;
                $changed = true;
            }
            else {
                $moved[$folder] = $color;
            }
        }

        if ($changed) {
            $this->save($moved);
        }
    }

    private function save($colors)
    {
        $this->rc->user->save_prefs([self::PREF => $colors]);
    }

    private function delimiter()
    {
        return $this->rc->get_storage()->get_hierarchy_delimiter();
    }

    /**
     * Only ever emit a colour we recognise: this value ends up inside a stylesheet.
     */
    public static function is_color($value)
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
