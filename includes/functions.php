<?php
/**
 * includes/functions.php
 * Small helpers used on many pages.
 */

require_once __DIR__ . '/../config/config.php';

/** Escape text before printing it in HTML (prevents XSS). Use on ALL user-supplied data. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Build a URL inside the app, e.g. url('students.php') -> /talent-program/students.php */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Format money as Ksh, e.g. 4500 -> "Ksh 4,500.00" */
function money($amount): string
{
    return 'Ksh ' . number_format((float) $amount, 2);
}

/** Send the browser to another page and stop running this one. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Read one text field from a submitted form, safely.
 * Returns '' if the field is missing (or was sent as an array, which only an attacker would do).
 * Leading/trailing spaces are removed unless you pass false, which is what we do for passwords.
 */
function post_str(string $key, bool $trim = true): string
{
    $value = $_POST[$key] ?? '';

    if (!is_string($value)) {
        return '';
    }

    return $trim ? trim($value) : $value;
}
