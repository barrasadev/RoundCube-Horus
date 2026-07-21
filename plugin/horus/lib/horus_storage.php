<?php

/**
 * Horus :: on-disk storage for tracked attachments.
 *
 * Tracked attachments are not attached to the message; they are held here and served
 * through a signed endpoint. Two rules keep that safe:
 *
 *  - files are stored under an opaque 64-hex name with no extension, so even if the
 *    directory were somehow reachable by a web server it could not execute anything;
 *  - the directory is created with a deny-all .htaccess and should live outside the
 *    document root (see `horus_storage_dir` in config.inc.php.dist).
 *
 * @license GNU GPLv3+
 */
class horus_storage
{
    /** Extensions we never accept, whatever the browser claims the MIME type is. */
    const BLOCKED_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'phtml', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'scr',
        'jsp', 'asp', 'aspx', 'htaccess',
    ];

    /** @var string Absolute path to the storage root */
    private $dir;

    public function __construct()
    {
        $rcmail = rcmail::get_instance();

        $dir = $rcmail->config->get('horus_storage_dir');

        if (empty($dir)) {
            $dir = rtrim($rcmail->config->get('temp_dir', sys_get_temp_dir()), '/') . '/horus-docs';
        }

        $this->dir = rtrim($dir, '/');
    }

    public function dir()
    {
        return $this->dir;
    }

    /**
     * Create the storage root if needed, with a deny-all guard.
     */
    public function ensure_dir()
    {
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                rcube::raise_error([
                        'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Horus: cannot create storage directory {$this->dir}"
                    ], true, false);
                return false;
            }

            @file_put_contents($this->dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($this->dir . '/index.html', '');
        }

        // Checked every time, not just at creation: a directory that exists but is
        // owned by the wrong user is the single most likely misconfiguration here,
        // and it otherwise surfaces as an unexplained "upload failed".
        if (!is_writable($this->dir)) {
            rcube::raise_error([
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Horus: storage directory {$this->dir} is not writable by the web server user"
                ], true, false);
            return false;
        }

        return true;
    }

    /**
     * Validate and store an uploaded file.
     *
     * @param array $upload Entry from $_FILES
     *
     * @return array|string File descriptor, or an error message key
     */
    public function store_upload(array $upload)
    {
        $rcmail   = rcmail::get_instance();
        $max_size = self::parse_size($rcmail->config->get('horus_max_doc_size', '25M'));

        if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            return 'horus.uploaderror';
        }

        if ($upload['size'] <= 0 || $upload['size'] > $max_size) {
            return 'horus.uploadtoolarge';
        }

        $name = self::sanitize_name($upload['name']);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === '' || in_array($ext, self::BLOCKED_EXT, true)) {
            return 'horus.uploadblockedtype';
        }

        if (!$this->ensure_dir()) {
            return 'horus.uploadnotwritable';
        }

        $key  = bin2hex(random_bytes(32));
        $path = $this->path($key);

        if (!@move_uploaded_file($upload['tmp_name'], $path)) {
            return 'horus.uploaderror';
        }

        @chmod($path, 0600);

        return [
            'name'        => $name,
            'mimetype'    => self::detect_mimetype($path, $name, $upload['type'] ?? null),
            'size'        => filesize($path),
            'storage_key' => $key,
        ];
    }

    public function path($key)
    {
        // Defence in depth: the key comes from our own database, but a traversal here
        // would be catastrophic, so reject anything that is not pure hex.
        if (!preg_match('/^[0-9a-f]{64}$/', (string) $key)) {
            return null;
        }

        return $this->dir . '/' . $key;
    }

    public function exists($key)
    {
        $path = $this->path($key);

        return $path && is_file($path);
    }

    public function delete($key)
    {
        $path = $this->path($key);

        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Strip directory components and anything that would confuse a Content-Disposition
     * header, while keeping the name recognisable to the recipient.
     */
    public static function sanitize_name($name)
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1f\x7f"\r\n]/', '', $name);
        $name = trim($name, ". \t");

        if ($name === '') {
            $name = 'document';
        }

        return mb_substr($name, 0, 200);
    }

    /**
     * Trust the file's own magic bytes over the browser-supplied type.
     */
    private static function detect_mimetype($path, $name, $fallback = null)
    {
        if (class_exists('rcube_mime')) {
            $type = rcube_mime::file_content_type($path, $name, $fallback ?: 'application/octet-stream');

            if ($type) {
                return $type;
            }
        }

        return $fallback ?: 'application/octet-stream';
    }

    public static function parse_size($value)
    {
        if (is_numeric($value)) {
            return intval($value);
        }

        $unit = strtoupper(substr(trim((string) $value), -1));
        $num  = floatval($value);

        switch ($unit) {
            case 'G': return (int) ($num * 1024 * 1024 * 1024);
            case 'M': return (int) ($num * 1024 * 1024);
            case 'K': return (int) ($num * 1024);
            default:  return (int) $num;
        }
    }

    public static function format_size($bytes)
    {
        $bytes = intval($bytes);

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }
}
