<?php

/**
 * Horus :: IP geolocation.
 *
 * This is the ONE part of Horus that talks to a third party, and it is off by
 * default. When enabled it sends an observed address to a lookup service, so it is
 * deliberately constrained:
 *
 *  - it never runs on the tracking endpoints. The pixel records the address and
 *    returns; geolocation is resolved later, from an authenticated page, when
 *    someone actually looks at the report. A recipient's open therefore costs no
 *    outbound call and no added latency;
 *  - results are cached per address, so an address is looked up once, not once per
 *    event;
 *  - private, loopback and reserved ranges are never sent anywhere.
 *
 * @license GNU GPLv3+
 */
class horus_geo
{
    /** How long a cached location stays good. */
    const CACHE_TTL = 30 * 86400;

    /** Most requests resolve a handful of addresses; this caps a pathological page. */
    const MAX_PER_REQUEST = 25;

    /** @var horus_store */
    private $store;

    /** @var array Effective settings */
    private $settings;

    /** @var int Lookups performed in this request */
    private $spent = 0;

    public function __construct(horus_store $store, array $settings = [])
    {
        $this->store    = $store;
        $this->settings = $settings ?: horus_settings::get();
    }

    public function enabled()
    {
        return !empty($this->settings['horus_geo_enabled']);
    }

    /**
     * Resolve a batch of addresses, returning what is known for each.
     *
     * @param array $ips
     *
     * @return array ip => ['city' =>, 'region' =>, 'country' =>, 'country_code' =>, 'org' =>]
     */
    public function locate_many(array $ips)
    {
        $out = [];

        foreach (array_unique(array_filter($ips)) as $ip) {
            if ($row = $this->locate($ip)) {
                $out[$ip] = $row;
            }
        }

        return $out;
    }

    /**
     * Location for one address, from cache when possible.
     *
     * @return array|null
     */
    public function locate($ip)
    {
        if (!self::is_public($ip)) {
            return null;
        }

        $cached = $this->store->get_ipinfo($ip);

        if ($cached && !empty($cached['geo_at'])
            && (time() - horus_store::ts($cached['geo_at'])) < self::CACHE_TTL
        ) {
            return self::shape($cached);
        }

        if (!$this->enabled() || $this->spent >= self::MAX_PER_REQUEST) {
            return $cached ? self::shape($cached) : null;
        }

        $this->spent++;

        $data = $this->fetch($ip);

        // Cache negatives too: an address the service cannot place should not be
        // asked about again on every page view.
        $this->store->put_geo($ip, $data ?: []);

        return $data ? self::shape($data) : null;
    }

    private static function shape($row)
    {
        $out = [
            'city'         => $row['city'] ?? null,
            'region'       => $row['region'] ?? null,
            'country'      => $row['country'] ?? null,
            'country_code' => $row['country_code'] ?? null,
            'org'          => $row['org'] ?? null,
        ];

        return array_filter($out) ? $out : null;
    }

    /**
     * Query the configured provider.
     */
    private function fetch($ip)
    {
        $timeout = intval($this->settings['horus_geo_timeout'] ?? 4);
        $url     = 'http://ip-api.com/json/' . rawurlencode($ip)
            . '?fields=status,country,countryCode,regionName,city,org,isp';

        $body = self::http_get($url, $timeout);

        if (!$body) {
            return null;
        }

        $data = json_decode($body, true);

        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        return [
            'city'         => self::clean($data['city'] ?? null, 64),
            'region'       => self::clean($data['regionName'] ?? null, 64),
            'country'      => self::clean($data['country'] ?? null, 64),
            'country_code' => self::clean($data['countryCode'] ?? null, 2),
            'org'          => self::clean(($data['org'] ?? '') ?: ($data['isp'] ?? ''), 128),
        ];
    }

    private static function clean($value, $max)
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /**
     * Public, routable addresses only. Anything private, loopback, link-local or
     * reserved is never handed to a third party - and could not be located anyway.
     */
    public static function is_public($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function http_get($url, $timeout)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT      => 'Roundcube-Horus/1.0',
            ]);

            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ($body !== false && $code === 200) ? $body : null;
        }

        $ctx  = stream_context_create(['http' => ['timeout' => $timeout]]);
        $body = @file_get_contents($url, false, $ctx);

        return $body !== false ? $body : null;
    }
}
