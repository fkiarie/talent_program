<?php
/**
 * login.php
 * Staff sign-in, built on includes/auth.php like every other page.
 *   - CSRF token on the form
 *   - rate limiting per email and per IP (the table survives cookie-clearing)
 *   - same message for "no such user" and "wrong password"
 *   - new session ID after login, password re-hashed if the algorithm has improved
 */
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect('students.php');
}

$pageTitle = 'Staff login';
$error     = '';
$email     = '';

const LOGIN_MAX_PER_EMAIL = 5;     // wrong attempts allowed per email ...
const LOGIN_MAX_PER_IP    = 20;    // ... and per IP address ...
const LOGIN_WINDOW        = 900;   // ... in this many seconds (15 minutes)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = strtolower(post_str('email'));
    $password = post_str('password', false);
    $ip       = client_ip();

    if (rate_limited('login_ip', $ip, LOGIN_MAX_PER_IP, LOGIN_WINDOW)
        || rate_limited('login_email', $email, LOGIN_MAX_PER_EMAIL, LOGIN_WINDOW)) {
        http_response_code(429);
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        // Always run password_verify, even for unknown emails, so timing does not reveal which emails exist.
        $hash  = $row['password_hash'] ?? '$2y$10$usesomesillystringforsaltexample.ZfZm8N0TqU0JvC0q1yP5Q0vK2';
        $valid = password_verify($password, $hash) && $row !== false;

        if ($valid) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            rate_limit_clear('login_email', $email);
            login_user((int) $row['id']);
            redirect('students.php');
        }

        rate_limit_hit('login_ip', $ip);
        rate_limit_hit('login_email', $email);
        $error = 'Invalid email or password.';
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-200">
    <div class="bg-mistnavy p-8 text-white text-center border-b-4 border-mistgold">
        <div class="w-16 h-16 bg-mistgold text-mistnavy rounded-2xl flex items-center justify-center mx-auto mb-3 font-black text-2xl shadow">M</div>
        <h1 class="text-xl font-bold">MIST Staff Portal</h1>
        <p class="text-xs text-slate-300 mt-1">Musketeers Institute of Science and Technology</p>
    </div>

    <div class="p-8 space-y-6">
        <?php if ($error): ?>
            <div class="bg-rose-50 text-rose-700 p-3 rounded-xl text-xs border border-rose-200" role="alert">
                <i class="fas fa-circle-exclamation text-rose-500"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label for="email" class="block text-xs font-bold uppercase text-slate-600 mb-1">Staff email</label>
                <input id="email" type="email" name="email" required autocomplete="username" value="<?= e($email) ?>"
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-mistnavy text-sm">
            </div>
            <div>
                <label for="password" class="block text-xs font-bold uppercase text-slate-600 mb-1">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                       class="w-full px-4 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-mistnavy text-sm">
            </div>
            <button class="w-full bg-mistgreen hover:bg-emerald-700 text-white font-bold py-3 rounded-xl shadow-lg text-sm">
                <i class="fas fa-right-to-bracket"></i> Sign in
            </button>
        </form>

        <p class="text-center text-xs text-slate-500">
            <a href="<?= e(url('register.php')) ?>" class="hover:text-mistnavy font-medium">&larr; Back to parent registration</a>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
