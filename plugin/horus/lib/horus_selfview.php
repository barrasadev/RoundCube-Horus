<?php

/**
 * Horus :: self-open detection.
 *
 * Without this, the single largest source of false "opens" is the sender: you send a
 * tracked message, then open your own copy in the Sent folder, your webmail loads the
 * pixel, and Horus cheerfully reports that the recipient read it.
 *
 * Three independent signals, strongest first. Any one of them is enough - they are all
 * things a recipient's mail client cannot plausibly produce.
 *
 * @license GNU GPLv3+
 */
class horus_selfview
{
    /**
     * How long after sending a same-IP fetch is still treated as the sender. Beyond
     * this the sender has probably moved networks and a match means less.
     */
    const SENDER_IP_WINDOW = 7 * 86400;

    /**
     * Decide whether this tracking hit is the sender looking at their own message.
     *
     * @param array  $message Row from horus_messages
     * @param string $ip      Client address
     *
     * @return string|null Reason when it is a self view, null otherwise
     */
    public static function detect(array $message, $ip)
    {
        // 1. A Roundcube session cookie. A recipient's mail client has never
        //    authenticated against this webmail, so one can only come from a browser
        //    logged into this very install.
        //
        //    Crucially, that is not the same as "the sender". On a server where both
        //    correspondents have accounts, the *recipient* reading the message in this
        //    same webmail also sends a session cookie - and discarding that would throw
        //    away a genuine open. So the session is resolved to a user id and only
        //    counts as a self view when it is the account that sent the message.
        if ($sid = self::session_cookie()) {
            $owner = self::session_user($sid);

            if ($owner !== null) {
                return $owner == $message['user_id'] ? 'self_session' : null;
            }

            // Unresolvable session store (memcache, redis, php): fall back to the
            // conservative reading rather than guessing.
            return 'self_session';
        }

        // 2. A Referer (or Origin) pointing at this webmail. Roundcube's message view
        //    is what is rendering the pixel; no external mail client refers from here.
        //    Only reachable without a session cookie, so it is not the case above.
        if (self::referred_from_self()) {
            return 'self_referer';
        }

        // 3. The same address the message was sent from, soon after sending. Weaker
        //    than the other two - the sender and recipient could share an office NAT -
        //    so it is the last resort and can be switched off.
        if ($ip && !empty($message['sender_ip']) && $ip === $message['sender_ip']) {
            $elapsed = time() - (horus_store::ts($message['sent_at']) ?: 0);

            if ($elapsed >= 0 && $elapsed < self::SENDER_IP_WINDOW) {
                return 'self_ip';
            }
        }

        return null;
    }

    /**
     * The Roundcube session id riding along with this request, if any.
     */
    private static function session_cookie()
    {
        $names = [session_name() ?: 'roundcube_sessid', 'roundcube_sessid'];

        foreach ($names as $name) {
            if (!empty($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
                return $_COOKIE[$name];
            }
        }

        return null;
    }

    /**
     * Which Roundcube user does a session id belong to?
     *
     * Only the database session store can be read from here - which is Roundcube's
     * default. Other stores return null and the caller falls back.
     *
     * @return int|null User id, or null if it cannot be determined
     */
    private static function session_user($sid)
    {
        // Session ids are hex; anything else is not worth a query.
        if (!preg_match('/^[a-zA-Z0-9,\-]{8,128}$/', $sid)) {
            return null;
        }

        $rcmail = rcmail::get_instance();

        if ($rcmail->config->get('session_storage', 'db') !== 'db') {
            return null;
        }

        $db = $rcmail->get_dbh();

        if (!in_array($db->table_name('session'), (array) $db->list_tables())) {
            return null;
        }

        $sql = $db->query('SELECT `vars` FROM ' . $db->table_name('session', true) . ' WHERE `sess_id` = ?', $sid);
        $row = $db->fetch_assoc($sql);

        if (empty($row['vars'])) {
            return null;
        }

        // PHP's session serialisation: "key|<serialized value>" pairs, base64 encoded
        // by Roundcube. Reading the one scalar we need out of it beats unserialising
        // the whole session on a hot path.
        $vars = base64_decode($row['vars'], true);

        if ($vars === false || !preg_match('/user_id\|i:(\d+);/', $vars, $m)) {
            return null;
        }

        return intval($m[1]);
    }

    /**
     * Did the request come from a page on this same host?
     */
    private static function referred_from_self()
    {
        // Chromium sends this and it is unambiguous.
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') {
            return true;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        if ($referer === '') {
            return false;
        }

        $ref_host = parse_url($referer, PHP_URL_HOST);

        if (!$ref_host) {
            return false;
        }

        $own_host = parse_url(horus_injector::base_url(), PHP_URL_HOST)
            ?: ($_SERVER['HTTP_HOST'] ?? '');

        // Compare hosts only: scheme and port vary behind proxies.
        return strcasecmp($ref_host, preg_replace('/:\d+$/', '', (string) $own_host)) === 0;
    }
}
