<?php
/**
 * period.php?id=3
 * Admin only: one billing period. Shows every charge in it and lets the admin
 *   - generate charges for students enrolled since the period was created, or
 *   - delete the period (only while no payment has been applied to its charges).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';

$me = require_role('ADMIN');

$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT id, label, start_date, end_date FROM billing_periods WHERE id = ?');
$stmt->execute([$id]);
$period = $stmt->fetch();

if (!$period) {
    http_response_code(404);
    $pageTitle = 'Period not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="bg-white rounded-lg shadow p-6 max-w-xl">';
    echo '<h1 class="text-xl font-bold mb-2">Billing period not found</h1>';
    echo '<a class="text-indigo-700 underline" href="' . e(url('periods.php')) . '">Back to billing periods</a>';
    echo '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $period['label'];
$back      = 'period.php?id=' . (int) $period['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');

    // ---- Create any charges that are missing -----------------------------------
    if ($action === 'generate') {
        $created = generate_period_charges((int) $period['id']);

        flash('success', $created > 0
            ? $created . ' new charge' . ($created === 1 ? '' : 's') . ' created.'
            : 'Nothing to add: every eligible student already has a charge for this period.');
        redirect($back);
    }

    // ---- Delete the whole period (a safety net for a mistake) -----------------------
    if ($action === 'delete') {
        $used = db()->prepare(
            'SELECT COUNT(*)
               FROM payment_allocations pa
               JOIN charges c ON c.id = pa.charge_id
              WHERE c.billing_period_id = ?'
        );
        $used->execute([$period['id']]);

        if ((int) $used->fetchColumn() > 0) {
            flash('error', 'This period cannot be deleted because payments have already been applied to its charges.');
            redirect($back);
        }

        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM charges WHERE billing_period_id = ?')->execute([$period['id']]);
        $pdo->prepare('DELETE FROM billing_periods WHERE id = ?')->execute([$period['id']]);
        $pdo->commit();

        flash('success', 'Period "' . $period['label'] . '" and its charges were deleted.');
        redirect('periods.php');
    }
}

// ---- Charges in this period ---------------------------------------------------------
$ch = db()->prepare(
    "SELECT c.id, c.amount, s.id AS student_id, s.full_name, a.name AS activity_name, l.proficiency,
            COALESCE((SELECT SUM(pa.amount)
                        FROM payment_allocations pa
                        JOIN payments p ON p.id = pa.payment_id
                       WHERE pa.charge_id = c.id AND p.status = 'VALID'), 0) AS paid
       FROM charges c
       JOIN students s          ON s.id = c.student_id
       JOIN enrollments e       ON e.id = c.enrollment_id
       JOIN activity_levels l   ON l.id = e.activity_level_id
       JOIN activities a        ON a.id = l.activity_id
      WHERE c.billing_period_id = ?
      ORDER BY s.full_name, a.name"
);
$ch->execute([$period['id']]);
$charges = $ch->fetchAll();

$total = 0.0;
$paid  = 0.0;
foreach ($charges as $c) {
    $total += (float) $c['amount'];
    $paid  += min((float) $c['paid'], (float) $c['amount']);
}

// Active enrolments with no charge in this period yet, split by WHY they have none.
//   "missing"  = they joined on or before the start date, so "Generate" will add them.
//   "waiting"  = they joined after the start date, so they start next period.
$pending = db()->prepare(
    "SELECT
        COALESCE(SUM(e.enrolled_on <= ?), 0) AS missing,
        COALESCE(SUM(e.enrolled_on >  ?), 0) AS waiting
       FROM enrollments e
      WHERE e.status = 'ACTIVE'
        AND NOT EXISTS (SELECT 1 FROM charges c
                         WHERE c.enrollment_id = e.id AND c.billing_period_id = ?)"
);
$pending->execute([$period['start_date'], $period['start_date'], $period['id']]);
$counts  = $pending->fetch();
$missing = (int) $counts['missing'];
$waiting = (int) $counts['waiting'];

require __DIR__ . '/includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold"><?= e($period['label']) ?></h1>
        <p class="text-slate-600 text-sm">
            <?= e(date('d M Y', strtotime($period['start_date']))) ?> &ndash; <?= e(date('d M Y', strtotime($period['end_date']))) ?>
        </p>
    </div>
    <a href="<?= url('periods.php') ?>" class="text-indigo-700 hover:underline text-sm">&larr; All periods</a>
</div>

<div class="grid gap-4 sm:grid-cols-3 mb-6">
    <div class="bg-white rounded-lg shadow p-5">
        <p class="text-sm text-slate-500">Charges</p>
        <p class="text-2xl font-semibold num"><?= count($charges) ?></p>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <p class="text-sm text-slate-500">Total billed</p>
        <p class="text-2xl font-semibold num"><?= e(money($total)) ?></p>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <p class="text-sm text-slate-500">Collected so far</p>
        <p class="text-2xl font-semibold num"><?= e(money($paid)) ?></p>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-5 mb-6 space-y-3 text-sm">
    <?php if ($missing > 0): ?>
        <p class="text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">
            <strong><?= $missing ?></strong> enrolment<?= $missing === 1 ? '' : 's' ?> that began on or before the start date
            <?= $missing === 1 ? 'has' : 'have' ?> no charge in this period yet.
        </p>
    <?php else: ?>
        <p class="text-green-800">Every eligible enrolment has a charge in this period.</p>
    <?php endif; ?>

    <?php if ($waiting > 0): ?>
        <p class="text-slate-600">
            <?= $waiting ?> enrolment<?= $waiting === 1 ? '' : 's' ?> began after this period started, so
            <?= $waiting === 1 ? 'it' : 'they' ?> will first be billed in the next period.
        </p>
    <?php endif; ?>

    <div class="flex flex-wrap gap-3 pt-1">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="generate">
            <button class="bg-indigo-700 hover:bg-indigo-800 text-white rounded px-4 py-2">Create missing charges</button>
        </form>

        <form method="post"
              onsubmit="return confirm('Delete this period and all of its charges? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button class="border border-red-300 text-red-700 hover:bg-red-50 rounded px-4 py-2">Delete period</button>
        </form>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
                <th class="px-4 py-3">Student</th>
                <th class="px-4 py-3">Activity</th>
                <th class="px-4 py-3">Level</th>
                <th class="px-4 py-3 text-right">Amount</th>
                <th class="px-4 py-3 text-right">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (!$charges): ?>
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-slate-500">No charges in this period.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($charges as $c): ?>
                <?php $amt = (float) $c['amount']; $pd = (float) $c['paid']; ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium">
                        <a class="text-indigo-700 hover:underline" href="<?= url('student.php?id=' . (int) $c['student_id']) ?>">
                            <?= e($c['full_name']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-3"><?= e($c['activity_name']) ?></td>
                    <td class="px-4 py-3"><?= e($c['proficiency']) ?></td>
                    <td class="px-4 py-3 text-right num whitespace-nowrap"><?= e(money($amt)) ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <?php if ($pd >= $amt - 0.004): ?>
                            <span class="text-green-700">Paid</span>
                        <?php elseif ($pd > 0): ?>
                            <span class="text-amber-700">Part paid (<?= e(money($pd)) ?>)</span>
                        <?php else: ?>
                            <span class="text-red-700">Unpaid</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
