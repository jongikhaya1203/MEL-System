<?php
/**
 * Per-server settings. Copy this file to config/config.local.php on each
 * server and fill in that server's values. config.local.php is git-ignored,
 * so pulling new code from GitHub never overwrites it.
 *
 * Anything not defined here falls back to the defaults in config/config.php.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');          // check C:\xampp\mysql\bin\my.ini, [mysqld] port
define('DB_NAME', 'usaasa_mel');
define('DB_USER', 'root');
define('DB_PASS', '');

// URL path the app is served under (http://<server>/mel_system).
define('APP_BASE_URL', '/mel_system');
