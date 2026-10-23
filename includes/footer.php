    </main>

    <footer class="text-center text-sm text-slate-500 py-4">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>
    </footer>

    <script src="<?= url('assets/js/app.js') ?>"></script>

    <?php /* Pages can load their own script by setting $pageScripts = ['assets/js/file.js'] before including the header. */ ?>
    <?php foreach (($pageScripts ?? []) as $script): ?>
        <script src="<?= e(url($script)) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
