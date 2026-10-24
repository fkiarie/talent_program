<?php
/**
 * pay_later.php
 * The parent chose "Pay later" on the checkout page. Their children stay registered with the fees
 * still owing, and are added to the follow-up list (payment_reservations) for a moderator to chase.
 * POST only (with a CSRF token); it works from the checkout saved in the session at registration.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pay.php');
}
csrf_check();

$checkout = $_SESSION['checkout'] ?? null;
if (!$checkout || empty($checkout['student_ids']) || empty($checkout['guardian_id'])) {
    flash('error', 'Start by registering your child.');
    redirect('register.php');
}

if (!reservations_ready()) {
    flash('error', 'Pay later is not available right now. Please pay now, or visit our office.');
    redirect('pay.php');
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $count = reserve_students($pdo, (int) $checkout['guardian_id'], array_map('intval', $checkout['student_ids']));
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $ex;
}

if ($count === 0) {
    flash('success', 'Everything on this registration is already paid. Thank you!');
    redirect('pay.php');
}

redirect('reserved.php');
