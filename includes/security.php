<?php
/**
 * includes/security.php
 * Small security helpers shared by every page:
 *   - the visitor's IP address
 *   - rate limiting (login attempts, public form spam, payment prompts)
 *   - standard security headers
 *
 * Rate limits are stored in the `rate_limits` table, so they work even when an
 * attacker throws away their cookies.
 */

require_once __DIR__ . '/db.php';

/** The visitor's IP. X-Forwarded-For is only trusted when TRUSTED_PROXY=true in .env. */
function client_ip(): string
{
    if (envBool('TRUSTED_PROXY', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Record one event in a bucket, e.g. rate_limit_hit('login_ip', $ip). */
function rate_limit_hit(string $bucket, string $key): void
{
    db()->prepare('INSERT INTO rate_limits (bucket, rkey) VALUES (?, ?)')
        ->execute([$bucket, mb_substr($key, 0, 120)]);

    // Tidy up old rows now and then (1 request in 50) so the table stays small.
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    }
}

/** Has this key done more than $max things in the last $windowSeconds? */
function rate_limited(string $bucket, string $key, int $max, int $windowSeconds): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM rate_limits
          WHERE bucket = ? AND rkey = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$bucket, mb_substr($key, 0, 120), $windowSeconds]);
    return (int) $stmt->fetchColumn() >= $max;
}

/** Forget a key's history, e.g. after a successful login. */
function rate_limit_clear(string $bucket, string $key): void
{
    db()->prepare('DELETE FROM rate_limits WHERE bucket = ? AND rkey = ?')
        ->execute([$bucket, mb_substr($key, 0, 120)]);
}

/** Headers that switch on browser protections. Safe to call more than once. */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');                 // nobody can put our pages in a hidden frame
    header('X-Content-Type-Options: nosniff');             // browser must respect the declared file type
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header_remove('X-Powered-By');

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}
