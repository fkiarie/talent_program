<?php
/**
 * includes/env.php
 * A tiny .env file reader (no Composer needed).
 *
 * A .env file is plain text, one setting per line:
 *     DB_USER=root
 *     DB_PASS="my secret"
 *     # lines starting with # are comments
 *
 * loadEnv() reads the file once; env() then fetches a value by name.
 */

/**
 * Read a .env file and remember its values.
 * Call this once, at the top of config.php.
 */
function loadEnv(string $path): void
{
    if (!is_readable($path)) {
        // We can't use the normal error pages yet (config isn't loaded), so stop plainly.
        http_response_code(500);
        die('Configuration file not found. Copy ".env.example" to ".env" and fill in your settings.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and lines without an "=".
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        // Split on the FIRST "=" only, so values may contain "=" themselves.
        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);

        // Remove matching quotes around the value: "abc" or 'abc' -> abc
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' || $first === "'") && $first === $last) {
                $value = substr($value, 1, -1);
            }
        }

        envStore($name, $value);
    }
}

/**
 * Get a setting by name. Returns $default if it isn't in the .env file.
 */
function env(string $key, $default = null)
{
    $values = envStore();
    return array_key_exists($key, $values) ? $values[$key] : $default;
}

/**
 * Internal storage. With a name and value it saves one; with no arguments it returns everything.
 * (Kept in a static variable so the values live only inside PHP, not in the server environment.)
 */
function envStore(?string $name = null, ?string $value = null): array
{
    static $values = [];

    if ($name !== null) {
        $values[$name] = $value;
    }

    return $values;
}

/**
 * Turn "true"/"false"/"1"/"0"/"yes"/"no" from .env into a real boolean.
 */
function envBool(string $key, bool $default = false): bool
{
    $raw = env($key);
    if ($raw === null) {
        return $default;
    }
    return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
}
