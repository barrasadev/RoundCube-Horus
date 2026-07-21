<?php

/**
 * Horus :: HMAC signing for public tracking URLs.
 *
 * Every parameter that reaches an unauthenticated endpoint is signed, so the tracking
 * redirect can never be turned into an open redirect and a document link cannot be
 * edited into "download someone else's file" or "flip view into download".
 *
 * @license GNU GPLv3+
 */
class horus_signer
{
    /** Truncated HMAC length. 128 bits is far beyond what a URL forger can brute-force. */
    const SIG_LENGTH = 32;

    /** @var string|null Memoised secret */
    private static $secret;

    /**
     * Sign an ordered list of URL components.
     *
     * @param array $parts Components; order matters and is part of the signature
     *
     * @return string Hex signature
     */
    public static function sign(array $parts)
    {
        return substr(hash_hmac('sha256', self::canonical($parts), self::secret()), 0, self::SIG_LENGTH);
    }

    /**
     * Constant-time signature check.
     *
     * @param array  $parts     The same components used when signing
     * @param string $signature Signature received from the request
     *
     * @return bool
     */
    public static function verify(array $parts, $signature)
    {
        if (!is_string($signature) || strlen($signature) !== self::SIG_LENGTH) {
            return false;
        }

        return hash_equals(self::sign($parts), $signature);
    }

    /**
     * URL-safe base64, so a full URL survives being carried inside a query string
     * without a second layer of percent-encoding ambiguity.
     */
    public static function b64_encode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function b64_decode($value)
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9\-_]*$/', $value)) {
            return false;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? false : $decoded;
    }

    /**
     * Length-prefixed join. Without this, sign(['a','bc']) and sign(['ab','c']) would
     * collide and a crafted uuid could borrow another link's signature.
     */
    private static function canonical(array $parts)
    {
        $out = '';

        foreach ($parts as $part) {
            $part = (string) $part;
            $out .= strlen($part) . ':' . $part . '|';
        }

        return $out;
    }

    /**
     * Signing key. Prefers an explicit `horus_secret`; otherwise it is derived from
     * the instance's des_key, which every Roundcube install already keeps secret.
     */
    private static function secret()
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        $rcmail = rcmail::get_instance();
        $secret = $rcmail->config->get('horus_secret');

        if (empty($secret)) {
            $secret = hash('sha256', 'horus/v1/' . $rcmail->config->get('des_key', ''));
        }

        return self::$secret = $secret;
    }
}
