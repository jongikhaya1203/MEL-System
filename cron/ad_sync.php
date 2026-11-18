<?php
// Nightly Active Directory sync — refreshes every AD-linked account's name,
// email and (where it follows AD groups) role, and deactivates accounts that
// are disabled/deleted in AD or no longer in a mapped group (if that option
// is on under Directory Access). Schedule with Windows Task Scheduler, e.g.:
//   C:\xampp\php\php.exe C:\xampp\htdocs\mel_system\cron\ad_sync.php
// Exit code 0 = success (or AD sign-in switched off), 1 = sync failed.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!ad_is_enabled($pdo)) {
    echo 'Directory sign-in is switched off — nothing to sync.' . PHP_EOL;
    exit(0);
}
$s = ad_sync_users($pdo);
if (!$s['ok']) {
    fwrite(STDERR, 'AD sync failed: ' . $s['error'] . PHP_EOL);
    exit(1);
}
printf("AD sync %s — checked %d, updated %d, role changes %d, deactivated %d%s", date('Y-m-d H:i:s'),
    $s['checked'], $s['updated'], $s['role_changes'], $s['deactivated'], PHP_EOL);
