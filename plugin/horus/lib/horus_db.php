<?php

/**
 * Horus :: self-applying database schema.
 *
 * Roundcube ships schema updates for plugins through bin/updatedb.sh, which means an
 * admin has to remember to run it. Horus is meant to be plug-and-play, so it applies
 * its own migrations at runtime using the exact same bookkeeping core uses: a
 * `<package>-version` row in the `system` table plus numbered SQL files under SQL/.
 *
 * @license GNU GPLv3+
 */
class horus_db
{
    /** Bump this and drop a matching SQL/<driver>/<version>.sql when the schema changes. */
    const SCHEMA_VERSION = 2026072105;

    const PACKAGE = 'horus';

    /** @var bool Guard so a single request never checks the schema twice. */
    private static $checked = false;

    /**
     * Make sure the schema is at SCHEMA_VERSION, applying any missing migrations.
     *
     * Cheap to call: after the first successful check the result is memoised in the
     * session, so steady-state requests do not touch the database at all.
     *
     * @param string $home Plugin directory (where SQL/ lives)
     *
     * @return bool True if the schema is usable
     */
    public static function ensure_schema($home)
    {
        if (self::$checked) {
            return true;
        }

        self::$checked = true;

        if (isset($_SESSION['horus_schema']) && $_SESSION['horus_schema'] == self::SCHEMA_VERSION) {
            return true;
        }

        $rcmail  = rcmail::get_instance();
        $db      = $rcmail->get_dbh();
        $current = self::current_version($db);

        if ($current >= self::SCHEMA_VERSION) {
            $_SESSION['horus_schema'] = self::SCHEMA_VERSION;
            return true;
        }

        $dir = $home . '/SQL/' . self::sql_dir($db);

        if (!is_dir($dir)) {
            rcube::raise_error([
                    'code' => 601, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Horus: no SQL schema for database driver '{$db->db_provider}'"
                ], true, false);
            return false;
        }

        foreach (self::pending_files($dir, $current) as $version => $file) {
            if (!$db->exec_script(file_get_contents($file))) {
                rcube::raise_error([
                        'code' => 601, 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Horus: failed applying schema $version: " . $db->is_error()
                    ], true, false);
                return false;
            }

            self::set_version($db, $version);
        }

        $_SESSION['horus_schema'] = self::SCHEMA_VERSION;

        return true;
    }

    /**
     * Read the currently installed schema version (0 when Horus has never run here).
     */
    private static function current_version($db)
    {
        // The `system` table only exists on Roundcube >= 0.9; every supported release has it.
        if (!in_array($db->table_name('system'), (array) $db->list_tables())) {
            return 0;
        }

        $sql = $db->query('SELECT `value` FROM ' . $db->table_name('system', true)
            . ' WHERE `name` = ?', self::PACKAGE . '-version');

        $row = $db->fetch_assoc($sql);

        return $row ? intval($row['value']) : 0;
    }

    private static function set_version($db, $version)
    {
        $table = $db->table_name('system', true);

        $db->query("UPDATE $table SET `value` = ? WHERE `name` = ?", $version, self::PACKAGE . '-version');

        if (!$db->affected_rows()) {
            $db->query("INSERT INTO $table (`name`, `value`) VALUES (?, ?)", self::PACKAGE . '-version', $version);
        }
    }

    /**
     * Migration files newer than $current, oldest first.
     *
     * @return array version => absolute path
     */
    private static function pending_files($dir, $current)
    {
        $files = [];

        foreach (glob($dir . '/*.sql') as $file) {
            $version = intval(pathinfo($file, PATHINFO_FILENAME));

            if ($version > $current && $version <= self::SCHEMA_VERSION) {
                $files[$version] = $file;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Map the Roundcube DB driver onto one of our SQL/ directories.
     */
    private static function sql_dir($db)
    {
        switch ($db->db_provider) {
            case 'mysql':
            case 'mysqli':
                return 'mysql';
            case 'postgres':
            case 'pgsql':
                return 'postgres';
            case 'sqlite':
                return 'sqlite';
            default:
                return $db->db_provider;
        }
    }
}
