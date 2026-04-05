<?php
/**
 * Cron job: purge expired sessions.
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/db.php';

$config = getConfig();
$ttl = $config['app']['session_ttl'];
$db = DB::get();
$stmt = $db->prepare('DELETE FROM sessions WHERE last_activity < ?');
$stmt->execute([time() - $ttl]);
$count = $stmt->rowCount();
echo "Purged {$count} expired sessions.\n";
