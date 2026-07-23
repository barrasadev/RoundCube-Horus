#!/usr/bin/env php
<?php

/**
 * Horus :: scheduled-message sender.
 *
 * Run once a minute from cron. It picks up every message whose send time has passed,
 * delivers it through SMTP exactly as an interactive send would, files a copy in the
 * user's Sent folder, and records the tracking row — the same flow a live send takes,
 * only started from disk instead of from a compose form.
 *
 *   * * * * * /usr/bin/php /path/to/plugins/horus/bin/horus_cron.php >/dev/null 2>&1
 *
 * No web session exists here, so the two things a session normally provides are carried
 * on each scheduled row instead: the envelope recipients, and the user's IMAP credential
 * (encrypted by Roundcube with des_key at schedule time, decrypted here only to send and
 * to file the Sent copy, then wiped). SMTP and IMAP both authenticate as the user, so
 * the message is indistinguishable from one they sent by hand.
 *
 * @license GNU GPLv3+
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "horus_cron.php must be run from the command line\n");
    exit(1);
}

// plugins/horus/bin -> plugins/horus -> plugins -> <roundcube root>
$home = realpath(__DIR__ . '/..');
define('INSTALL_PATH', realpath(__DIR__ . '/../../../') . '/');

require_once INSTALL_PATH . 'program/include/clisetup.php';

require_once $home . '/lib/horus_db.php';
require_once $home . '/lib/horus_store.php';
require_once $home . '/lib/horus_storage.php';

$rcmail = rcmail::get_instance();

// One tick at a time: if a minute's batch runs long, the next cron invocation must not
// start delivering the same rows in parallel.
$lock = fopen(sys_get_temp_dir() . '/horus_cron.lock', 'c');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

if (!horus_db::ensure_schema($home)) {
    fwrite(STDERR, "horus_cron: schema not ready\n");
    exit(1);
}

$store   = new horus_store();
$storage = new horus_storage();
$now     = horus_store::now();

$sent = 0;
$failed = 0;

foreach ($store->due_scheduled($now, 50) as $row) {
    $raw = $storage->read($row['storage_key']);

    if ($raw === null) {
        $store->mark_scheduled_attempt($row['scheduled_id'], 'stored message body is missing');
        $failed++;
        continue;
    }

    // Split the frozen message back into its header block and body on the first blank
    // line, the two halves the SMTP layer expects (mirrors horus::deliver_one()).
    $split = strpos($raw, "\r\n\r\n");

    if ($split === false) {
        $store->mark_scheduled_attempt($row['scheduled_id'], 'stored message is malformed');
        $failed++;
        continue;
    }

    $headers   = substr($raw, 0, $split);
    $body      = substr($raw, $split + 4);
    $envelope  = array_values(array_filter(array_map('trim', explode(',', (string) $row['envelope_to']))));
    $from      = $row['from_addr'];

    if (!$envelope) {
        $store->mark_scheduled_attempt($row['scheduled_id'], 'no recipients');
        $failed++;
        continue;
    }

    // Authenticate SMTP as the user, using the credential frozen with the message.
    // The credentials are set literally rather than through the %u/%p session
    // placeholders: there is no web session here, so an unauthenticated send would be
    // rejected (and, from a residential IP, land on a blocklist). A fresh smtp_init per
    // row re-authenticates cleanly when consecutive rows belong to different users.
    $pass = $rcmail->decrypt($row['imap_pass_enc']);

    $rcmail->config->set('smtp_user', $row['imap_user']);
    $rcmail->config->set('smtp_pass', $pass);
    $rcmail->smtp = null;
    $rcmail->smtp_init(true);
    $ok = $rcmail->smtp->send_mail($from, $envelope, $headers, $body);

    if (!$ok) {
        $err = $rcmail->smtp->get_error();
        $store->mark_scheduled_attempt($row['scheduled_id'],
            'SMTP: ' . (is_array($err) ? ($err['label'] ?? json_encode($err)) : (string) $err));
        $failed++;
        continue;
    }

    // Delivered. From here nothing may re-send: a failure to file the Sent copy or to
    // record tracking is logged, not retried.
    $file_error = null;

    // Tracking record, now that the message has really gone out.
    $recipients = array_values(array_filter(array_map('trim', explode(',', (string) $row['recipients']))));

    $message_id = $store->create_message(intval($row['user_id']), [
        'uuid'       => $row['uuid'],
        'subject'    => $row['subject'],
        'recipients' => $recipients ?: $envelope,
        'from_addr'  => $from,
        'tracked'    => !empty($row['tracked']),
    ]);

    if ($message_id && !empty($row['compose_id'])) {
        $store->attach_documents_to_message(intval($row['user_id']), $row['compose_id'], $message_id);
    }

    // File a copy in the user's Sent folder, authenticating IMAP as the user.
    if (!empty($row['imap_host']) && !empty($row['sent_mbox'])) {
        try {
            $imap = new rcube_imap(null);
            $ssl  = $row['imap_ssl'] ?: null;
            $port = intval($row['imap_port']) ?: ($ssl === 'ssl' ? 993 : 143);

            if ($imap->connect($row['imap_host'], $row['imap_user'], $pass, $port, $ssl)) {
                $imap->save_message($row['sent_mbox'], $raw, '', false, ['SEEN']);
                $imap->close();
            }
            else {
                $file_error = 'IMAP connect failed';
            }
        }
        catch (Exception $e) {
            $file_error = 'IMAP: ' . $e->getMessage();
        }
    }

    // Mark sent; if only the Sent-copy filing failed, note it without re-sending.
    $store->mark_scheduled_sent($row['scheduled_id'], $message_id ?: null, $file_error);

    $storage->delete($row['storage_key']);
    $sent++;
}

flock($lock, LOCK_UN);
fclose($lock);

if ($sent || $failed) {
    fwrite(STDOUT, sprintf("horus_cron: %d sent, %d failed\n", $sent, $failed));
}

exit(0);
