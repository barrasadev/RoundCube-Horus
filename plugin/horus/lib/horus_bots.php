<?php

/**
 * Horus :: network ranges belonging to mail proxies and scanners.
 *
 * Apple publishes the egress ranges used by iCloud Private Relay (and, in practice, by
 * Mail Privacy Protection) as a CSV. We mirror it into `horus_netranges` so classifying
 * an open never blocks on a network call, and so the plugin keeps working offline.
 *
 * @license GNU GPLv3+
 */
class horus_bots
{
    const SOURCE_APPLE_RELAY = 'apple_relay';

    /** Apple's published egress list for iCloud Private Relay. */
    const APPLE_RELAY_URL = 'https://mask-api.icloud.com/egress-ip-ranges.csv';

    /**
     * Apple's own AS. MPP fetches come out of here even when Private Relay is off, and
     * unlike the relay list this block has been stable for decades - it is the floor we
     * fall back to if the CSV can never be fetched.
     */
    const APPLE_STATIC = ['17.0.0.0/8', '2620:149::/32'];

    /** @var horus_store */
    private $store;

    /** @var array Effective settings (see horus_settings) */
    private $settings;

    /** @var array|null Memoised {source => [parsed ranges]} */
    private $ranges;

    public function __construct(horus_store $store, array $settings = [])
    {
        $this->store    = $store;
        $this->settings = $settings ?: horus_settings::get();
    }

    /**
     * True when $ip belongs to Apple's mail/relay infrastructure.
     */
    public function is_apple($ip)
    {
        return $this->in_source($ip, self::SOURCE_APPLE_RELAY)
            || self::cidr_match_any($ip, self::APPLE_STATIC)
            || self::cidr_match_any($ip, (array) ($this->settings['horus_apple_ranges'] ?? []));
    }

    /**
     * True when $ip is in a range the user or admin has declared to be a bot.
     */
    public function is_blocked_range($ip)
    {
        return self::cidr_match_any($ip, (array) ($this->settings['horus_bot_ranges'] ?? []));
    }

    private function in_source($ip, $source)
    {
        if ($this->ranges === null) {
            $this->ranges = [];

            foreach ($this->store->get_netranges() as $row) {
                $this->ranges[$row['source']][] = $row['cidr'];
            }
        }

        return self::cidr_match_any($ip, $this->ranges[$source] ?? []);
    }

    /**
     * Refresh the cached Apple ranges if they are older than the configured TTL.
     *
     * Called opportunistically from an authenticated request, never from a tracking
     * endpoint: a pixel must not wait on an outbound HTTP call.
     *
     * @return bool True if a refresh actually happened
     */
    public function refresh_if_stale($force = false)
    {
        if (empty($this->settings['horus_fetch_apple_ranges'])) {
            return false;
        }

        $ttl = intval($this->settings['horus_ranges_ttl'] ?? 7 * 86400);
        $age = $this->store->netranges_age(self::SOURCE_APPLE_RELAY);

        if (!$force && $age !== null && (time() - $age) < $ttl) {
            return false;
        }

        // Touch the timestamp first: if the fetch fails we still back off for a full TTL
        // instead of retrying a dead endpoint on every single request.
        $existing = array_map(function ($r) { return $r['cidr']; }, $this->store->get_netranges(self::SOURCE_APPLE_RELAY));
        $this->store->replace_netranges(self::SOURCE_APPLE_RELAY, $existing);

        if (!($csv = self::http_get(self::APPLE_RELAY_URL, intval($this->settings['horus_fetch_timeout'] ?? 8)))) {
            return false;
        }

        // Apple's list is large and grows; parse it with a hard ceiling so a runaway
        // response can never exhaust the PHP memory limit mid-request.
        $max   = intval($this->settings['horus_max_ranges'] ?? 20000);
        $cidrs = [];

        $lines = preg_split('/\r\n|\n|\r/', $csv);
        unset($csv);

        foreach ($lines as $line) {
            if (count($cidrs) >= $max) {
                rcube::raise_error([
                        'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Horus: Apple range list truncated at $max entries"
                    ], true, false);
                break;
            }

            $cidr = trim(strtok($line, ','));

            if (self::parse_cidr($cidr)) {
                $cidrs[] = $cidr;
            }
        }

        unset($lines);

        if (!$cidrs) {
            return false;
        }

        $this->store->replace_netranges(self::SOURCE_APPLE_RELAY, $cidrs);
        $this->ranges = null;

        return true;
    }

    /**
     * Force a refresh now, ignoring the TTL (used by the "update ranges" setting).
     */
    public function refresh_if_stale_now()
    {
        return $this->refresh_if_stale(true);
    }

    /**
     * Minimal HTTP GET. Uses cURL when available and falls back to a stream context so
     * the plugin has no hard dependency beyond stock PHP.
     */
    private static function http_get($url, $timeout = 8)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'Roundcube-Horus/1.0',
            ]);

            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ($body !== false && $code === 200) ? $body : null;
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout,
            'header'  => "User-Agent: Roundcube-Horus/1.0\r\n",
        ]]);

        $body = @file_get_contents($url, false, $ctx);

        return $body !== false ? $body : null;
    }

    // ------------------------------------------------------------ CIDR maths

    public static function cidr_match_any($ip, array $cidrs)
    {
        foreach ($cidrs as $cidr) {
            if (self::cidr_match($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match an IPv4 or IPv6 address against a CIDR block.
     *
     * Works on the packed binary form, so v4 and v6 share one code path and there is
     * no 32-bit overflow to worry about.
     */
    public static function cidr_match($ip, $cidr)
    {
        $addr = @inet_pton($ip);

        if ($addr === false || !($net = self::parse_cidr($cidr))) {
            return false;
        }

        list($subnet, $bits) = $net;

        // An IPv4 address can never sit inside an IPv6 block, and vice versa.
        if (strlen($addr) !== strlen($subnet)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $rest  = $bits % 8;

        if ($whole && strncmp($addr, $subnet, $whole) !== 0) {
            return false;
        }

        if ($rest) {
            $mask = ~((1 << (8 - $rest)) - 1) & 0xff;

            if ((ord($addr[$whole]) & $mask) !== (ord($subnet[$whole]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array|false [packed subnet, prefix bits]
     */
    public static function parse_cidr($cidr)
    {
        if (!is_string($cidr) || strpos($cidr, '/') === false) {
            return false;
        }

        list($subnet, $bits) = explode('/', trim($cidr), 2);

        $packed = @inet_pton(trim($subnet));

        if ($packed === false || !is_numeric($bits)) {
            return false;
        }

        $bits = intval($bits);

        if ($bits < 0 || $bits > strlen($packed) * 8) {
            return false;
        }

        return [$packed, $bits];
    }
}
