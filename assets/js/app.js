// assets/js/app.js - shared by every page: drop-down menus and the mobile menu.
(function () {
    var dropdowns = document.querySelectorAll('[data-dropdown]');
    var menuBtn = document.getElementById('mobile-menu-btn');
    var menu = document.getElementById('mobile-menu');

    function closeDropdowns(except) {
        dropdowns.forEach(function (btn) {
            var panel = document.getElementById(btn.dataset.dropdown);
            if (panel && panel !== except) {
                panel.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function closeMobile() {
        if (menu && menuBtn) {
            menu.classList.add('hidden');
            menuBtn.setAttribute('aria-expanded', 'false');
            menuBtn.setAttribute('aria-label', 'Open menu');
            menuBtn.innerHTML = '<i class="fas fa-bars"></i>';
        }
    }

    // Drop-downs: click to open, click again / click elsewhere / Esc to close (works on touch screens too)
    dropdowns.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var panel = document.getElementById(btn.dataset.dropdown);
            if (!panel) { return; }
            var willOpen = panel.classList.contains('hidden');
            closeDropdowns(willOpen ? panel : null);
            panel.classList.toggle('hidden', !willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function () { closeDropdowns(null); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeDropdowns(null); closeMobile(); }
    });

    // Mobile menu
    if (menuBtn && menu) {
        menuBtn.addEventListener('click', function () {
            var willOpen = menu.classList.contains('hidden');
            menu.classList.toggle('hidden', !willOpen);
            menuBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menuBtn.setAttribute('aria-label', willOpen ? 'Close menu' : 'Open menu');
            menuBtn.innerHTML = willOpen ? '<i class="fas fa-xmark"></i>' : '<i class="fas fa-bars"></i>';
        });
        // Going back to a wide screen should not leave the mobile menu stuck open
        window.addEventListener('resize', function () { if (window.innerWidth >= 1024) { closeMobile(); } });
    }
})();