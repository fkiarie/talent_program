<?php
/**
 * reserved.php
 * Confirmation shown after a parent chooses "Pay later". Reads the checkout saved in the session,
 * so nobody can open it for someone else's children.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';

$checkout = $_SESSION['checkout'] ?? null;
if (!$checkout || empty($checkout['student_ids'])) {
    redirect('index.php');
}

$summary = checkout_summary(array_map('intval', $checkout['student_ids']));
$phone   = format_phone((string) ($checkout['phone'] ?? ''));

$pageTitle = 'Registration saved';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-xl mx-auto bg-white rounded-2xl shadow-xl overflow-hidden border border-slate-200">
    <div class="bg-mistnavy p-6 text-white text-center">
        <div class="w-14 h-14 bg-white rounded-full flex items-center justify-center mx-auto mb-3 shadow">
            <i class="fas fa-bookmark text-mistnavy text-2xl"></i>
        </div>
        <h1 class="text-xl font-bold">Registration saved</h1>
        <p class="text-xs text-slate-300 mt-1">Payment is still to be made</p>
    </div>

    <div class="p-6 space-y-5 text-sm">
        <?php if ($summary['total'] <= 0): ?>
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 p-4">
                <i class="fas fa-circle-check"></i> Everything on this registration is paid. Thank you!
            </div>
        <?php else: ?>
            <p class="text-slate-700">
                We have saved your registration and noted that you will pay later. Our team will contact you
                <?php if ($phone !== ''): ?>on <strong><?= e($phone) ?></strong><?php endif; ?>
                to arrange payment.
            </p>

            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <?php foreach ($summary['children'] as $child): ?>
                    <div class="flex justify-between gap-4 px-4 py-2 border-b border-slate-100 last:border-0">
                        <span class="font-semibold text-mistnavy"><?= e($child['name']) ?></span>
                        <span class="num whitespace-nowrap"><?= e(money($child['subtotal'])) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="flex justify-between gap-4 px-4 py-3 bg-slate-50 font-bold">
                    <span class="text-mistnavy">Still to pay</span>
                    <span class="num text-mistgreen"><?= e(money($summary['total'])) ?></span>
                </div>
            </div>

            <p class="text-xs text-slate-500">
                You can also call the office on +254 700 904 523, or pay now whenever you are ready.
            </p>

            <a href="<?= e(url('pay.php')) ?>"
               class="block text-center bg-mistgreen hover:bg-emerald-700 text-white font-bold py-3 rounded-xl shadow">
                <i class="fas fa-mobile-screen-button"></i> Pay now with M-Pesa instead
            </a>
        <?php endif; ?>

        <a href="<?= e(url('index.php')) ?>" class="block text-center text-mistnavy underline">Back to home</a>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
