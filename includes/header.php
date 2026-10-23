<?php
/**
 * includes/header.php
 * The top of every page: <head>, Tailwind, navigation bar and flash messages.
 * Each page sets $pageTitle before including this file.
 */
require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? APP_NAME;
$navUser   = current_user();     // null when nobody is logged in
$flashes   = flash_all();        // one-time notices such as "User added."
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>

    <!-- Tailwind via CDN: no build step needed on shared hosting -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Our own small additions -->
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col">

    <nav class="bg-indigo-700 text-white shadow">
        <div class="max-w-6xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-3">

            <div class="flex items-center gap-6">
                <a href="<?= url('index.php') ?>" class="text-lg font-semibold"><?= e(APP_NAME) ?></a>

                <?php if ($navUser): ?>
                    <a href="<?= url('index.php') ?>" class="text-sm hover:underline">Home</a>

                    <a href="<?= url('students.php') ?>" class="text-sm hover:underline">Students</a>

                    <!-- Menu links get added here in later phases (Activities, Payments...) -->

                    <?php if ($navUser['role'] === 'ADMIN'): ?>
                        <a href="<?= url('users.php') ?>" class="text-sm hover:underline">Users</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($navUser): ?>
                <div class="flex items-center gap-3 text-sm">
                    <span>
                        <?= e($navUser['full_name']) ?>
                        <span class="ml-1 rounded bg-indigo-900 px-2 py-0.5 text-xs">
                            <?= e(ucfirst(strtolower($navUser['role']))) ?>
                        </span>
                    </span>

                    <!-- Logout is a POST form with a CSRF token, not a plain link -->
                    <form method="post" action="<?= url('logout.php') ?>">
                        <?= csrf_field() ?>
                        <button class="rounded border border-indigo-300 px-3 py-1 hover:bg-indigo-600">Log out</button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </nav>

    <main class="max-w-6xl w-full mx-auto px-4 py-8 flex-1">

        <?php foreach ($flashes as $f): ?>
            <?php
                $style = $f['type'] === 'error'
                    ? 'bg-red-50 border-red-200 text-red-800'
                    : 'bg-green-50 border-green-200 text-green-800';
            ?>
            <div class="mb-6 rounded border px-4 py-3 text-sm <?= $style ?>">
                <?= e($f['message']) ?>
            </div>
        <?php endforeach; ?>
