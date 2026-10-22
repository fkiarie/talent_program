<?php
/**
 * config/config.php
 * Loads settings from the .env file and turns them into constants.
 *
 * Secrets and anything that differs between your computer and the live server
 * (database login, debug mode, URL path) now live in ".env", not in this file.
 * This file only says WHICH settings exist and gives safe defaults.
 */

require_once __DIR__ . '/../includes/env.php';

// Where the .env file lives. By default: the project root (one level above /config).
// On the live server you can move it OUTSIDE the public web folder and change this path.
loadEnv(__DIR__ . '/../.env');

// ---- App settings ---------------------------------------------------------
define('APP_NAME', env('APP_NAME', 'Holiday Talent Program'));

// The URL path where the app lives. No trailing slash.
// Local example: http://localhost/talent-program  ->  '/talent-program'
// If the app sits at the domain root on the live server, use an empty value.
define('BASE_URL', rtrim(env('BASE_URL', '/talent-program'), '/'));

// ---- Database -------------------------------------------------------------
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', 'talent_program'));
define('DB_USER',    env('DB_USER', 'root'));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ---- Business rules -------------------------------------------------------
define('REGISTRATION_FEE', (float) env('REGISTRATION_FEE', 2000));   // one-time fee in Ksh

// ---- Environment ----------------------------------------------------------
date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Nairobi'));

// true while developing (shows errors on screen); must be false on the live server.
// If the setting is missing we default to FALSE, the safe choice.
define('APP_DEBUG', envBool('APP_DEBUG', false));

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
