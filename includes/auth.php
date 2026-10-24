<?php
/**
 * includes/auth.php
 * Everything about "who is using the system and what may they do":
 *   - starting the session safely
 *   - logging in and out
 *   - checking roles (ADMIN / MODERATOR)
 *   - CSRF protection for forms
 *   - flash messages ("Saved!" notices that survive a redirect)
 *
 * Every page that needs a logged-in user starts with:
 *     require_once __DIR__ . '/includes/auth.php';
 *     require_login();            // or require_role('ADMIN');
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

send_security_headers();

// ---------------------------------------------------------------------------
// 1. Start the session safely
// ---------------------------------------------------------------------------
// A session is how PHP remembers a visitor between page loads. The browser
// holds a cookie with a random ID; the data itself stays on the server.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_name('talent_sid');

    // Where session files are stored. Some hosts point PHP at a folder that does not exist,
    // which silently breaks login and every form's security token. Set SESSION_SAVE_PATH in .env
    // to use your own folder (outside the web root, permission 700). If it is not set, and PHP's
    // default folder is unusable, fall back to a private folder inside the system temp directory.
    $sessionPath = (string) env('SESSION_SAVE_PATH', '');
    if ($sessionPath === '') {
        $current = (string) session_save_path();
        if ($current === '' || !is_dir($current) || !is_writable($current)) {
            $sessionPath = sys_get_temp_dir() . '/talent_sessions';
        }
    }
    if ($sessionPath !== '') {
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0700, true);
        }
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            session_save_path($sessionPath);
        }
    }
    ini_set('session.use_strict_mode', '1');       // refuse session IDs the server did not create
    // Keep session files for 8 hours. PHP's default is only 24 minutes, after which a form left open
    // (a parent filling in the registration, or a payment page) loses its security token.
    // The 2-hour idle logout for staff below is still enforced by the app itself.
    ini_set('session.gc_maxlifetime', '28800');

    session_set_cookie_params([
        'lifetime' => 0,                            // cookie dies when the browser closes
        'path'     => BASE_URL === '' ? '/' : BASE_URL,
        'secure'   => $isHttps,                     // only sent over HTTPS when the site uses it
        'httponly' => true,                         // JavaScript cannot read the cookie
        'samesite' => 'Lax',                        // not sent on cross-site form posts
    ]);

    session_start();

    // Idle timeout: someone who walks away from a shared computer is logged out after 2 hours.
    $idleLimit = 2 * 60 * 60;
    if (!empty($_SESSION['user_id']) && isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > $idleLimit) {
        unset($_SESSION['user_id']);
        $_SESSION['flash'][] = ['type' => 'error', 'message' => 'You were logged out because of inactivity. Please sign in again.'];
    }
    $_SESSION['last_seen'] = time();
}

// ---------------------------------------------------------------------------
// 2. Who is logged in?
// ---------------------------------------------------------------------------
/**
 * Returns the logged-in user as an array, or null if nobody is logged in.
 *
 * The session only stores the user's ID. We look the user up in the database
 * on every request, so if an Admin deactivates someone or changes their role,
 * it takes effect immediately instead of waiting for them to log out.
 */
function current_user(): ?array
{
    static $user = false;           // false = "not looked up yet" (null means "nobody")

    if ($user === false) {
        $user = null;

        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare(
                'SELECT id, full_name, email, role
                   FROM users
                  WHERE id = ? AND is_active = 1'
            );
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        }
    }

    return $user;
}

/** Mark a user as logged in. */
function login_user(int $userId): void
{
    // New session ID after login: stops "session fixation" attacks.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

/** Log out completely: clear data, delete the cookie, destroy the session. */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
}

// ---------------------------------------------------------------------------
// 3. Guards: put these at the top of a page to protect it
// ---------------------------------------------------------------------------
/** Visitors who are not logged in are sent to the login page. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

/** Only the listed roles may continue, e.g. require_role('ADMIN'). */
function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        forbidden();
    }
    return $user;
}

/** Quick yes/no check, handy for hiding buttons: if (has_role('ADMIN')) { ... } */
function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], $roles, true);
}

/** Show an "Access denied" page and stop. */
function forbidden(): void
{
    http_response_code(403);
    $pageTitle = 'Access denied';
    require __DIR__ . '/header.php';
    echo '<div class="bg-white rounded-lg shadow p-6 max-w-xl">';
    echo '<h1 class="text-xl font-bold mb-2">Access denied</h1>';
    echo '<p class="text-slate-600">Your role does not allow you to open this page.</p>';
    echo '<p class="mt-4"><a class="text-indigo-700 underline" href="' . e(url('index.php')) . '">Back to home</a></p>';
    echo '</div>';
    require __DIR__ . '/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// 4. CSRF protection
// ---------------------------------------------------------------------------
// Problem: a malicious website could make your browser submit a form to OUR
// site while you are logged in. Fix: every form carries a secret random token
// that only our pages know. A forged form can't include it.

/** The token for this session (created on first use). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Put this inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Call at the top of any code that handles a POST. Stops the page if the token is wrong.
 * The real reason is written to the PHP error_log (and shown on screen when APP_DEBUG=true),
 * because "session expired" can mean several different things.
 */
function csrf_check(): void
{
    $sent     = $_POST['csrf'] ?? '';
    $hadToken = !empty($_SESSION['csrf']);          // checked BEFORE csrf_token() creates a new one

    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if (!$hadToken) {
            $reason = 'the server had no saved session for this visit (session file missing, expired or not writable)';
        } elseif ($sent === '') {
            $reason = 'the form did not send a security token';
        } else {
            $reason = 'the form came from an older visit (its token does not match this session)';
        }
        error_log(sprintf(
            'CSRF check failed on %s: %s | browser sent session cookie: %s | session folder: %s',
            $_SERVER['SCRIPT_NAME'] ?? '?',
            $reason,
            isset($_COOKIE[session_name()]) ? 'yes' : 'no',
            session_save_path()
        ));

        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>Your session expired or the form was invalid. Go back, refresh the page and try again.</p>';
        echo '<p><a href="' . e(url('index.php')) . '">Back to the home page</a></p>';
        if (APP_DEBUG) {
            echo '<p><small>Debug: ' . e($reason) . '</small></p>';
        }
        exit;
    }
}

// ---------------------------------------------------------------------------
// 5. Flash messages
// ---------------------------------------------------------------------------
// A one-time notice stored in the session so it survives a redirect.
//     flash('success', 'User added.');  redirect('users.php');
// header.php prints and clears them on the next page.

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_all(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}