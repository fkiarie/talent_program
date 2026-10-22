<?php
/**
 * login.php
 * Shows the login form and checks the email + password.
 */
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Nothing to do here.
if (current_user() !== null) {
    redirect('index.php');
}

// Brand-new system with no users yet? Go create the first admin.
$userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount === 0) {
    redirect('setup_admin.php');
}

$pageTitle = 'Log in';
$error     = '';
$email     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = post_str('email');
    $password = post_str('password', false);   // false = do not trim: spaces may be part of a password

    // Prepared statement: the ? is filled in separately from the SQL text,
    // so whatever the visitor types can never be run as SQL.
    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE email = ?');
    $stmt->execute([strtolower($email)]);
    $user = $stmt->fetch();

    // Always do one password check, even for unknown emails, so the response
    // time does not reveal which emails exist.
    $hash = $user ? $user['password_hash'] : password_hash('not-a-real-password', PASSWORD_DEFAULT);
    $ok   = password_verify($password, $hash) && $user && (int) $user['is_active'] === 1;

    if ($ok) {
        // If PHP's recommended hashing strength has improved since this password
        // was stored, quietly upgrade the stored hash.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        login_user((int) $user['id']);
        redirect('index.php');
    }

    // One vague message for every failure: don't reveal whether the email exists.
    $error = 'Incorrect email or password, or the account is deactivated.';
}

require __DIR__ . '/includes/header.php';
?>

<div class="max-w-sm mx-auto bg-white rounded-lg shadow p-6">
    <h1 class="text-xl font-bold mb-4">Log in</h1>

    <?php if ($error): ?>
        <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-800 px-3 py-2 text-sm">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-4" autocomplete="on">
        <?= csrf_field() ?>

        <div>
            <label for="email" class="block text-sm font-medium mb-1">Email</label>
            <input id="email" name="email" type="email" required autofocus
                   value="<?= e($email) ?>"
                   class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium mb-1">Password</label>
            <input id="password" name="password" type="password" required
                   class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>

        <button type="submit"
                class="w-full bg-indigo-700 hover:bg-indigo-800 text-white font-medium rounded px-4 py-2">
            Log in
        </button>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
