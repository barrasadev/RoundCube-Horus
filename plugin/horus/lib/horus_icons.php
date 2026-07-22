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
        // An eye - the message was looked at. An envelope only said it was mail.
        'opened'      => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/>',

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

        /*
         * The Eye of Horus - the plugin's namesake, and what marks it in the message
         * header row. Drawn as the wedjat rather than a plain eye: the brow, the
         * almond, the pupil, and the two markings below (the descending teardrop and
         * the curling tail) that make the symbol recognisable at 16px.
         */
        'horus'       => '<path d="M3.5 6.2c2-1.6 4.4-2.4 6.9-2.4 3.6 0 6.6 1.9 8.6 4.6"/>'
            . '<path d="M2.5 12.6c2.2-3 5.1-4.6 8.2-4.6s6 1.6 8.2 4.6c-2.2 3-5.1 4.6-8.2 4.6s-6-1.6-8.2-4.6z"/>'
            . '<circle cx="10.7" cy="12.6" r="2.1"/>'
            . '<path d="M8.6 16.9L7.2 21"/>'
            . '<path d="M13.4 16.4c1.6 1.4 3.4 2.1 5.4 2.1-1.1 1.4-2.6 2.1-4.4 2.1-1 0-1.8-.4-2.4-1.1"/>',

        // Operating systems.
        'os-linux'    => '<path d="M9 3.5c0-1 .8-1.7 1.8-1.7h2.4c1 0 1.8.7 1.8 1.7v3.2c0 1.6 3.1 4 3.1 8.1 0 3.3-2.5 5.4-6.1 5.4S6 18.1 6 14.8c0-4.1 3-6.5 3-8.1z"/><circle cx="10.4" cy="6.6" r=".7"/><circle cx="13.6" cy="6.6" r=".7"/><path d="M10.9 9.6c.7.6 1.5.6 2.2 0"/>',
        'os-windows'  => '<path d="M3.5 6.3l7.2-1v6.2H3.5z"/><path d="M12.3 5.1l8.2-1.1v7.5h-8.2z"/><path d="M3.5 13.1h7.2v6.2l-7.2-1z"/><path d="M12.3 13.1h8.2v7.5l-8.2-1.1z"/>',
        'os-apple'    => '<path d="M16.2 12.6c0-2.4 2-3.5 2.1-3.6-1.1-1.7-2.9-1.9-3.6-1.9-1.5-.2-3 .9-3.8.9s-2-.9-3.2-.9c-1.7 0-3.2 1-4 2.5-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3.1 2.4 1.2 0 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4.6-.9.9-1.7 1-1.8-.1 0-2.2-.9-2.2-3z"/><path d="M14.3 4.9c.7-.8 1.1-1.9 1-3-1 0-2.2.7-2.9 1.5-.6.7-1.1 1.8-1 2.9 1.1.1 2.2-.6 2.9-1.4z"/>',
        'os-android'  => '<path d="M5 9.5h14v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2z"/><path d="M5 9.5a7 7 0 0 1 14 0"/><path d="M7.5 6.2L6.2 4.1"/><path d="M16.5 6.2l1.3-2.1"/><circle cx="9.3" cy="7.3" r=".6"/><circle cx="14.7" cy="7.3" r=".6"/><path d="M9 18.5v2.2"/><path d="M15 18.5v2.2"/>',
        'os-generic'  => '<rect x="3" y="4.5" width="18" height="12" rx="1.6"/><path d="M8 20h8"/><path d="M12 16.5V20"/>',

        // Browsers and mail clients.
        'app-chrome'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.6"/><path d="M9.1 13.9L4.3 7.1"/><path d="M14.8 10.2h7.9"/><path d="M12.6 15.5l-3.4 6.3"/>',
        'app-firefox'   => '<circle cx="12" cy="12.6" r="8.4"/><path d="M12 4.2c-3 0-4.9 2-4.9 4.6 0 2.9 2.3 4 4.4 4 1.7 0 2.7.8 2.7 2.1 0 1.5-1.4 2.6-3.3 2.6"/><path d="M16.6 5.6c1.3.6 2.4 1.6 3.1 2.9"/>',
        'app-edge'      => '<circle cx="12" cy="12" r="9"/><path d="M3.6 13.6c3.6.9 9.4 1.3 16.7-.7"/><path d="M8.3 20.4c-1.2-2.4-1.5-6.7.6-10.2 1.6-2.7 4.4-3.6 6.6-2.6"/>',
        'app-safari'    => '<circle cx="12" cy="12" r="9"/><path d="M15.6 8.4l-1.9 5.3-5.3 1.9 1.9-5.3z"/>',
        'app-mail'      => '<path d="M3 7l9 6 9-6"/><path d="M3 7h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'app-bot'       => '<rect x="4" y="8" width="16" height="11" rx="2.4"/><path d="M12 4.5V8"/><circle cx="12" cy="3.4" r="1.2"/><circle cx="9" cy="13" r="1.1"/><circle cx="15" cy="13" r="1.1"/>',

        // Device classes.
        'dev-desktop' => '<rect x="2.5" y="4" width="19" height="12.5" rx="1.6"/><path d="M8 20.5h8"/><path d="M12 16.5v4"/>',
        'dev-mobile'  => '<rect x="7" y="2.5" width="10" height="19" rx="2.2"/><path d="M11 18.6h2"/>',
        'dev-tablet'  => '<rect x="4.5" y="2.5" width="15" height="19" rx="2"/><path d="M11 18.6h2"/>',
    ];

    /** Which icon represents a parsed OS string. */
    public static function for_os($os)
    {
        $os = strtolower((string) $os);

        if (strpos($os, 'linux') !== false || strpos($os, 'freebsd') !== false) return 'os-linux';
        if (strpos($os, 'windows') !== false)                                   return 'os-windows';
        if (strpos($os, 'ios') !== false || strpos($os, 'macos') !== false)     return 'os-apple';
        if (strpos($os, 'android') !== false)                                   return 'os-android';

        return $os !== '' ? 'os-generic' : null;
    }

    /** Which icon represents a parsed client string. */
    public static function for_client($client)
    {
        $c = strtolower((string) $client);

        if (strpos($c, 'chrome') !== false)  return 'app-chrome';
        if (strpos($c, 'firefox') !== false) return 'app-firefox';
        if (strpos($c, 'edge') !== false)    return 'app-edge';
        if (strpos($c, 'safari') !== false)  return 'app-safari';
        if (strpos($c, 'proxy') !== false || strpos($c, 'curl') !== false
            || strpos($c, 'wget') !== false) return 'app-bot';
        if ($c !== '')                       return 'app-mail';

        return null;
    }

    /** Which icon represents a device class. */
    public static function for_device($device)
    {
        switch ((string) $device) {
            case 'mobile':  return 'dev-mobile';
            case 'tablet':  return 'dev-tablet';
            case 'bot':     return 'app-bot';
            case 'desktop': return 'dev-desktop';
        }

        return null;
    }

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
