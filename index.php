<?php
// index.php - Main Portal Hub
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-8">
    <!-- Hero Banner -->
    <div class="bg-gradient-to-r from-mistnavy via-indigo-900 to-slate-900 rounded-3xl p-8 md:p-12 text-white shadow-2xl relative overflow-hidden border border-mistgold/20">
        <div class="absolute right-0 bottom-0 opacity-10 text-9xl transform translate-x-10 translate-y-10">
            <i class="fas fa-award"></i>
        </div>
        <div class="max-w-2xl relative z-10 space-y-4">
            <span class="bg-mistgreen text-white text-xs font-extrabold uppercase px-3 py-1 rounded-full">Admissions Open 2026</span>
            <h1 class="text-3xl md:text-5xl font-black tracking-tight leading-tight">
                MIST Talent & Skills Development Program
            </h1>
            <p class="text-slate-200 text-sm md:text-base leading-relaxed">
                Welcome to Musketeers Institute of Science and Technology. Register your child for professional training tracks with instant M-Pesa automated billing.
            </p>
            <div class="flex flex-wrap gap-4 pt-2">
                <a href="register.php" class="bg-mistgold hover:bg-amber-400 text-mistnavy font-extrabold px-6 py-3.5 rounded-xl shadow-lg transition flex items-center gap-2 text-sm">
                    <i class="fas fa-user-plus"></i> Register Child Now
                </a>
                <a href="login.php" class="bg-white/10 hover:bg-white/20 text-white border border-white/30 font-bold px-6 py-3.5 rounded-xl transition flex items-center gap-2 text-sm">
                    <i class="fas fa-lock"></i> Staff Sign In
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Info Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-3">
            <div class="w-12 h-12 bg-emerald-50 text-mistgreen rounded-xl flex items-center justify-center text-xl font-bold">
                <i class="fas fa-child"></i>
            </div>
            <h3 class="font-bold text-mistnavy text-lg">Parent Self-Registration</h3>
            <p class="text-xs text-slate-600 leading-relaxed">
                Parents and guardians can register children online instantly, select talent tracks, and settle charges securely via M-Pesa.
            </p>
            <a href="register.php" class="text-mistgreen font-bold text-xs inline-flex items-center gap-1 hover:underline">
                Get Started <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-3">
            <div class="w-12 h-12 bg-amber-50 text-mistgold rounded-xl flex items-center justify-center text-xl font-bold">
                <i class="fas fa-mobile-screen-button"></i>
            </div>
            <h3 class="font-bold text-mistnavy text-lg">Instant M-Pesa Checkout</h3>
            <p class="text-xs text-slate-600 leading-relaxed">
                Automated payment processing with instant STK push simulation and electronic receipt generation.
            </p>
            <span class="text-slate-400 text-xs block">Active Gateway</span>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-3">
            <div class="w-12 h-12 bg-indigo-50 text-mistnavy rounded-xl flex items-center justify-center text-xl font-bold">
                <i class="fas fa-location-dot"></i>
            </div>
            <h3 class="font-bold text-mistnavy text-lg">Visit Our Campus</h3>
            <p class="text-xs text-slate-600 leading-relaxed">
                Mumbi Plaza, 4th Floor, Nakuru. Reach us via phone at +254 700 904 523 or email info@mist.ac.ke.
            </p>
            <span class="text-mistnavy font-bold text-xs block">Nakuru, Kenya</span>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>