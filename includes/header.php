<?php
/**
 * includes/header.php
 * The page layout above the content: contact bar, navigation, breadcrumbs and flash messages.
 *
 * Before including it a page may set:
 *     $pageTitle    = 'Students';           // browser tab + last breadcrumb
 *     $pageScripts  = ['assets/js/x.js'];   // loaded by footer.php
 *     $breadcrumbs  = [['Students', 'students.php'], ['Judy']];   // optional: replaces the automatic trail
 *
 * THE MENU lives in the $nav array below: to add a page to the menu, add one line there.
 * Each group is shown only to people allowed to use it, so nobody sees links that would give "Access denied".
 */
require_once __DIR__ . '/auth.php';

$navUser  = current_user();
$isStaff  = $navUser !== null;
$isAdmin  = $isStaff && $navUser['role'] === 'ADMIN';
$tabTitle = (isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' | ' : '') . 'MIST Talent Program';
$cur      = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

// ---- The menu --------------------------------------------------------------------------------
// label, icon, 'pages' = every file that belongs to this group (keeps it highlighted on detail pages),
// 'items' = [label, file, icon]. A group with one item is a plain link; with several it is a drop-down.
$nav = [['label' => 'Home', 'icon' => 'fa-house', 'pages' => ['index.php'],
         'items' => [['Home', 'index.php', 'fa-house']]]];

if ($isStaff) {
    $nav[] = ['label' => 'Students', 'icon' => 'fa-user-graduate',
              'pages' => ['students.php', 'student.php', 'student_new.php'],
              'items' => [['All students', 'students.php', 'fa-users'],
                          ['Register a student', 'student_new.php', 'fa-user-plus']]];
    if ($isAdmin) {
        $nav[] = ['label' => 'Billing', 'icon' => 'fa-file-invoice-dollar',
                  'pages' => ['periods.php', 'period.php'],
                  'items' => [['Billing periods', 'periods.php', 'fa-calendar-days']]];
        $nav[] = ['label' => 'Setup', 'icon' => 'fa-gear',
                  'pages' => ['activities.php', 'users.php'],
                  'items' => [['Activities &amp; fees', 'activities.php', 'fa-futbol'],
                              ['Users', 'users.php', 'fa-user-shield']]];
    }
}

// Which group is the visitor in? (null on pages that are in no menu, like pay.php)
$activeGroup = null;
foreach ($nav as $gi => $g) {
    if (in_array($cur, $g['pages'], true)) {
        $activeGroup = $gi;
    }
}

// ---- Breadcrumbs: "Students > Register a student". Skipped on a group's main page. ----
$crumbs = $breadcrumbs ?? [];
if (!$crumbs && $activeGroup !== null && $activeGroup > 0) {
    $g = $nav[$activeGroup];
    $isLanding = $cur === $g['items'][0][1];
    if (!$isLanding) {
        $last = null;
        foreach ($g['items'] as $it) {
            if ($it[1] === $cur) {
                $last = $it[0];
            }
        }
        $crumbs = [[$g['label'], $g['items'][0][1]],
                   [$last ?? ($pageTitle ?? 'Details')]];
    }
}

// Reusable bits of styling
$linkBase   = 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold transition-colors ';
$linkIdle   = $linkBase . 'text-slate-600 hover:bg-slate-100 hover:text-mistnavy';
$linkActive = $linkBase . 'bg-mistnavy/10 text-mistnavy';
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

    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:bg-white focus:text-mistnavy focus:px-4 focus:py-2 focus:m-2 focus:rounded">Skip to content</a>

    <div class="hidden sm:block bg-mistnavy text-slate-300 text-xs py-2 border-b border-mistgold/30">
        <div class="max-w-7xl mx-auto px-4 flex justify-between items-center gap-2">
            <div class="flex items-center space-x-4">
                <span><i class="fas fa-location-dot text-mistgold mr-1"></i> Mumbi Plaza, 4th Floor, Nakuru</span>
                <span><i class="fas fa-phone text-mistgold mr-1"></i> +254 700 904 523</span>
            </div>
            <span><i class="fas fa-envelope text-mistgold mr-1"></i> info@mist.ac.ke</span>
        </div>
    </div>

    <header class="bg-white shadow-md sticky top-0 z-50 border-b-2 border-mistgold">
        <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between gap-4">

            <a href="<?= e(url('index.php')) ?>" class="flex items-center space-x-3 shrink-0">
                <span class="w-10 h-10 bg-mistnavy text-mistgold rounded-lg flex items-center justify-center font-black text-lg shadow">M</span>
                <span class="leading-tight">
                    <span class="block font-extrabold text-mistnavy text-base tracking-tight">MIST Talent Program</span>
                    <span class="hidden sm:block text-[10px] text-slate-500 font-semibold uppercase tracking-wider">Musketeers Institute of Science &amp; Tech</span>
                </span>
            </a>

            <!-- Desktop menu -->
            <nav aria-label="Main" class="hidden lg:flex items-center gap-1 flex-1 ml-6">
                <?php foreach ($nav as $gi => $g): ?>
                    <?php $isActive = $activeGroup === $gi; ?>
                    <?php if (count($g['items']) === 1): ?>
                        <a href="<?= e(url($g['items'][0][1])) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>
                           class="<?= $isActive ? $linkActive : $linkIdle ?>">
                            <i class="fas <?= e($g['icon']) ?> text-xs"></i> <?= e($g['label']) ?>
                        </a>
                    <?php else: ?>
                        <div class="relative">
                            <button type="button" data-dropdown="dd-<?= $gi ?>" aria-expanded="false" aria-haspopup="true"
                                    class="<?= $isActive ? $linkActive : $linkIdle ?>">
                                <i class="fas <?= e($g['icon']) ?> text-xs"></i> <?= e($g['label']) ?>
                                <i class="fas fa-chevron-down text-[9px] opacity-60"></i>
                            </button>
                            <div id="dd-<?= $gi ?>" class="hidden absolute left-0 mt-2 w-60 bg-white rounded-xl shadow-lg border border-slate-200 p-2 z-50">
                                <?php foreach ($g['items'] as $it): ?>
                                    <?php $here = $cur === $it[1]; ?>
                                    <a href="<?= e(url($it[1])) ?>" <?= $here ? 'aria-current="page"' : '' ?>
                                       class="<?= $here ? $linkActive : $linkIdle ?>">
                                        <i class="fas <?= e($it[2]) ?> w-4 text-center text-xs"></i> <?= $it[0] ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <div class="flex items-center gap-2">
                <?php if (!$isStaff): ?>
                    <a href="<?= e(url('register.php')) ?>"
                       class="hidden sm:inline-flex items-center gap-2 bg-mistgreen hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow">
                        <i class="fas fa-user-plus text-xs"></i> Register Child
                    </a>
                    <a href="<?= e(url('login.php')) ?>"
                       class="hidden lg:inline-flex items-center gap-2 bg-mistnavy hover:bg-indigo-950 text-white px-4 py-2 rounded-lg text-sm font-bold shadow">
                        <i class="fas fa-lock text-xs"></i> Staff Login
                    </a>
                <?php else: ?>
                    <!-- Account menu (desktop) -->
                    <div class="relative hidden lg:block">
                        <button type="button" data-dropdown="dd-account" aria-expanded="false" aria-haspopup="true"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-slate-100">
                            <span class="w-8 h-8 rounded-full bg-mistnavy text-mistgold flex items-center justify-center text-sm font-bold">
                                <?= e(mb_strtoupper(mb_substr($navUser['full_name'], 0, 1))) ?>
                            </span>
                            <span class="text-left leading-tight">
                                <span class="block text-xs font-bold text-mistnavy"><?= e($navUser['full_name']) ?></span>
                                <span class="block text-[10px] text-slate-500 uppercase font-semibold"><?= e($navUser['role']) ?></span>
                            </span>
                            <i class="fas fa-chevron-down text-[9px] opacity-60"></i>
                        </button>
                        <div id="dd-account" class="hidden absolute right-0 mt-2 w-60 bg-white rounded-xl shadow-lg border border-slate-200 p-2 z-50">
                            <a href="<?= e(url('register.php')) ?>" class="<?= $linkIdle ?>">
                                <i class="fas fa-globe w-4 text-center text-xs"></i> Parent registration page
                            </a>
                            <!-- Logout is a POST with a CSRF token, so another website cannot log people out. -->
                            <form method="post" action="<?= e(url('logout.php')) ?>" class="border-t border-slate-100 mt-1 pt-1">
                                <?= csrf_field() ?>
                                <button class="w-full <?= $linkBase ?>text-rose-600 hover:bg-rose-50">
                                    <i class="fas fa-sign-out-alt w-4 text-center text-xs"></i> Log out
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Menu button (small screens) -->
                <button type="button" id="mobile-menu-btn" aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu"
                        class="lg:hidden w-10 h-10 rounded-lg border border-slate-200 text-mistnavy hover:bg-slate-100">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>

        <!-- Mobile menu: every group laid out as a labelled section -->
        <nav id="mobile-menu" aria-label="Main (mobile)" class="hidden lg:hidden border-t border-slate-200 bg-white px-4 py-3 max-h-[80vh] overflow-y-auto">
            <?php foreach ($nav as $gi => $g): ?>
                <?php if (count($g['items']) > 1): ?>
                    <p class="px-3 pt-3 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400"><?= e($g['label']) ?></p>
                <?php endif; ?>
                <?php foreach ($g['items'] as $it): ?>
                    <?php $here = $cur === $it[1]; ?>
                    <a href="<?= e(url($it[1])) ?>" <?= $here ? 'aria-current="page"' : '' ?> class="<?= $here ? $linkActive : $linkIdle ?>">
                        <i class="fas <?= e($it[2]) ?> w-4 text-center text-xs"></i> <?= $it[0] ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <div class="border-t border-slate-100 mt-3 pt-3">
                <?php if ($isStaff): ?>
                    <p class="px-3 pb-1 text-xs text-slate-500">
                        Signed in as <strong class="text-mistnavy"><?= e($navUser['full_name']) ?></strong>
                        <span class="uppercase text-[10px] font-semibold">(<?= e($navUser['role']) ?>)</span>
                    </p>
                    <a href="<?= e(url('register.php')) ?>" class="<?= $linkIdle ?>"><i class="fas fa-globe w-4 text-center text-xs"></i> Parent registration page</a>
                    <form method="post" action="<?= e(url('logout.php')) ?>">
                        <?= csrf_field() ?>
                        <button class="w-full <?= $linkBase ?>text-rose-600 hover:bg-rose-50"><i class="fas fa-sign-out-alt w-4 text-center text-xs"></i> Log out</button>
                    </form>
                <?php else: ?>
                    <a href="<?= e(url('register.php')) ?>" class="<?= $linkBase ?>bg-mistgreen text-white justify-center font-bold"><i class="fas fa-user-plus text-xs"></i> Register Child</a>
                    <a href="<?= e(url('login.php')) ?>" class="<?= $linkIdle ?> mt-1"><i class="fas fa-lock w-4 text-center text-xs"></i> Staff Login</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main id="main" class="flex-grow max-w-7xl mx-auto px-4 py-8 w-full">
        <?php if ($crumbs): ?>
            <nav aria-label="Breadcrumb" class="mb-5 text-sm text-slate-500">
                <ol class="flex flex-wrap items-center gap-2">
                    <?php foreach ($crumbs as $ci => $crumb): ?>
                        <?php $isLastCrumb = $ci === count($crumbs) - 1; ?>
                        <li class="flex items-center gap-2">
                            <?php if (!$isLastCrumb && isset($crumb[1])): ?>
                                <a href="<?= e(url($crumb[1])) ?>" class="hover:text-mistnavy hover:underline"><?= e($crumb[0]) ?></a>
                                <i class="fas fa-chevron-right text-[9px] text-slate-300"></i>
                            <?php else: ?>
                                <span class="font-semibold text-slate-700" aria-current="page"><?= e($crumb[0]) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <?php foreach (flash_all() as $note): ?>
            <?php $isErr = $note['type'] === 'error'; ?>
            <div class="mb-6 rounded border px-4 py-3 text-sm <?= $isErr ? 'bg-red-50 border-red-200 text-red-800' : 'bg-green-50 border-green-200 text-green-800' ?>"
                 role="<?= $isErr ? 'alert' : 'status' ?>">
                <?= e($note['message']) ?>
            </div>
        <?php endforeach; ?>