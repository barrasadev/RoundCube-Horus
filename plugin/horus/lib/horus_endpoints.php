<?php

/**
 * Horus :: public tracking endpoints.
 *
 * These run from the `startup` hook, i.e. before Roundcube authenticates anything.
 * The whole surface is three URLs and every one of them terminates the request itself.
 *
 * Security posture:
 *  - every parameter is HMAC-signed, so the redirect cannot be repointed and a
 *    document link cannot be edited from "view" into "download someone else's file";
 *  - a valid signature is still not authorisation: the uuid is looked up and must
 *    exist, and the redirect target is re-validated as http(s) before we send a 302;
 *  - responses never reveal whether a uuid exists (the pixel is always returned).
 *
 * @license GNU GPLv3+
 */
class horus_endpoints
{
    /** 1x1 fully transparent GIF. */
    const BLANK_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** @var horus_store */
    private $store;

    /** @var horus_storage */
    private $storage;

    public function __construct(horus_store $store, horus_storage $storage)
    {
        $this->store   = $store;
        $this->storage = $storage;
    }

    /**
     * Dispatch a `_horus=` request. Never returns.
     */
    public function dispatch($mode)
    {
        // A tracking hit must never leave a half-rendered Roundcube page behind it.
        while (ob_get_level()) {
            ob_end_clean();
        }

        switch ($mode) {
            case 'px':
                $this->handle_pixel();
                break;
            case 'click':
                $this->handle_click();
                break;
            case 'doc':
                $this->handle_document();
                break;
            default:
                $this->fail(404);
        }

        exit;
    }

    // ------------------------------------------------------------------ pixel

    private function handle_pixel()
    {
        $uuid = self::param('id');
        $sig  = self::param('s');

        // The image is returned no matter what. A tracking pixel that 404s for an
        // unknown id would let anyone probe which uuids are real.
        if (horus_signer::verify(['px', $uuid], $sig) && ($message = $this->store->get_message_by_uuid($uuid))) {
            // Classification honours the sender's own settings, which are not in
            // scope here (no session), so they are resolved from the message's owner.
            $settings   = horus_settings::get($message['user_id']);
            $intel      = (new horus_intel($this->store, $settings))->collect(self::remote_ip());
            $classifier = new horus_classifier(new horus_bots($this->store, $settings), $settings);

            list($status, $reason) = $classifier->classify(
                $message, $intel['user_agent'], $intel['ip'], $intel['hostname']
            );

            $this->store->record_open($message, $status, $reason, $intel);
        }

        $body = base64_decode(self::BLANK_GIF);

        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($body));
        $this->no_cache_headers();

        echo $body;
    }

    // ------------------------------------------------------------------ click

    private function handle_click()
    {
        $uuid   = self::param('id');
        $sig    = self::param('s');
        $target = horus_signer::b64_decode(self::param('u'));

        if ($target === false || !horus_signer::verify(['click', $uuid, $target], $sig)) {
            $this->fail(400, 'Invalid tracking link');
        }

        // Belt and braces. The signature proves we generated this URL, but the URL was
        // built from user-authored message content, so re-check the scheme before we
        // hand a browser a redirect to it.
        if (!preg_match('~^https?://~i', $target)) {
            $this->fail(400, 'Invalid tracking link');
        }

        if ($message = $this->store->get_message_by_uuid($uuid)) {
            $intel = (new horus_intel($this->store, horus_settings::get($message['user_id'])))
                ->collect(self::remote_ip());

            $this->store->record_click($message, $target, $intel);
        }

        $this->no_cache_headers();
        header('Location: ' . $target, true, 302);
        header('Referrer-Policy: no-referrer');
    }

    // -------------------------------------------------------------- documents

    private function handle_document()
    {
        $uuid     = self::param('id');
        $sig      = self::param('s');
        $mode     = self::param('d') === '1' ? '1' : '0';
        $download = $mode === '1';

        if (!horus_signer::verify(['doc', $uuid, $mode], $sig)) {
            $this->fail(400, 'Invalid document link');
        }

        $doc = $this->store->get_document_by_uuid($uuid);

        if (!$doc || empty($doc['message_id']) || !$this->storage->exists($doc['storage_key'])) {
            $this->fail(404, 'Document not available');
        }

        $message = $this->store->get_message($doc['message_id']);

        if ($message) {
            $intel = (new horus_intel($this->store, horus_settings::get($message['user_id'])))
                ->collect(self::remote_ip());

            $this->store->record_document_event(
                $doc, $message,
                $download ? horus_store::EVENT_DOC_DOWNLOAD : horus_store::EVENT_DOC_VIEW,
                $intel
            );
        }

        if ($download) {
            $this->stream_document($doc);
        }
        else {
            $this->render_document_page($doc);
        }
    }

    /**
     * Serve the file itself. This is the "downloaded" half of the view/download split.
     */
    private function stream_document(array $doc)
    {
        $path = $this->storage->path($doc['storage_key']);
        $name = horus_storage::sanitize_name($doc['filename']);

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . '"; '
            . "filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        $this->no_cache_headers();

        readfile($path);
    }

    /**
     * The landing page a recipient reaches from the message. Reaching it is what we
     * record as "viewed"; pressing the button records "downloaded".
     *
     * The file is never rendered inline here - not even images - because this page is
     * served from the webmail's own origin and inlining recipient-supplied content
     * there would be a stored-XSS surface.
     */
    private function render_document_page(array $doc)
    {
        $name = rcube::Q($doc['filename']);
        $size = horus_storage::format_size($doc['size']);
        $url  = rcube::Q(horus_injector::doc_url($doc['uuid'], true));

        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        $this->no_cache_headers();

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $name . '</title><style>'
            . 'body{margin:0;background:#f2f4f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2328}'
            . '.card{max-width:520px;margin:12vh auto;background:#fff;border:1px solid #e1e6eb;border-radius:12px;padding:40px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06)}'
            . '.mark{color:#0969da}'
            . 'h1{font-size:19px;margin:18px 0 6px;word-break:break-word}'
            . '.meta{color:#68727d;font-size:13px;margin:0 0 26px}'
            . 'a.btn{display:inline-flex;align-items:center;gap:8px;background:#0969da;color:#fff;'
            . 'text-decoration:none;padding:11px 26px;border-radius:8px;font-size:15px;font-weight:600}'
            . 'svg{vertical-align:middle}'
            . '@media(prefers-color-scheme:dark){body{background:#161b22;color:#e6edf3}'
            . '.card{background:#0d1117;border-color:#30363d}.meta{color:#8b949e}}'
            . '</style></head><body><div class="card">'
            . '<div class="mark">' . horus_icons::get('attachment', '', 34) . '</div>'
            . '<h1>' . $name . '</h1>'
            . '<p class="meta">' . $size . '</p>'
            . '<a class="btn" href="' . $url . '">' . horus_icons::get('downloaded', '', 16) . ' Download</a>'
            . '</div></body></html>';
    }

    // ----------------------------------------------------------------- helpers

    private static function param($name)
    {
        $value = $_GET[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function user_agent()
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    /**
     * Remote address, honouring X-Forwarded-For only for proxies the admin has
     * whitelisted (rcube_utils::remote_addr enforces `proxy_whitelist`).
     */
    private static function remote_ip()
    {
        $ip = rcube_utils::remote_addr();

        return $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function no_cache_headers()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }

    private function fail($code, $message = 'Not found')
    {
        header('Content-Type: text/plain; charset=utf-8', true, $code);
        echo $message;
        exit;
    }
}
