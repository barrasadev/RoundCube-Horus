<?php

/**
 * Horus :: request forensics.
 *
 * Everything knowable about the client behind a tracking hit: the address and its
 * reverse DNS name, the parsed user agent, the language it asked for, the referrer,
 * the proxy chain, and the raw headers as a fallback for anything not modelled here.
 *
 * The reverse lookup is the expensive part. A PTR query is a blocking network call on
 * a request that has to return a 1x1 GIF quickly, so results are cached per address -
 * including negative results, otherwise every hit from a PTR-less address would pay
 * the full DNS timeout again.
 *
 * @license GNU GPLv3+
 */
class horus_intel
{
    /** How long a cached PTR result stays good. */
    const CACHE_TTL = 604800; // 7 days

    /** Request headers worth keeping verbatim. */
    const KEEP_HEADERS = [
        'HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_CONNECTION',
        'HTTP_DNT', 'HTTP_SEC_GPC', 'HTTP_FROM', 'HTTP_VIA', 'HTTP_X_FORWARDED_PROTO',
        'HTTP_SEC_FETCH_DEST', 'HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_SITE',
        'HTTP_SEC_CH_UA', 'HTTP_SEC_CH_UA_MOBILE', 'HTTP_SEC_CH_UA_PLATFORM',
    ];

    /** @var horus_store */
    private $store;

    /** @var bool */
    private $reverse_dns;

    public function __construct(horus_store $store, array $settings = [])
    {
        $this->store       = $store;
        $this->reverse_dns = !isset($settings['horus_reverse_dns']) || $settings['horus_reverse_dns'];
    }

    /**
     * Collect everything about the current request.
     *
     * @param string $ip Already-resolved client address
     *
     * @return array Columns for horus_events
     */
    public function collect($ip)
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $data = [
            'ip'         => $ip ?: null,
            'ip_version' => $ip ? (strpos($ip, ':') !== false ? 6 : 4) : null,
            'hostname'   => $ip ? $this->reverse_lookup($ip) : null,
            'user_agent' => $ua !== '' ? mb_substr($ua, 0, 500) : null,
            'language'   => self::header('HTTP_ACCEPT_LANGUAGE', 64),
            'referer'    => self::header('HTTP_REFERER', 500),
            'forwarded'  => self::header('HTTP_X_FORWARDED_FOR', 250),
            'headers'    => self::extra_headers(),
        ];

        return $data + self::parse_ua($ua);
    }

    private static function header($key, $max)
    {
        $value = $_SERVER[$key] ?? '';

        return $value !== '' ? mb_substr((string) $value, 0, $max) : null;
    }

    private static function extra_headers()
    {
        $out = [];

        foreach (self::KEEP_HEADERS as $key) {
            if (!empty($_SERVER[$key])) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $out[$name] = mb_substr((string) $_SERVER[$key], 0, 200);
            }
        }

        return $out ? json_encode($out) : null;
    }

    // ------------------------------------------------------------ reverse DNS

    /**
     * PTR record for an address, or null. Cached in `horus_ipinfo`.
     */
    public function reverse_lookup($ip)
    {
        if (!$this->reverse_dns || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $cached = $this->store->get_ipinfo($ip);

        if ($cached && (time() - horus_store::ts($cached['resolved_at'])) < self::CACHE_TTL) {
            return $cached['hostname'] ?: null;
        }

        // gethostbyaddr() returns the input unchanged when there is no PTR record.
        $host = @gethostbyaddr($ip);
        $host = ($host && $host !== $ip) ? mb_substr($host, 0, 250) : null;

        // Cached either way - a miss is worth remembering so the next hit from this
        // address does not block on DNS again.
        $this->store->put_ipinfo($ip, $host);

        return $host;
    }

    // --------------------------------------------------------- user agent

    /** Ordered: the first match wins, so specific products precede generic engines. */
    const CLIENTS = [
        'Thunderbird'      => '~Thunderbird/([\d.]+)~i',
        'Outlook'          => '~Microsoft Outlook(?: [\d.]+)?|Outlook-(?:iOS|Android)~i',
        'Apple Mail'       => '~\bMail/([\d.]+)~i',
        'Gmail proxy'      => '~GoogleImageProxy~i',
        'Yahoo proxy'      => '~YahooMailProxy~i',
        'Edge'             => '~Edg(?:e|A|iOS)?/([\d.]+)~i',
        'Samsung Browser'  => '~SamsungBrowser/([\d.]+)~i',
        'Opera'            => '~OPR/([\d.]+)|Opera/([\d.]+)~i',
        'Chrome'           => '~(?:Chrome|CriOS)/([\d.]+)~i',
        'Firefox'          => '~(?:Firefox|FxiOS)/([\d.]+)~i',
        'Safari'           => '~Version/([\d.]+).*Safari~i',
        'Evolution'        => '~Evolution/?([\d.]*)~i',
        'Zimbra'           => '~Zimbra~i',
        'eM Client'        => '~eM Client/?([\d.]*)~i',
        'curl'             => '~curl/([\d.]+)~i',
        'wget'             => '~Wget/([\d.]+)~i',
    ];

    const OSES = [
        'iOS'       => '~(?:iPhone|iPad|iPod).*?OS (\d+[_\d]*)|OS (\d+[_\d]*) like Mac~i',
        'macOS'     => '~Mac OS X (\d+[_\.\d]*)|Macintosh~i',
        'Android'   => '~Android (\d+[\.\d]*)~i',
        'Windows'   => '~Windows NT ([\d.]+)|Windows~i',
        'Linux'     => '~Linux|X11~i',
        'FreeBSD'   => '~FreeBSD~i',
    ];

    /**
     * Best-effort client/OS/device breakdown.
     *
     * Deliberately small: user-agent parsing is unbounded work, and the classifier
     * does not depend on this - it is for the human reading the timeline.
     */
    public static function parse_ua($ua)
    {
        $out = ['client' => null, 'client_ver' => null, 'os' => null, 'device' => null];

        if ($ua === '') {
            return $out;
        }

        foreach (self::CLIENTS as $name => $re) {
            if (preg_match($re, $ua, $m)) {
                $out['client']     = $name;
                $out['client_ver'] = isset($m[1]) && $m[1] !== '' ? substr($m[1], 0, 30) : null;
                break;
            }
        }

        foreach (self::OSES as $name => $re) {
            if (preg_match($re, $ua, $m)) {
                $version = '';
                for ($i = 1; $i < count($m); $i++) {
                    if (!empty($m[$i])) { $version = str_replace('_', '.', $m[$i]); break; }
                }
                $out['os'] = $version !== '' ? substr("$name $version", 0, 60) : $name;
                break;
            }
        }

        if (preg_match('~iPad|Tablet~i', $ua)) {
            $out['device'] = 'tablet';
        }
        else if (preg_match('~Mobi|iPhone|Android.*Mobile|Windows Phone~i', $ua)) {
            $out['device'] = 'mobile';
        }
        else if (preg_match('~bot|crawler|spider|proxy|scanner|curl/|Wget/~i', $ua)) {
            $out['device'] = 'bot';
        }
        else if ($out['client'] !== null || preg_match('~Windows|Macintosh|X11|Linux~i', $ua)) {
            $out['device'] = 'desktop';
        }

        return $out;
    }

    /**
     * Hostnames that give away an automated fetcher regardless of the user agent.
     * Used as an extra classifier signal - a PTR is much harder to spoof than a UA.
     */
    const HOSTNAME_BOTS = '~(
          \.googlebot\.com | crawl[-.] | \.search\.msn\.com | \.applebot\.apple\.com
        | spider | scanner | \.proofpoint\.com | \.barracuda(?:networks)?\.com
        | \.mimecast\.com | \.messagelabs\.com | \.trendmicro\.com | \.forcepoint\.com
        | \.symantec\.com | \.sophos\.com | \.ironport\.com | \.protection\.outlook\.com
    )~xi';

    const HOSTNAME_MAIL_PROXY = '~(
          \.google\.com | \.googleusercontent\.com | \.icloud\.com | \.apple\.com
        | \.yahoo\.com | \.outlook\.com
    )$~xi';

    public static function hostname_is_bot($hostname)
    {
        return $hostname && preg_match(self::HOSTNAME_BOTS, $hostname) === 1;
    }
}
