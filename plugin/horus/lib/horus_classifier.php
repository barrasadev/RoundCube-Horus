<?php

/**
 * Horus :: open classifier.
 *
 * A tracking pixel cannot tell you that a human read your email. It tells you that
 * *something* fetched an image. This class decides which of the three honest answers
 * applies - confirmed, bot, or unknown - and records why.
 *
 * The bias is deliberately conservative: when a signal is ambiguous we return
 * 'unknown' rather than 'confirmed', because an inflated open rate is worse than an
 * incomplete one.
 *
 * @license GNU GPLv3+
 */
class horus_classifier
{
    /** Gmail's image cache. Google fetches on the recipient's real open, not before. */
    const RE_GOOGLE_PROXY = '~GoogleImageProxy|via ggpht\.com GoogleImageProxy~i';

    /**
     * Security gateways, link rewriters and crawlers that fetch every URL in a message
     * the moment it lands. None of these imply a human.
     */
    const RE_SCANNER = '~(
          BarracudaCentral | Barracuda | ProofPoint | Mimecast | MessageLabs | Symantec
        | TrendMicro | FireEye | Forcepoint | Sophos | McAfee | IronPort | Cisco
        | SafeLinks | ATPImageProxy | Microsoft\ Office\ Existence\ Discovery
        | YahooMailProxy | SkypeUriPreview | Slackbot | Discordbot | TelegramBot
        | WhatsApp | facebookexternalhit | Twitterbot | LinkedInBot
        | bot\b | crawler | spider | curl/ | wget | python-requests | Go-http-client
        | HeadlessChrome | PhantomJS | Scrapy | libwww-perl | Java/
    )~xi';

    /**
     * Mail clients and browsers that only fetch remote content when a person is
     * looking at the message.
     */
    const RE_REAL_CLIENT = '~(
          Thunderbird | Outlook-i?OS | Microsoft\ Outlook | MSOffice | Windows\ Mail
        | AppleWebKit.*Version.*Safari | Chrome/[0-9] | Firefox/[0-9] | Edg/[0-9]
        | Mobile/\w+\ Safari | Android.*Mobile | iPhone | iPad | Macintosh | Windows\ NT
        | Evolution | KMail | Claws\ Mail | Mutt | Roundcube | Zimbra | eM\ Client
    )~xi';

    /**
     * Apple's Mail Privacy Protection presents a stripped, version-less UA. A real
     * client string carries a product and version; this one does not.
     */
    const RE_GENERIC_UA = '~^Mozilla/5\.0\s*(\((Macintosh|Windows|X11)[^)]*\))?\s*$~i';

    /** @var horus_bots */
    private $bots;

    /** @var int Seconds after send within which a fetch is treated as a prefetch */
    private $prefetch_window;

    public function __construct(horus_bots $bots, array $settings = [])
    {
        $settings = $settings ?: horus_settings::get();

        $this->bots = $bots;
        $this->prefetch_window = intval($settings['horus_prefetch_window'] ?? 10);
    }

    /**
     * Classify a pixel request.
     *
     * @param array       $message  Row from horus_messages (needs sent_at)
     * @param string      $ua       User-Agent header
     * @param string      $ip       Remote address
     * @param string|null $hostname Reverse DNS name for $ip, when resolved
     *
     * @return array [status, reason]
     */
    public function classify(array $message, $ua, $ip, $hostname = null)
    {
        $ua      = (string) $ua;
        $elapsed = time() - (horus_store::ts($message['sent_at']) ?: time());

        // 0. The sender reading their own copy in Sent. This outranks every other
        //    rule: whatever the user agent says, it is not the recipient, and
        //    counting it is the most misleading thing this plugin could do.
        if ($reason = horus_selfview::detect($message, $ip)) {
            return [horus_store::STATUS_SELF, $reason];
        }

        // 1. Admin-declared bot ranges win outright - that is what they are for.
        if ($ip && $this->bots->is_blocked_range($ip)) {
            return [horus_store::STATUS_BOT, 'configured_range'];
        }

        // 2. Gmail's proxy is checked BEFORE the prefetch rule. Google does not
        //    pre-fetch: it caches the image when the recipient actually opens the
        //    message, so even a fetch two seconds after sending is a real open (you
        //    can trigger it yourself by mailing an address you have open). All it
        //    costs us is the recipient's IP, which Google replaces with its own.
        if (preg_match(self::RE_GOOGLE_PROXY, $ua)) {
            return [horus_store::STATUS_CONFIRMED, 'google_proxy'];
        }

        // 3. Anything that announces itself as a scanner or a link-preview bot.
        if (preg_match(self::RE_SCANNER, $ua)) {
            return [horus_store::STATUS_BOT, 'scanner_ua'];
        }

        // 4. The reverse DNS name. A PTR record is controlled by whoever owns the
        //    address block, so it is far harder to forge than a user agent - a host
        //    under proofpoint.com or barracuda.com is a gateway whatever it claims.
        if (horus_intel::hostname_is_bot($hostname)) {
            return [horus_store::STATUS_BOT, 'scanner_host'];
        }

        // 5. Nobody reads an email in under ten seconds of it being sent. A fetch this
        //    fast is the receiving gateway walking the message.
        if ($elapsed >= 0 && $elapsed < $this->prefetch_window) {
            return [horus_store::STATUS_BOT, 'prefetch'];
        }

        // 6. Apple Mail Privacy Protection. Apple fetches every remote image on
        //    delivery regardless of whether the message is ever opened, so an MPP hit
        //    carries no information about the human. It is identified by the pair of
        //    signals - Apple egress IP *and* a stripped UA - because either alone has
        //    honest false positives (a person browsing over Private Relay; a stripped
        //    UA from some other privacy proxy).
        if ($ip && $this->bots->is_apple($ip)) {
            if ($this->is_generic_ua($ua)) {
                return [horus_store::STATUS_BOT, 'apple_mpp'];
            }

            // Apple network but a real client string: probably a genuine reader behind
            // Private Relay. Not provable either way.
            return [horus_store::STATUS_UNKNOWN, 'apple_network'];
        }

        // 7. A recognisable mail client or browser fetching outside the prefetch
        //    window is the ordinary "someone opened it" case.
        if (preg_match(self::RE_REAL_CLIENT, $ua) && !$this->is_generic_ua($ua)) {
            return [horus_store::STATUS_CONFIRMED, 'real_client'];
        }

        // 8. A stripped UA from an unrecognised network. Common for privacy proxies.
        if ($this->is_generic_ua($ua)) {
            return [horus_store::STATUS_UNKNOWN, 'generic_ua'];
        }

        // 9. Out of signals. Say so rather than guessing.
        return [horus_store::STATUS_UNKNOWN, $ua === '' ? 'no_ua' : 'unrecognised'];
    }

    /**
     * A UA with no product/version tokens beyond the Mozilla preamble.
     */
    private function is_generic_ua($ua)
    {
        return $ua === '' || preg_match(self::RE_GENERIC_UA, trim($ua)) === 1;
    }

    /**
     * Human-readable label for a classification reason, for the UI and the logs.
     */
    public static function reason_label($reason)
    {
        $map = [
            'user_marked'      => 'horus.reasonusermarked',
            'self_session'     => 'horus.reasonselfsession',
            'self_referer'     => 'horus.reasonselfreferer',
            'self_ip'          => 'horus.reasonselfip',
            'configured_range' => 'horus.reasonconfigured',
            'google_proxy'     => 'horus.reasongoogle',
            'scanner_ua'       => 'horus.reasonscanner',
            'scanner_host'     => 'horus.reasonscannerhost',
            'prefetch'         => 'horus.reasonprefetch',
            'apple_mpp'        => 'horus.reasonapplempp',
            'apple_network'    => 'horus.reasonapplenet',
            'real_client'      => 'horus.reasonrealclient',
            'generic_ua'       => 'horus.reasongenericua',
            'unrecognised'     => 'horus.reasonunknown',
            'no_ua'            => 'horus.reasonnoua',
            'click_reinforced' => 'horus.reasonclickreinforced',
            'human_click'      => 'horus.reasonhumanclick',
            'immediate_click'  => 'horus.reasonimmediateclick',
            'doc_view'         => 'horus.reasondocview',
            'doc_download'     => 'horus.reasondocdownload',
        ];

        return $map[$reason] ?? null;
    }
}
