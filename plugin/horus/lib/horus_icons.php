<?php

/**
 * Horus :: inline SVG icon set.
 *
 * A single 24x24 stroke geometry per icon, drawn with `currentColor` so an icon takes
 * the colour of whatever badge or tag encloses it - no per-context variants, and no
 * icon font or sprite sheet to ship.
 *
 * Not used inside outgoing email: mail clients strip or refuse inline SVG, so the
 * message body uses text instead (see horus_injector).
 *
 * @license GNU GPLv3+
 */
class horus_icons
{
    /** Path data only; the wrapper below supplies the shared attributes. */
    const PATHS = [
        // An open envelope with a check - the message was read.
        'opened'      => '<path d="M3 7l9 6 9-6"/><path d="M3 7h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M8.5 12.5l2.5 2.5 4.5-4.5"/>',

        // A question mark inside a circle - something fetched it, we cannot say who.
        'maybe'       => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.2 2.4c-.6.2-1 .8-1 1.4v.4"/><path d="M11.7 17h.01"/>',

        // Chain link - a click.
        'clicked'     => '<path d="M10 13a5 5 0 0 0 7.1.4l2.5-2.5a5 5 0 0 0-7.1-7.1L11 5.3"/><path d="M14 11a5 5 0 0 0-7.1-.4l-2.5 2.5a5 5 0 0 0 7.1 7.1L13 18.7"/>',

        // Paperclip - a tracked attachment.
        'attachment'  => '<path d="M21 12.8l-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8l8.9-8.9a3.7 3.7 0 0 1 5.2 5.2l-8.9 8.9a1.8 1.8 0 0 1-2.6-2.6l8.2-8.2"/>',

        // Arrow into a tray - the file was downloaded.
        'downloaded'  => '<path d="M12 3v12"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',

        // Eye - the file link was opened but not downloaded.
        'viewed'      => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/>',

        // Paper plane - sent, nothing back yet.
        'sent'        => '<path d="M21.5 3.5L2.5 10.2l7.3 2.9 2.9 7.3z"/><path d="M21.5 3.5L9.8 13.1"/>',

        // Shield with a slash - an automated fetch, deliberately not counted.
        'bot'         => '<path d="M12 3l7.5 3v5.5c0 4.4-3 8.2-7.5 9.5-4.5-1.3-7.5-5.1-7.5-9.5V6z"/><path d="M9 15l6-6"/>',

        // Disclosure chevron for the collapsed panel.
        'chevron'     => '<path d="M6 9l6 6 6-6"/>',

        // Circle with a slash - tracking was off for this message.
        'off'         => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
    ];

    /**
     * Render an icon.
     *
     * @param string $name  Key from PATHS
     * @param string $class Extra CSS classes
     * @param int    $size  Rendered size in px
     *
     * @return string SVG markup, or '' for an unknown name
     */
    public static function get($name, $class = '', $size = 14)
    {
        if (!isset(self::PATHS[$name])) {
            return '';
        }

        return '<svg class="horus-icon ' . rcube::Q($class) . '" width="' . intval($size) . '"'
            . ' height="' . intval($size) . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . self::PATHS[$name]
            . '</svg>';
    }

    /**
     * The same geometry as a data: URI, for CSS `background-image` (the task bar
     * button, where the skin owns the ::before pseudo-element).
     *
     * @param string $color CSS colour literal baked into the image
     */
    public static function data_uri($name, $color = '#ffffff')
    {
        if (!isset(self::PATHS[$name])) {
            return '';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"'
            . ' fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round">' . self::PATHS[$name] . '</svg>';

        // base64 rather than percent-encoding: a data: URI inside a CSS url() has too
        // many characters that need escaping for hand-rolled encoding to be safe.
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
