<?php
/**
 * pay.php
 * The parent's checkout page, shown right after registration.
 *
 * It lists what is owed (registration fee once per child + each child's first month), taken from the
 * database every time the page loads. The amount is never read from the URL or the form.
 * The "Pay with M-Pesa" button calls api/stk_push.php, then pay.js waits for the result.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';

$checkout = $_SESSION['checkout'] ?? null;
if (!$checkout || empty($checkout['student_ids'])) {
    flash('error', 'Start by registering your child. Your payment page is created from your registration.');
    redirect('register.php');
}

$summary = checkout_summary(array_map('intval', $checkout['student_ids']));
$phone   = phone_for_input((string) ($checkout['phone'] ?? ''));

$pageTitle   = 'Pay fees';
$pageScripts = ['assets/js/pay.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-xl mx-auto bg-white rounded-2xl shadow-xl overflow-hidden border border-slate-200">
    <div class="bg-mistgreen p-6 text-white text-center">
        <div class="w-14 h-14 bg-white rounded-full flex items-center justify-center mx-auto mb-3 shadow">
            <i class="fas fa-mobile-screen-button text-mistgreen text-2xl"></i>
        </div>
        <h1 class="text-xl font-bold">Pay with M-Pesa</h1>
        <p class="text-xs text-emerald-100 mt-1">Musketeers Institute of Science and Technology</p>
    </div>

    <div class="p-6 space-y-6">
        <?php if ($summary['total'] <= 0): ?>
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 text-sm">
                <i class="fas fa-circle-check"></i> Everything on this registration is paid. Thank you!
            </div>
            <a href="<?= e(url('index.php')) ?>" class="text-mistnavy underline text-sm">Back to home</a>
        <?php else: ?>
            <div>
                <h2 class="text-sm font-bold uppercase text-slate-600 mb-3">What you are paying for</h2>
                <?php foreach ($summary['children'] as $child): ?>
                    <div class="mb-3 rounded-xl border border-slate-200 overflow-hidden">
                        <p class="bg-slate-50 px-4 py-2 font-bold text-mistnavy text-sm"><?= e($child['name']) ?></p>
                        <table class="w-full text-sm">
                            <?php foreach ($child['lines'] as $line): ?>
                                <tr class="border-t border-slate-100">
                                    <td class="px-4 py-2"><?= e($line['label']) ?></td>
                                    <td class="px-4 py-2 text-right num whitespace-nowrap"><?= e(money($line['amount'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endforeach; ?>
                <div class="flex justify-between items-center text-lg border-t border-slate-200 pt-3">
                    <span class="font-bold text-mistnavy">Total payable</span>
                    <span class="font-extrabold text-mistgreen num"><?= e(money($summary['total'])) ?></span>
                </div>
                <p class="text-xs text-slate-500 mt-2">The registration fee is paid once per child. From next month only the monthly activity fee is charged.</p>
            </div>

            <form id="stk-form" class="space-y-4"
                  data-push-url="<?= e(url('api/stk_push.php')) ?>"
                  data-status-url="<?= e(url('api/stk_status.php')) ?>"
                  data-receipt-url="<?= e(url('receipt.php')) ?>">
                <?= csrf_field() ?>
                <div>
                    <label for="phone" class="block text-xs font-semibold text-slate-600 mb-1">M-Pesa number that will receive the prompt</label>
                    <input id="phone" name="phone" type="tel" required maxlength="20" value="<?= e($phone) ?>"
                           class="w-full px-4 py-2 border rounded-lg text-sm font-semibold text-slate-700">
                </div>
                <button type="submit" id="pay-btn"
                        class="w-full bg-mistgreen hover:bg-emerald-700 text-white font-bold py-3.5 rounded-xl shadow-lg">
                    <i class="fas fa-paper-plane"></i> Pay <?= e(money($summary['total'])) ?> with M-Pesa
                </button>
            </form>

            <div id="status-box" class="hidden rounded-xl p-4 text-sm" role="status" aria-live="polite"></div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
