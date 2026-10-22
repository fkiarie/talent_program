<?php
/**
 * index.php
 * Home page. Requires a logged-in user (Admin or Moderator).
 * Admins also see the Phase 1 system check.
 */
require_once __DIR__ . '/includes/auth.php';

$user = require_login();     // not logged in? this sends them to login.php

$pageTitle = 'Home';

// The system check is only useful to the admin, so only run it for them.
$tables  = [];
$missing = [];
$expected = [
    'users', 'guardians', 'students', 'activities', 'activity_levels',
    'billing_periods', 'enrollments', 'charges', 'payments', 'payment_allocations',
];

if ($user['role'] === 'ADMIN') {
    $tables  = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($expected, $tables);
}

require __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-1">Welcome, <?= e($user['full_name']) ?></h1>
<p class="text-slate-600 mb-6">
    You are logged in as <strong><?= e(ucfirst(strtolower($user['role']))) ?></strong>.
</p>

<?php if ($user['role'] === 'ADMIN'): ?>
    <div class="bg-white rounded-lg shadow p-6 max-w-xl">
        <h2 class="text-lg font-semibold mb-3">System check <span class="text-xs font-normal text-slate-500">(admin only)</span></h2>

        <p class="mb-2">
            <span class="font-medium">PHP version:</span> <?= e(PHP_VERSION) ?>
        </p>

        <p class="mb-2">
            <span class="font-medium">Database:</span>
            <span class="text-green-700">Connected to <?= e(DB_NAME) ?></span>
        </p>

        <?php if (empty($missing)): ?>
            <p class="text-green-700">All <?= count($expected) ?> tables found.</p>
        <?php else: ?>
            <p class="text-red-700 font-medium">Missing tables: <?= e(implode(', ', $missing)) ?></p>
            <p class="text-sm text-slate-600 mt-1">Import <code>schema.sql</code> into the <?= e(DB_NAME) ?> database.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg shadow p-6 max-w-xl text-slate-600">
        Student registration and payment screens will appear here in the next phases.
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
