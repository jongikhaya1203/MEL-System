<?php
// USAASA MEL System - application configuration

define('DB_HOST', 'localhost');
define('DB_PORT', '3311'); // this XAMPP install's MySQL runs on a custom port — check mysql/bin/my.ini's [mysqld] "port" if this ever changes
define('DB_NAME', 'usaasa_mel');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'USAASA MEL System');
define('APP_BASE_URL', '/mel_system');

// Proof-of-evidence (POE) file uploads for the site completion & payment
// approval module. Stored outside any directly browsable path — see
// storage/poe_uploads/.htaccess — and only ever served through
// sites/download_poe.php, which checks the user is logged in first.
define('POE_STORAGE_DIR', __DIR__ . '/../storage/poe_uploads/');
define('POE_MAX_BYTES', 10 * 1024 * 1024);

// Site logo, shown top-left in the header. Stored as a plain public static
// file (like assets/css/style.css) since a logo isn't sensitive — served
// directly via <img>, not gated behind a download script.
define('LOGO_STORAGE_DIR', __DIR__ . '/../assets/branding/');
define('LOGO_PUBLIC_PATH', APP_BASE_URL . '/assets/branding/');
define('LOGO_MAX_BYTES', 2 * 1024 * 1024);

// Indigent application household photos + signatures — personal/financial
// data tied to an ID number, more sensitive than a POE photo, so it gets
// the same locked-down treatment: random filenames, deny-all .htaccess,
// served only through an authenticated view script.
define('INDIGENT_STORAGE_DIR', __DIR__ . '/../storage/indigent_uploads/');
define('INDIGENT_PHOTO_MAX_BYTES', 5 * 1024 * 1024);

date_default_timezone_set('Africa/Johannesburg');

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

error_reporting(E_ALL);
ini_set('display_errors', '0'); // set to '1' temporarily when debugging locally
