<?php
// Optional: run this periodically (e.g. via Windows Task Scheduler calling
// C:\xampp\php\php.exe C:\xampp\htdocs\mel_system\cron\flag_overdue.php)
// if you want overdue flagging to happen even when nobody is browsing the
// register. The app also performs this same check on every page load of
// the dashboard and register, so this script is not required for correctness.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

sync_overdue_statuses($pdo);
echo "Overdue statuses synced at " . date('Y-m-d H:i:s') . PHP_EOL;
