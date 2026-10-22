<?php
/**
 * users.php
 * Admin only: list staff accounts, add new ones, activate/deactivate.
 *
 * Pattern used here (and on most pages we build):
 *   1. guard the page (require_role)
 *   2. if the request is a POST: check CSRF, validate, save, then REDIRECT
 *      (Post/Redirect/Get: refreshing the page never re-submits the form)
 *   3. load data from the database
 *   4. print the HTML
 */
require_once __DIR__ . '/includes/auth.php';

$me = require_role('ADMIN');

$pageTitle = 'Users';
$errors    = [];
$old       = ['full_name' => '', 'email' => '', 'role' => 'MODERATOR'];
$roles     = ['ADMIN' => 'Admin', 'MODERATOR' => 'Moderator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');

    // ---- Add a user -------------------------------------------------------
    if ($action === 'create') {
        $old['full_name'] = post_str('full_name');
        $old['email']     = strtolower(post_str('email'));
        $old['role']      = post_str('role');
        $password         = post_str('password', false);

        if ($old['full_name'] === '' || mb_strlen($old['full_name']) > 120) {
            $errors[] = 'Enter the full name (up to 120 characters).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 120) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!array_key_exists($old['role'], $roles)) {
            $errors[] = 'Choose a role.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'The password must be at least 8 characters.';
        }

        if (!$errors) {
            $exists = db()->prepare('SELECT 1 FROM users WHERE email = ?');
            $exists->execute([$old['email']]);
            if ($exists->fetchColumn()) {
                $errors[] = 'A user with that email already exists.';
            }
        }

        if (!$errors) {
            db()->prepare(
                'INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)'
            )->execute([
                $old['full_name'],
                $old['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $old['role'],
            ]);

            flash('success', 'User ' . $old['full_name'] . ' added as ' . $roles[$old['role']] . '.');
            redirect('users.php');
        }
    }

    // ---- Reset a password ---------------------------------------------------
    // The admin types a new temporary password and passes it to the staff member.
    if ($action === 'reset_password') {
        $id       = (int) post_str('id');
        $password = post_str('password', false);

        $stmt = db()->prepare('SELECT id, full_name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'User not found.');
        } elseif (strlen($password) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $target['id']]);

            flash('success', 'Password reset for ' . $target['full_name'] . '. Share the new password with them privately.');
        }

        redirect('users.php');
    }

    // ---- Activate / deactivate -------------------------------------------
    if ($action === 'toggle') {
        $id = (int) post_str('id');

        $stmt = db()->prepare('SELECT id, full_name, role, is_active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'User not found.');
        } elseif ((int) $target['id'] === (int) $me['id']) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            $blocked = false;

            // Never allow the last active admin to be switched off.
            if ($target['role'] === 'ADMIN' && (int) $target['is_active'] === 1) {
                $others = db()->prepare(
                    "SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND is_active = 1 AND id <> ?"
                );
                $others->execute([$target['id']]);
                if ((int) $others->fetchColumn() < 1) {
                    flash('error', 'At least one active admin must remain.');
                    $blocked = true;
                }
            }

            if (!$blocked) {
                db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')
                    ->execute([$target['id']]);
                flash('success', $target['full_name'] . ((int) $target['is_active'] === 1 ? ' deactivated.' : ' reactivated.'));
            }
        }

        redirect('users.php');
    }
}

$users = db()->query(
    'SELECT id, full_name, email, role, is_active, created_at FROM users ORDER BY role, full_name'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-6">Users</h1>

<div class="grid gap-6 lg:grid-cols-3">

    <!-- Existing users -->
    <div class="lg:col-span-2 bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="px-4 py-3 font-medium"><?= e($u['full_name']) ?></td>
                        <td class="px-4 py-3"><?= e($u['email']) ?></td>
                        <td class="px-4 py-3"><?= e($roles[$u['role']] ?? $u['role']) ?></td>
                        <td class="px-4 py-3">
                            <?php if ((int) $u['is_active'] === 1): ?>
                                <span class="text-green-700">Active</span>
                            <?php else: ?>
                                <span class="text-slate-500">Deactivated</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                                <form method="post" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="text-indigo-700 hover:underline">
                                        <?= (int) $u['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-slate-400">You</span>
                            <?php endif; ?>

                            <!-- <details> is built into HTML: click the summary to open or close the form, no JavaScript needed -->
                            <details class="block mt-1 text-left">
                                <summary class="cursor-pointer text-indigo-700 hover:underline text-right list-none">Reset password</summary>
                                <form method="post" class="mt-2 flex gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <input name="password" type="password" required minlength="8"
                                           placeholder="New password (8+)" autocomplete="new-password"
                                           class="w-40 rounded border border-slate-300 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <button class="rounded bg-indigo-700 hover:bg-indigo-800 text-white text-sm px-3 py-1">Save</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Add user -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Add a user</h2>

        <?php if ($errors): ?>
            <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-800 px-3 py-2 text-sm">
                <ul class="list-disc pl-5">
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div>
                <label for="full_name" class="block text-sm font-medium mb-1">Full name</label>
                <input id="full_name" name="full_name" required maxlength="120" value="<?= e($old['full_name']) ?>"
                       class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium mb-1">Email</label>
                <input id="email" name="email" type="email" required maxlength="120" value="<?= e($old['email']) ?>"
                       class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label for="role" class="block text-sm font-medium mb-1">Role</label>
                <select id="role" name="role"
                        class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <?php foreach ($roles as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $old['role'] === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium mb-1">Temporary password (8+ characters)</label>
                <input id="password" name="password" type="password" required minlength="8"
                       class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <button type="submit"
                    class="w-full bg-indigo-700 hover:bg-indigo-800 text-white font-medium rounded px-4 py-2">
                Add user
            </button>
        </form>
    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>