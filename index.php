<?php
/**
 * index.php
 * Home page. For Phase 1 it simply proves that:
 *   1. PHP is running,
 *   2. the layout and Tailwind load,
 *   3. PHP can talk to MySQL and see the tables from schema.sql.
 */
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Home';

// Ask MySQL which tables exist in our database.
$tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

// The tables the app expects (from schema.sql).
$expected = [
    'users', 'guardians', 'students', 'activities', 'activity_levels',
    'billing_periods', 'enrollments', 'charges', 'payments', 'payment_allocations',
];
$missing = array_diff($expected, $tables);

require __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-6">Welcome</h1>

<div class="bg-white rounded-lg shadow p-6 max-w-xl">
    <h2 class="text-lg font-semibold mb-3">System check</h2>

    <p class="mb-2">
        <span class="font-medium">PHP version:</span> <?= e(PHP_VERSION) ?>
    </p>

    <p class="mb-2">
        <span class="font-medium">Database:</span>
        <span class="text-green-700">Connected to <?= e(DB_NAME) ?></span>
    </p>

    <?php if (empty($missing)): ?>
        <p class="text-green-700">All <?= count($expected) ?> tables found. Foundation is ready.</p>
    <?php else: ?>
        <p class="text-red-700 font-medium">Missing tables: <?= e(implode(', ', $missing)) ?></p>
        <p class="text-sm text-slate-600 mt-1">Import <code>schema.sql</code> into the <?= e(DB_NAME) ?> database.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
