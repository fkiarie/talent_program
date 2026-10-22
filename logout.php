<?php
/**
 * logout.php
 * Only accepts a POST with a valid CSRF token (the "Log out" button in the
 * navigation bar), so another website cannot log people out by linking here.
 */
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout_user();

    // The old session is gone, so start a fresh one just to carry the notice.
    session_start();
    flash('success', 'You have been logged out.');
}

redirect('login.php');
