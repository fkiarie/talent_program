<?php
// includes/header.php - Overhauled Global Layout Header for MIST
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$is_logged_in = isset($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'GUEST';
$user_name = $_SESSION['full_name'] ?? 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MIST Talent Program Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        mistnavy: '#262262',
                        mistgold: '#FFC000',
                        mistgreen: '#6dad13',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen flex flex-col">

    <!-- Top Contact Bar -->
    <div class="bg-mistnavy text-slate-300 text-xs py-2 border-b border-mistgold/30">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-4">
                <span><i class="fas fa-location-dot text-mistgold mr-1"></i> Mumbi Plaza, 4th Floor, Nakuru</span>
                <span><i class="fas fa-phone text-mistgold mr-1"></i> +254 700 904 523</span>
            </div>
            <div>
                <span><i class="fas fa-envelope text-mistgold mr-1"></i> info@mist.ac.ke</span>
            </div>
        </div>
    </div>

    <!-- Main Navigation Bar -->
    <header class="bg-white shadow-md sticky top-0 z-50 border-b-2 border-mistgold">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-mistnavy text-mistgold rounded-lg flex items-center justify-center font-black text-lg shadow">
                    M
                </div>
                <div>
                    <a href="index.php" class="font-extrabold text-mistnavy text-base md:text-lg tracking-tight hover:text-mistgreen transition">
                        MIST Talent Program
                    </a>
                    <p class="text-[10px] text-slate-500 font-semibold uppercase tracking-wider">Musketeers Institute of Science & Tech</p>
                </div>
            </div>

            <nav class="hidden md:flex items-center space-x-6 text-sm font-medium">
                <a href="index.php" class="text-slate-700 hover:text-mistnavy transition">Home</a>
                <a href="register.php" class="text-mistgreen font-bold hover:underline flex items-center gap-1">
                    <i class="fas fa-user-plus"></i> Register Child
                </a>
                <?php if ($is_logged_in): ?>
                    <a href="students.php" class="text-slate-700 hover:text-mistnavy transition">Students Directory</a>
                    <a href="activities.php" class="text-slate-700 hover:text-mistnavy transition">Activities</a>
                    <?php if ($user_role === 'ADMIN'): ?>
                        <a href="users.php" class="text-slate-700 hover:text-mistnavy transition">Users</a>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>

            <div class="flex items-center space-x-3">
                <?php if ($is_logged_in): ?>
                    <div class="text-right hidden sm:block">
                        <p class="text-xs font-bold text-mistnavy"><?= htmlspecialchars($user_name) ?></p>
                        <p class="text-[10px] bg-mistgold/20 text-yellow-800 px-2 py-0.5 rounded font-bold uppercase inline-block"><?= htmlspecialchars($user_role) ?></p>
                    </div>
                    <a href="logout.php" class="bg-rose-50 text-rose-600 hover:bg-rose-100 p-2 rounded-lg text-xs font-bold transition" title="Logout">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                <?php else: ?>
                    <a href="login.php" class="bg-mistnavy text-white hover:bg-indigo-950 px-4 py-2 rounded-lg text-xs font-bold shadow transition flex items-center gap-2">
                        <i class="fas fa-lock"></i> Staff Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="flex-grow max-w-7xl mx-auto px-4 py-8 w-full">