<?php

/**
 * Horus :: effective settings for a given user.
 *
 * The tracking endpoints run with no session, so `$rcmail->config->get()` returns the
 * admin defaults only - a user's own preferences are not loaded. Classification has to
 * honour those preferences anyway (they are what the settings screen edits), so this
 * resolves them explicitly from the user id carried on the tracked message.
 *
 * @license GNU GPLv3+
 */
class horus_settings
{
    /** Preference keys a user may override, with their fallbacks. */
    const USER_KEYS = [
        'horus_default_enabled'  => true,
        'horus_prefetch_window'  => 10,
        'horus_bot_ranges'       => [],
        'horus_classify_opens'   => true,
        'horus_split_recipients' => false,
        'horus_folder_colors_enabled' => false,
        'horus_scheduling_enabled' => false,
        // Empty means "the user's own time zone", read from the browser. A named zone
        // (e.g. 'Europe/Madrid') pins scheduling to that clock instead.
        'horus_schedule_tz'        => '',
    ];

    /** @var array Memoised per user id */
    private static $cache = [];

    /**
     * @param int|null $user_id Roundcube user; null means "current session"
     *
     * @return array Admin config overlaid with that user's preferences
     */
    public static function get($user_id = null)
    {
        $rcmail = rcmail::get_instance();

        // In-session: rcube_config already merges the logged-in user's prefs.
        if ($user_id === null || ($rcmail->user && $rcmail->user->ID == $user_id)) {
            $out = [];

            foreach (self::USER_KEYS as $key => $default) {
                $out[$key] = $rcmail->config->get($key, $default);
            }

            return $out + self::admin_only($rcmail);
        }

        if (isset(self::$cache[$user_id])) {
            return self::$cache[$user_id];
        }

        $user  = new rcube_user($user_id);
        $prefs = $user->ID ? (array) $user->get_prefs() : [];
        $out   = [];

        foreach (self::USER_KEYS as $key => $default) {
            $out[$key] = array_key_exists($key, $prefs) ? $prefs[$key] : $rcmail->config->get($key, $default);
        }

        return self::$cache[$user_id] = $out + self::admin_only($rcmail);
    }

    /**
     * Settings that are never user-editable - they are operational, not preferences.
     */
    private static function admin_only($rcmail)
    {
        return [
            'horus_apple_ranges'       => (array) $rcmail->config->get('horus_apple_ranges', []),
            'horus_fetch_apple_ranges' => (bool) $rcmail->config->get('horus_fetch_apple_ranges', true),
            'horus_ranges_ttl'         => intval($rcmail->config->get('horus_ranges_ttl', 7 * 86400)),
            'horus_fetch_timeout'      => intval($rcmail->config->get('horus_fetch_timeout', 8)),
            'horus_max_ranges'         => intval($rcmail->config->get('horus_max_ranges', 20000)),
            'horus_reverse_dns'        => (bool) $rcmail->config->get('horus_reverse_dns', true),
            'horus_geo_enabled'        => (bool) $rcmail->config->get('horus_geo_enabled', false),
            'horus_geo_timeout'        => intval($rcmail->config->get('horus_geo_timeout', 4)),
        ];
    }

    /**
     * Parse a user-supplied list of CIDR blocks (one per line or comma separated),
     * dropping anything that is not a valid network.
     *
     * @return array Valid CIDR strings
     */
    public static function parse_ranges($input)
    {
        if (is_array($input)) {
            $lines = $input;
        }
        else {
            $lines = preg_split('/[\s,;]+/', (string) $input);
        }

        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // A bare address is a /32 (or /128) - accept it as a convenience.
            if (strpos($line, '/') === false && @inet_pton($line) !== false) {
                $line .= strpos($line, ':') !== false ? '/128' : '/32';
            }

            if (horus_bots::parse_cidr($line)) {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }
}
