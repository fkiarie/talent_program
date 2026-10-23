<?php
// includes/footer.php - Overhauled Footer Component
?>
    </main>

    <footer class="bg-mistnavy text-slate-300 text-xs py-8 border-t-4 border-mistgold mt-auto">
        <div class="max-w-7xl mx-auto px-4 grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div>
                <h4 class="font-bold text-white text-sm mb-2 uppercase tracking-wider text-mistgold">Musketeers Institute</h4>
                <p class="text-slate-300 leading-relaxed">
                    Nurturing professional youth talents in technology, arts, and sports through rigorous academic and practical excellence.
                </p>
            </div>
            <div>
                <h4 class="font-bold text-white text-sm mb-2 uppercase tracking-wider text-mistgold">Contact Center</h4>
                <p class="mb-1"><i class="fas fa-location-dot text-mistgold mr-2"></i> Mumbi Plaza, 4th Floor, Nakuru</p>
                <p class="mb-1"><i class="fas fa-phone text-mistgold mr-2"></i> +254 700 904 523</p>
                <p><i class="fas fa-envelope text-mistgold mr-2"></i> info@mist.ac.ke</p>
            </div>
            <div>
                <h4 class="font-bold text-white text-sm mb-2 uppercase tracking-wider text-mistgold">Quick Links</h4>
                <ul class="space-y-1">
                    <li><a href="register.php" class="hover:text-mistgold transition">Register Your Child</a></li>
                    <li><a href="login.php" class="hover:text-mistgold transition">Staff Portal Login</a></li>
                    <li><a href="students.php" class="hover:text-mistgold transition">Student Records</a></li>
                </ul>
            </div>
        </div>
        <div class="max-w-7xl mx-auto px-4 border-t border-slate-700 pt-4 text-center text-slate-400">
            <p>&copy; <?= date('Y') ?> Musketeers Institute of Science and Technology (MIST). All rights reserved.</p>
        </div>
    </footer>
</body>
</html>