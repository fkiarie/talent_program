<?php
/**
 * includes/header.php
 * The top of every page: <head>, Tailwind, and the navigation bar.
 * Each page sets $pageTitle before including this file.
 */
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? APP_NAME;
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
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="<?= url('index.php') ?>" class="text-lg font-semibold"><?= e(APP_NAME) ?></a>
            <!-- Menu links get added here in later phases (Students, Activities, Payments...) -->
            <span class="text-sm text-indigo-200">Phase 1</span>
        </div>
    </nav>

    <main class="max-w-6xl w-full mx-auto px-4 py-8 flex-1">
