<?php
/**
 * includes/header.php
 * The page layout above the content. Every page (staff and public) uses it.
 *
 * Before including it a page may set:
 *     $pageTitle   = 'Students';          // shown in the browser tab
 *     $pageScripts = ['assets/js/x.js'];  // loaded by footer.php
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/billing.php';

$navUser  = current_user();
$isStaff  = $navUser !== null;
$isAdmin  = $isStaff && $navUser['role'] === 'ADMIN';
$newFollowUps = $isStaff ? reservation_new_count() : 0;     // families who chose "Pay later"
$tabTitle = (isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' | ' : '') . 'MIST Talent Program';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tabTitle) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/tailwind.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen flex flex-col">

    <div class="bg-mistnavy text-slate-300 text-xs py-2 border-b border-mistgold/30">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-4">
                <span><i class="fas fa-location-dot text-mistgold mr-1"></i> Mumbi Plaza, 4th Floor, Nakuru</span>
                <span><i class="fas fa-phone text-mistgold mr-1"></i> +254 700 904 523</span>
            </div>
            <span><i class="fas fa-envelope text-mistgold mr-1"></i> info@mist.ac.ke</span>
        </div>
    </div>

    <header class="bg-white shadow-md sticky top-0 z-50 border-b-2 border-mistgold">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <a href="<?= e(url('index.php')) ?>" class="flex items-center space-x-3">
                <span class="w-10 h-10 bg-mistnavy text-mistgold rounded-lg flex items-center justify-center font-black text-lg shadow">M</span>
                <span>
                    <span class="block font-extrabold text-mistnavy text-base md:text-lg tracking-tight">MIST Talent Program</span>
                    <span class="block text-[10px] text-slate-500 font-semibold uppercase tracking-wider">Musketeers Institute of Science &amp; Tech</span>
                </span>
            </a>

            <nav class="hidden md:flex items-center space-x-6 text-sm font-medium">
                <a href="<?= e(url('index.php')) ?>" class="text-slate-700 hover:text-mistnavy">Home</a>
                <a href="<?= e(url('register.php')) ?>" class="text-mistgreen font-bold hover:underline"><i class="fas fa-user-plus"></i> Register Child</a>
                <?php if ($isStaff): ?>
                    <a href="<?= e(url('students.php')) ?>" class="text-slate-700 hover:text-mistnavy">Students</a>
                    <a href="<?= e(url('reservations.php')) ?>" class="text-slate-700 hover:text-mistnavy">
                        Follow-ups<?php if ($newFollowUps > 0): ?> <span class="bg-amber-500 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 num"><?= (int) $newFollowUps ?></span><?php endif; ?>
                    </a>
                    <?php if ($isAdmin): ?>
                        <a href="<?= e(url('activities.php')) ?>" class="text-slate-700 hover:text-mistnavy">Activities</a>
                        <a href="<?= e(url('periods.php')) ?>" class="text-slate-700 hover:text-mistnavy">Billing periods</a>
                        <a href="<?= e(url('users.php')) ?>" class="text-slate-700 hover:text-mistnavy">Users</a>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>

            <div class="flex items-center space-x-3">
                <?php if ($isStaff): ?>
                    <div class="text-right hidden sm:block">
                        <p class="text-xs font-bold text-mistnavy"><?= e($navUser['full_name']) ?></p>
                        <p class="text-[10px] bg-mistgold/20 text-yellow-800 px-2 py-0.5 rounded font-bold uppercase inline-block"><?= e($navUser['role']) ?></p>
                    </div>
                    <!-- Logout is a POST with a CSRF token, so another website cannot log people out. -->
                    <form method="post" action="<?= e(url('logout.php')) ?>">
                        <?= csrf_field() ?>
                        <button class="bg-rose-50 text-rose-600 hover:bg-rose-100 px-3 py-2 rounded-lg text-xs font-bold" title="Log out">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                <?php else: ?>
                    <a href="<?= e(url('login.php')) ?>" class="bg-mistnavy text-white hover:bg-indigo-950 px-4 py-2 rounded-lg text-xs font-bold shadow flex items-center gap-2">
                        <i class="fas fa-lock"></i> Staff Login
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Small-screen menu -->
        <nav class="md:hidden border-t border-slate-100 px-4 py-2 flex flex-wrap gap-x-5 gap-y-1 text-sm font-medium">
            <a href="<?= e(url('register.php')) ?>" class="text-mistgreen font-bold">Register Child</a>
            <?php if ($isStaff): ?>
                <a href="<?= e(url('students.php')) ?>">Students</a>
                <a href="<?= e(url('reservations.php')) ?>">Follow-ups<?php if ($newFollowUps > 0): ?> <span class="bg-amber-500 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5"><?= (int) $newFollowUps ?></span><?php endif; ?></a>
                <?php if ($isAdmin): ?>
                    <a href="<?= e(url('activities.php')) ?>">Activities</a>
                    <a href="<?= e(url('periods.php')) ?>">Billing</a>
                    <a href="<?= e(url('users.php')) ?>">Users</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </header>

    <main class="flex-grow max-w-7xl mx-auto px-4 py-8 w-full">
        <?php foreach (flash_all() as $note): ?>
            <?php $isErr = $note['type'] === 'error'; ?>
            <div class="mb-6 rounded border px-4 py-3 text-sm <?= $isErr ? 'bg-red-50 border-red-200 text-red-800' : 'bg-green-50 border-green-200 text-green-800' ?>"
                 role="<?= $isErr ? 'alert' : 'status' ?>">
                <?= e($note['message']) ?>
            </div>
        <?php endforeach; ?>
