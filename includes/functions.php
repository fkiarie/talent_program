<?php
/**
 * includes/functions.php
 * Small helpers used on many pages.
 */

require_once __DIR__ . '/../config/config.php';

// The three proficiency levels, in display order. Every activity has all three.
const PROFICIENCY_LEVELS = ['Beginner', 'Intermediate', 'Advanced'];

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

// ---------------------------------------------------------------------------
// Phone numbers, ID numbers, dates
// ---------------------------------------------------------------------------

/**
 * Clean up a phone number and store it as digits only, in the format M-Pesa uses.
 *   0712 345 678, 0112345678, 712345678, +254712345678  ->  254712345678
 * Numbers from other countries must start with + (or 00), e.g. +256772123456 -> 256772123456.
 * Returns null if the number does not look valid.
 */
function normalize_phone(string $raw): ?string
{
    $raw     = trim($raw);
    $hasPlus = str_starts_with($raw, '+');
    $digits  = preg_replace('/\D+/', '', $raw);

    if ($digits === '' || $digits === null) {
        return null;
    }

    if (!$hasPlus) {
        if (str_starts_with($digits, '00')) {                 // 00256... = international prefix
            $digits  = substr($digits, 2);
            $hasPlus = true;
        } elseif (strlen($digits) === 10 && $digits[0] === '0') {      // 0712345678
            return '254' . substr($digits, 1);
        } elseif (strlen($digits) === 9 && in_array($digits[0], ['7', '1'], true)) {   // 712345678
            return '254' . $digits;
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '254')) {         // 254712345678
            return $digits;
        }
    }

    if ($hasPlus && strlen($digits) >= 8 && strlen($digits) <= 15) {
        // A Kenyan number written with + must be the full 12 digits.
        if (str_starts_with($digits, '254') && strlen($digits) !== 12) {
            return null;
        }
        return $digits;
    }

    return null;
}

/** How to show a stored number inside a form field: 254712345678 -> 0712345678 */
function phone_for_input(string $stored): string
{
    if (strlen($stored) === 12 && str_starts_with($stored, '254')) {
        return '0' . substr($stored, 3);
    }
    return '+' . $stored;
}

/** How to print a stored number on screen: 254712345678 -> +254 712 345 678 */
function format_phone(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }
    if (strlen($stored) === 12 && str_starts_with($stored, '254')) {
        return '+254 ' . substr($stored, 3, 3) . ' ' . substr($stored, 6, 3) . ' ' . substr($stored, 9, 3);
    }
    return '+' . $stored;
}

/**
 * Tidy an ID / passport number so "12 345 678" and "12345678" count as the same.
 * Removes spaces and makes letters capitals.
 */
function normalize_id_number(string $raw): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($raw)));
}

/** Letters, digits, hyphen and slash only, 4 to 30 characters. */
function valid_id_number(string $id): bool
{
    return (bool) preg_match('/^[A-Z0-9\-\/]{4,30}$/', $id);
}

/** Turn "2014-05-23" into a date object, or null if it is not a real date. */
function parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if ($date === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date;
}

/** Age in whole years today, from a date of birth like "2014-05-23". */
function age_years(string $dob): int
{
    try {
        return (new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
    } catch (Exception $e) {
        return 0;
    }
}

// ---------------------------------------------------------------------------
// Money
// ---------------------------------------------------------------------------

/**
 * Read a money amount typed by a person: "4500", "4,500" and "4500.50" all work.
 * Returns null unless it is a number above 0 and at most 1,000,000 (two decimals at most).
 */
function parse_amount(string $raw): ?float
{
    $raw = str_replace([',', ' '], '', trim($raw));

    if ($raw === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return null;
    }

    $value = (float) $raw;
    return ($value > 0 && $value <= 1000000) ? round($value, 2) : null;
}
