<?php

/**
 * Horus :: outgoing message rewriting.
 *
 * Builds the three things a tracked message carries: the 1x1 pixel, click-through
 * links, and the block that links to tracked attachments instead of attaching them.
 *
 * @license GNU GPLv3+
 */
class horus_injector
{
    /** Schemes we never rewrite - they are not http fetches. */
    const SKIP_SCHEMES = ['mailto:', 'tel:', 'sms:', 'callto:', 'cid:', 'data:', 'javascript:', 'ftp:'];

    /**
     * Query parameter the public endpoints answer on.
     *
     * Deliberately neutral. A recipient who hovers a link or reads the message source
     * should not be handed the name of the tracking plugin - `_horus=px` announces
     * exactly what is going on. Configurable so an installation can pick its own.
     */
    public static function param()
    {
        $name = (string) rcmail::get_instance()->config->get('horus_url_param', '_res');

        // Must be a Roundcube-style parameter name, or it would collide with real ones.
        return preg_match('/^_[a-z0-9_]{1,16}$/i', $name) ? $name : '_res';
    }

    /**
     * Absolute URL of the webmail entry point, ending in the script name.
     *
     * Behind a reverse proxy, or when a message is generated outside a normal web
     * request, $_SERVER cannot be trusted - that is what `horus_base_url` is for.
     */
    public static function base_url()
    {
        $rcmail = rcmail::get_instance();

        if ($configured = $rcmail->config->get('horus_base_url')) {
            return rtrim($configured, '/');
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

        return rcube_utils::resolve_url($script);
    }

    // ------------------------------------------------------------------- URLs

    public static function pixel_url($uuid)
    {
        return self::base_url() . '?' . self::param() . '=px&id=' . rawurlencode($uuid)
            . '&s=' . horus_signer::sign(['px', $uuid]);
    }

    public static function click_url($uuid, $target)
    {
        $encoded = horus_signer::b64_encode($target);

        return self::base_url() . '?' . self::param() . '=click&id=' . rawurlencode($uuid)
            . '&u=' . $encoded
            . '&s=' . horus_signer::sign(['click', $uuid, $target]);
    }

    /**
     * Both variants download the file; the flag survives because it is part of the
     * signature, and messages sent before the landing page was dropped carry `d=0`.
     *
     * @param bool $download Mode bit carried in the URL and covered by the signature
     */
    public static function doc_url($doc_uuid, $download = false)
    {
        $mode = $download ? '1' : '0';

        return self::base_url() . '?' . self::param() . '=doc&id=' . rawurlencode($doc_uuid)
            . '&d=' . $mode
            . '&s=' . horus_signer::sign(['doc', $doc_uuid, $mode]);
    }

    // -------------------------------------------------------------- rewriting

    /**
     * Apply all tracking transforms to an HTML body.
     *
     * Order matters: the document block is appended first so its links get rewritten
     * for click tracking too, and the pixel goes in last so it is never wrapped.
     *
     * @param string $html HTML body
     * @param string $uuid Message uuid
     * @param array  $docs Tracked attachment rows
     *
     * @return string
     */
    public static function process_html($html, $uuid, array $docs = [])
    {
        if ($docs) {
            $html = self::append_html($html, self::documents_block($docs));
        }

        $html = self::rewrite_links($html, $uuid);
        $html = self::append_html($html, self::pixel_tag($uuid));

        return $html;
    }

    public static function pixel_tag($uuid)
    {
        $url = htmlspecialchars(self::pixel_url($uuid), ENT_QUOTES, 'UTF-8');

        return '<img src="' . $url . '" width="1" height="1" alt="" '
            . 'style="display:none;width:1px;height:1px;border:0;outline:0" />';
    }

    /**
     * Rewrite every http(s) <a href> to the signed redirect endpoint.
     */
    public static function rewrite_links($html, $uuid)
    {
        return preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
            function ($m) use ($uuid) {
                // hrefs in HTML are entity-encoded; sign and store the real URL.
                $target = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (!self::is_trackable($target)) {
                    return $m[0];
                }

                $new = htmlspecialchars(self::click_url($uuid, $target), ENT_QUOTES, 'UTF-8');

                return $m[1] . $m[2] . $new . $m[2];
            },
            $html
        );
    }

    /**
     * Only absolute http(s) links are rewritten. Anchors, mailto:, inline data and -
     * importantly - our own tracking URLs are left alone so a forwarded message is
     * never double-wrapped.
     */
    private static function is_trackable($url)
    {
        $url = trim($url);

        if ($url === '' || $url[0] === '#') {
            return false;
        }

        $lower = strtolower($url);

        foreach (self::SKIP_SCHEMES as $scheme) {
            if (strpos($lower, $scheme) === 0) {
                return false;
            }
        }

        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }

        if (strpos($url, self::param() . '=') !== false || strpos($url, '_horus=') !== false) {
            return false;
        }

        return true;
    }

    /**
     * The "tracked attachments" block that replaces real attachments in the body.
     *
     * Styled with inline attributes only: email clients strip <style> blocks, and this
     * has to survive Gmail, Outlook and everything in between.
     */
    public static function documents_block(array $docs)
    {
        // The recipient must not be told anything about tracking. This heading is the
        // one piece of Horus wording that leaves the building, so it is neutral by
        // default and overridable per installation.
        $rcmail = rcmail::get_instance();
        $title  = rcube::Q($rcmail->config->get('horus_attachments_label')
            ?: $rcmail->gettext('horus.mailattachments'));

        $html = '<div style="margin:24px 0 0;padding:16px 18px;background:#f6f8fa;'
            . 'border:1px solid #e1e6eb;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,'
            . '\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . '<p style="margin:0 0 10px;font-weight:600;font-size:14px;color:#24292f;">' . $title . '</p>';

        $action = rcube::Q($rcmail->config->get('horus_download_label')
            ?: $rcmail->gettext('horus.maildownload'));

        foreach ($docs as $doc) {
            $url  = htmlspecialchars(self::doc_url($doc['uuid'], true), ENT_QUOTES, 'UTF-8');
            $name = rcube::Q($doc['filename']);
            $size = horus_storage::format_size($doc['size']);
            $kind = self::kind_label($doc['mimetype'], $doc['filename']);

            // A text badge rather than an icon: mail clients strip inline SVG, and an
            // emoji renders differently (or not at all) across Outlook, Gmail and
            // Apple Mail. Plain styled text is the only thing that looks the same
            // everywhere - which is also why the arrow below is a character and the
            // button is a one-cell table with bgcolor: Outlook ignores background and
            // border-radius on an <a>, but honours them on a <td>.
            // A <div>, not a <p>: a <table> is not allowed inside a paragraph, so the
            // parser would close it early and drop the button onto its own line.
            $html .= '<div style="margin:8px 0;font-size:14px;line-height:1.5;">'
                . '<span style="display:inline-block;min-width:38px;padding:2px 6px;margin-right:8px;'
                . 'background:#e7ecf1;border-radius:4px;color:#57606a;font-size:11px;font-weight:700;'
                . 'letter-spacing:.04em;text-align:center;">' . $kind . '</span>'
                . '<a href="' . $url . '" style="color:#0969da;text-decoration:none;font-weight:500;">'
                . $name . '</a>'
                . ' <span style="color:#8b949e;font-size:12px;">(' . $size . ')</span>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
                . ' style="display:inline-table;vertical-align:middle;margin-left:10px;"><tr>'
                . '<td bgcolor="#0969da" style="border-radius:6px;padding:5px 12px;">'
                . '<a href="' . $url . '" style="color:#ffffff;text-decoration:none;font-size:12px;'
                . 'font-weight:600;white-space:nowrap;">&#8595;&nbsp;' . $action . '</a>'
                . '</td></tr></table>'
                . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Plain-text counterpart of the document block. Without this, a recipient reading
     * the text/plain alternative would have no way to reach the files at all.
     */
    public static function documents_block_text(array $docs)
    {
        $rcmail = rcmail::get_instance();
        $out    = "\n\n" . ($rcmail->config->get('horus_attachments_label')
            ?: $rcmail->gettext('horus.mailattachments')) . ":\n";

        foreach ($docs as $doc) {
            $out .= '- ' . $doc['filename'] . ' (' . horus_storage::format_size($doc['size']) . ")\n"
                . '  ' . self::doc_url($doc['uuid'], true) . "\n";
        }

        return $out;
    }

    /**
     * Short uppercase file-kind label for the badge (PDF, DOCX, IMG...).
     */
    private static function kind_label($mimetype, $filename)
    {
        $ext = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext !== '' && strlen($ext) <= 4) {
            return rcube::Q($ext);
        }

        if (strpos($mimetype, 'pdf') !== false) {
            return 'PDF';
        }

        if (strpos($mimetype, 'image/') === 0) {
            return 'IMG';
        }

        return 'FILE';
    }

    /**
     * Insert content at the end of the document body, respecting </body> when present.
     */
    private static function append_html($html, $content)
    {
        if (preg_match('~</body\s*>~i', $html)) {
            return preg_replace('~</body\s*>~i', $content . '$0', $html, 1);
        }

        return $html . $content;
    }
}
