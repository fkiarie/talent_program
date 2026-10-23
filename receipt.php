<?php
/**
 * receipt.php?code=QJK1234567
 * A printable receipt for one M-Pesa payment, showing exactly which charges it paid.
 * Visible to logged-in staff, and to the visitor whose payment it was (their session remembers the code).
 */
require_once __DIR__ . '/includes/auth.php';

$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$code = preg_match('/^[A-Z0-9]{6,15}$/', $code) ? $code : '';

$allowed = current_user() !== null || in_array($code, $_SESSION['receipt_codes'] ?? [], true);

$payment = null;
if ($code !== '' && $allowed) {
    $stmt = db()->prepare(
        'SELECT p.*, g.full_name AS guardian_name
           FROM payments p
           JOIN students s ON s.id = p.student_id
           JOIN guardians g ON g.id = s.guardian_id
          WHERE p.mpesa_code = ?'
    );
    $stmt->execute([$code]);
    $payment = $stmt->fetch() ?: null;
}

if (!$payment) {
    http_response_code(404);
    $pageTitle = 'Receipt not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="bg-white rounded-lg shadow p-6 max-w-xl mx-auto"><h1 class="text-xl font-bold mb-2">Receipt not found</h1>'
       . '<p class="text-slate-600 text-sm">This receipt does not exist, or you are not allowed to see it.</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$al = db()->prepare(
    "SELECT a.amount, c.charge_type, s.full_name AS student_name, bp.label AS period_label,
            act.name AS activity_name, lv.proficiency
       FROM payment_allocations a
       JOIN charges c ON c.id = a.charge_id
       JOIN students s ON s.id = c.student_id
       LEFT JOIN billing_periods bp ON bp.id = c.billing_period_id
       LEFT JOIN enrollments en ON en.id = c.enrollment_id
       LEFT JOIN activity_levels lv ON lv.id = en.activity_level_id
       LEFT JOIN activities act ON act.id = lv.activity_id
      WHERE a.payment_id = ?
      ORDER BY s.full_name, (c.charge_type = 'REGISTRATION') DESC, c.id"
);
$al->execute([$payment['id']]);
$lines = $al->fetchAll();

$applied = 0.0;
foreach ($lines as $l) {
    $applied += (float) $l['amount'];
}
$credit = round((float) $payment['amount'] - $applied, 2);

$pageTitle = 'Receipt ' . $payment['mpesa_code'];
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-xl mx-auto bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">
    <div class="bg-mistnavy text-white p-6 text-center border-b-4 border-mistgold">
        <h1 class="text-xl font-bold">Payment receipt</h1>
        <p class="text-xs text-slate-300 mt-1">Musketeers Institute of Science and Technology &middot; Talent Program</p>
    </div>

    <div class="p-6 space-y-5 text-sm">
        <?php if ($payment['status'] !== 'VALID'): ?>
            <p class="rounded bg-red-50 border border-red-200 text-red-800 px-3 py-2 font-semibold">This payment has been voided.</p>
        <?php endif; ?>

        <dl class="grid grid-cols-2 gap-y-2">
            <dt class="text-slate-500">M-Pesa code</dt><dd class="font-mono font-bold text-right"><?= e($payment['mpesa_code']) ?></dd>
            <dt class="text-slate-500">Date</dt><dd class="text-right"><?= e(date('d M Y, H:i', strtotime($payment['paid_on']))) ?></dd>
            <dt class="text-slate-500">Paid by</dt><dd class="text-right"><?= e($payment['guardian_name']) ?></dd>
            <?php if ($payment['payer_phone']): ?>
                <dt class="text-slate-500">Phone</dt><dd class="text-right"><?= e(format_phone($payment['payer_phone'])) ?></dd>
            <?php endif; ?>
        </dl>

        <table class="w-full">
            <thead><tr class="text-left text-slate-500 border-b"><th class="py-2">Paid for</th><th class="py-2 text-right">Amount</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($lines as $l): ?>
                    <?php
                        $what = $l['charge_type'] === 'REGISTRATION'
                            ? 'Registration fee'
                            : ($l['activity_name'] ? $l['activity_name'] . ' (' . $l['proficiency'] . ')' : 'Activity fee')
                              . ' - ' . ($l['period_label'] ?: 'first month');
                    ?>
                    <tr>
                        <td class="py-2"><span class="font-semibold"><?= e($l['student_name']) ?></span><br><span class="text-slate-500"><?= e($what) ?></span></td>
                        <td class="py-2 text-right num whitespace-nowrap"><?= e(money($l['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($credit > 0.004): ?>
                    <tr><td class="py-2 text-slate-500">Extra amount kept as credit</td><td class="py-2 text-right num"><?= e(money($credit)) ?></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot><tr class="border-t-2 border-slate-300 font-bold text-base">
                <td class="py-3">Total received</td><td class="py-3 text-right num"><?= e(money($payment['amount'])) ?></td>
            </tr></tfoot>
        </table>

        <div class="flex justify-between items-center print:hidden">
            <button onclick="window.print()" class="bg-mistnavy text-white rounded px-4 py-2 text-sm font-semibold"><i class="fas fa-print"></i> Print</button>
            <a href="<?= e(url('index.php')) ?>" class="text-slate-600 underline">Back to home</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
