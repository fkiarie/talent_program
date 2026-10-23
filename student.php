<?php
/**
 * student.php?id=7
 * One student's profile: their details, guardian, charges and balance.
 * (Activities appear here in Phase 4, payments in Phase 6.)
 */
require_once __DIR__ . '/includes/auth.php';

$user = require_login();

require_once __DIR__ . '/includes/billing.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT s.*, g.full_name AS g_name, g.id_number AS g_id_number,
            g.phone AS g_phone, g.alt_phone AS g_alt_phone,
            u.full_name AS registered_by_name
       FROM students s
       JOIN guardians g ON g.id = s.guardian_id
       LEFT JOIN users u ON u.id = s.registered_by
      WHERE s.id = ?'
);
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    $pageTitle = 'Student not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="bg-white rounded-lg shadow p-6 max-w-xl">';
    echo '<h1 class="text-xl font-bold mb-2">Student not found</h1>';
    echo '<a class="text-indigo-700 underline" href="' . e(url('students.php')) . '">Back to students</a>';
    echo '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $student['full_name'];

// ---- Enrol in / drop an activity (Admins and Moderators) ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');
    $back   = 'student.php?id=' . (int) $student['id'];

    if ($action === 'enrol') {
        $levelId = (int) post_str('activity_level_id');

        // The level must exist and its activity must be switched on.
        $lv = db()->prepare(
            'SELECT l.id, l.activity_id, l.proficiency, l.monthly_fee, a.name
               FROM activity_levels l
               JOIN activities a ON a.id = l.activity_id
              WHERE l.id = ? AND a.is_active = 1'
        );
        $lv->execute([$levelId]);
        $level = $lv->fetch();

        if (!$level) {
            flash('error', 'Choose an activity and level from the list.');
        } elseif (REQUIRE_REGISTRATION_PAID && !registration_is_paid((int) $student['id'])) {
            flash('error', 'The registration fee must be paid in full before enrolling in an activity.');
        } else {
            // Look at every enrolment this student has in THIS activity (any level).
            $ex = db()->prepare(
                'SELECT e.id, e.status, e.activity_level_id
                   FROM enrollments e
                   JOIN activity_levels l ON l.id = e.activity_level_id
                  WHERE e.student_id = ? AND l.activity_id = ?'
            );
            $ex->execute([$student['id'], $level['activity_id']]);
            $existing = $ex->fetchAll();

            $activeRow = null;
            $sameLevelRow = null;
            foreach ($existing as $row) {
                if ($row['status'] === 'ACTIVE') {
                    $activeRow = $row;
                }
                if ((int) $row['activity_level_id'] === (int) $level['id']) {
                    $sameLevelRow = $row;
                }
            }

            if ($activeRow !== null && (int) $activeRow['activity_level_id'] === (int) $level['id']) {
                flash('error', 'This student is already enrolled in ' . $level['name'] . ' (' . $level['proficiency'] . ').');
            } elseif ($activeRow !== null) {
                flash('error', 'This student is already enrolled in ' . $level['name'] . ' at another level. Drop that enrolment first to change level.');
            } else {
                $pdo = db();
                try {
                    // Enrolment and the first month's charge are saved together or not at all.
                    $pdo->beginTransaction();

                    if ($sameLevelRow !== null) {
                        // They were enrolled here before and dropped: switch it back on with today's fee.
                        $pdo->prepare(
                            "UPDATE enrollments SET status = 'ACTIVE', monthly_fee = ?, enrolled_on = CURRENT_DATE WHERE id = ?"
                        )->execute([$level['monthly_fee'], $sameLevelRow['id']]);
                        $enrollmentId = (int) $sameLevelRow['id'];
                    } else {
                        // monthly_fee is a COPY of the level's fee at this moment (the "snapshot").
                        $pdo->prepare(
                            'INSERT INTO enrollments (student_id, activity_level_id, monthly_fee) VALUES (?, ?, ?)'
                        )->execute([$student['id'], $level['id'], $level['monthly_fee']]);
                        $enrollmentId = (int) $pdo->lastInsertId();
                    }

                    // Bill the joining month now, so it shows up in what the family owes.
                    create_first_month_charge($pdo, (int) $student['id'], $enrollmentId, $level['monthly_fee']);
                    $pdo->commit();
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $ex;
                }
                flash('success', 'Enrolled in ' . $level['name'] . ' (' . $level['proficiency'] . ') at ' . money($level['monthly_fee']) . ' per month.');
            }
        }
        redirect($back);
    }

    if ($action === 'drop') {
        $enrollmentId = (int) post_str('enrollment_id');

        // The "student_id = ?" part makes sure nobody can drop another student's enrolment by editing the form.
        $up = db()->prepare(
            "UPDATE enrollments SET status = 'DROPPED' WHERE id = ? AND student_id = ? AND status = 'ACTIVE'"
        );
        $up->execute([$enrollmentId, $student['id']]);

        flash($up->rowCount() > 0 ? 'success' : 'error',
              $up->rowCount() > 0 ? 'Enrolment dropped. No further monthly charges will be created for it.'
                                  : 'That enrolment could not be dropped.');
        redirect($back);
    }
}

// Enrolments (active first)
$en = db()->prepare(
    "SELECT e.id, e.monthly_fee, e.status, e.enrolled_on, a.name AS activity_name, l.proficiency
       FROM enrollments e
       JOIN activity_levels l ON l.id = e.activity_level_id
       JOIN activities a ON a.id = l.activity_id
      WHERE e.student_id = ?
      ORDER BY e.status, a.name"
);
$en->execute([$student['id']]);
$enrollments = $en->fetchAll();

// Every level of every active activity, for the "enrol" drop-down.
$levelRows = db()->query(
    "SELECT l.id, l.proficiency, l.monthly_fee, a.name AS activity_name
       FROM activity_levels l
       JOIN activities a ON a.id = l.activity_id
      WHERE a.is_active = 1
      ORDER BY a.name, FIELD(l.proficiency, 'Beginner', 'Intermediate', 'Advanced')"
)->fetchAll();

$levelsByActivity = [];
foreach ($levelRows as $row) {
    $levelsByActivity[$row['activity_name']][] = $row;
}

$registrationBlocked = REQUIRE_REGISTRATION_PAID && !registration_is_paid((int) $student['id']);

// Other children of the same guardian (brothers and sisters, usually)
$sib = db()->prepare('SELECT id, full_name FROM students WHERE guardian_id = ? AND id <> ? ORDER BY full_name');
$sib->execute([$student['guardian_id'], $student['id']]);
$siblings = $sib->fetchAll();

// Charges, each with how much of it has been paid off so far.
$ch = db()->prepare(
    "SELECT c.id, c.charge_type, c.amount, c.created_on, bp.label AS period_label,
            COALESCE((SELECT SUM(a.amount)
                        FROM payment_allocations a
                        JOIN payments p ON p.id = a.payment_id
                       WHERE a.charge_id = c.id AND p.status = 'VALID'), 0) AS paid
       FROM charges c
       LEFT JOIN billing_periods bp ON bp.id = c.billing_period_id
      WHERE c.student_id = ?
      ORDER BY c.created_on, c.id"
);
$ch->execute([$student['id']]);
$charges = $ch->fetchAll();

// Totals: balance = charged - paid. Negative means the family has credit.
$totalCharged = 0.0;
foreach ($charges as $c) {
    $totalCharged += (float) $c['amount'];
}

// "Paid" means money that has been applied to THIS student's charges. A payment covering
// several brothers and sisters is split between them, so we count allocations, not payments.
$totalPaid = student_paid_total((int) $student['id']);

// Payments that touched this student's charges (for the receipts list)
$pl = db()->prepare(
    "SELECT p.id, p.mpesa_code, p.paid_on, p.status, SUM(a.amount) AS applied
       FROM payments p
       JOIN payment_allocations a ON a.payment_id = p.id
       JOIN charges c ON c.id = a.charge_id
      WHERE c.student_id = ?
      GROUP BY p.id, p.mpesa_code, p.paid_on, p.status
      ORDER BY p.paid_on DESC, p.id DESC"
);
$pl->execute([$student['id']]);
$payments = $pl->fetchAll();

$balance = $totalCharged - $totalPaid;

require __DIR__ . '/includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold"><?= e($student['full_name']) ?></h1>
        <p class="text-slate-600 text-sm">
            <?= (int) age_years($student['date_of_birth']) ?> years old &middot;
            registered <?= e(date('d M Y', strtotime($student['registered_on']))) ?>
            <?php if ($student['registered_by_name']): ?>by <?= e($student['registered_by_name']) ?><?php endif; ?>
        </p>
    </div>
    <a href="<?= url('students.php') ?>" class="text-indigo-700 hover:underline text-sm">&larr; All students</a>
</div>

<div class="grid gap-6 lg:grid-cols-3">

    <!-- Details -->
    <div class="lg:col-span-2 space-y-6">

        <section class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Student details</h2>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-slate-500">Date of birth</dt>
                     <dd><?= e(date('d M Y', strtotime($student['date_of_birth']))) ?></dd></div>
                <div><dt class="text-slate-500">Nationality</dt>
                     <dd><?= e($student['nationality']) ?></dd></div>
                <div><dt class="text-slate-500">ID / passport number</dt>
                     <dd><?= $student['id_passport_no'] ? e($student['id_passport_no']) : '<span class="text-slate-400">None</span>' ?></dd></div>
            </dl>
        </section>

        <section class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Parent / guardian</h2>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-slate-500">Name</dt>
                     <dd><?= e($student['g_name']) ?> (<?= e($student['relation']) ?>)</dd></div>
                <div><dt class="text-slate-500">ID number</dt>
                     <dd><?= e($student['g_id_number']) ?></dd></div>
                <div><dt class="text-slate-500">Phone</dt>
                     <dd><?= e(format_phone($student['g_phone'])) ?></dd></div>
                <div><dt class="text-slate-500">Alternative phone</dt>
                     <dd><?= $student['g_alt_phone'] ? e(format_phone($student['g_alt_phone'])) : '<span class="text-slate-400">None</span>' ?></dd></div>
            </dl>

            <?php if ($siblings): ?>
                <p class="mt-4 text-sm text-slate-600">
                    Other children of this guardian:
                    <?php foreach ($siblings as $n => $sb): ?>
                        <?= $n > 0 ? ', ' : '' ?><a class="text-indigo-700 hover:underline"
                           href="<?= url('student.php?id=' . (int) $sb['id']) ?>"><?= e($sb['full_name']) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </section>

        <section class="bg-white rounded-lg shadow overflow-x-auto">
            <h2 class="text-lg font-semibold p-6 pb-3">Activities</h2>

            <?php if ($enrollments): ?>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-6 py-3">Activity</th>
                            <th class="px-6 py-3">Level</th>
                            <th class="px-6 py-3 text-right">Monthly fee</th>
                            <th class="px-6 py-3">Since</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($enrollments as $en): ?>
                            <tr class="<?= $en['status'] === 'ACTIVE' ? '' : 'text-slate-400' ?>">
                                <td class="px-6 py-3 font-medium"><?= e($en['activity_name']) ?></td>
                                <td class="px-6 py-3"><?= e($en['proficiency']) ?></td>
                                <td class="px-6 py-3 text-right num whitespace-nowrap"><?= e(money($en['monthly_fee'])) ?></td>
                                <td class="px-6 py-3 whitespace-nowrap"><?= e(date('d M Y', strtotime($en['enrolled_on']))) ?></td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">
                                    <?php if ($en['status'] === 'ACTIVE'): ?>
                                        <form method="post" class="inline"
                                              onsubmit="return confirm('Drop this enrolment? No more monthly charges will be created for it.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="drop">
                                            <input type="hidden" name="enrollment_id" value="<?= (int) $en['id'] ?>">
                                            <button class="text-red-700 hover:underline">Drop</button>
                                        </form>
                                    <?php else: ?>
                                        Dropped
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="px-6 pb-3 text-sm text-slate-500">Not enrolled in any activity yet.</p>
            <?php endif; ?>

            <div class="p-6 border-t border-slate-100">
                <?php if ($registrationBlocked): ?>
                    <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                        The registration fee must be paid in full before this student can be enrolled in an activity.
                    </p>
                <?php elseif (!$levelsByActivity): ?>
                    <p class="text-sm text-slate-500">
                        There are no activities to enrol in yet.
                        <?php if ($user['role'] === 'ADMIN'): ?>
                            <a class="text-indigo-700 underline" href="<?= url('activities.php') ?>">Add an activity</a>.
                        <?php else: ?>
                            Ask an admin to add them.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <form method="post" class="flex flex-wrap items-end gap-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="enrol">
                        <div class="flex-1 min-w-[16rem]">
                            <label for="activity_level_id" class="block text-sm font-medium mb-1">Enrol in an activity</label>
                            <select id="activity_level_id" name="activity_level_id" required
                                    class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Choose activity and level...</option>
                                <?php foreach ($levelsByActivity as $activityName => $rows): ?>
                                    <optgroup label="<?= e($activityName) ?>">
                                        <?php foreach ($rows as $row): ?>
                                            <option value="<?= (int) $row['id'] ?>">
                                                <?= e($row['proficiency']) ?> &ndash; <?= e(money($row['monthly_fee'])) ?> / month
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="bg-indigo-700 hover:bg-indigo-800 text-white font-medium rounded px-5 py-2">Enrol</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="bg-white rounded-lg shadow overflow-x-auto">
            <h2 class="text-lg font-semibold p-6 pb-3">Charges</h2>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-6 py-3">Charge</th>
                        <th class="px-6 py-3">Date</th>
                        <th class="px-6 py-3 text-right">Amount</th>
                        <th class="px-6 py-3 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($charges as $c): ?>
                        <?php
                            $amount = (float) $c['amount'];
                            $paid   = (float) $c['paid'];
                            $label  = $c['charge_type'] === 'REGISTRATION'
                                ? 'Registration fee (one-time)'
                                : 'Activity fee' . ($c['period_label'] ? ' - ' . $c['period_label'] : '');
                        ?>
                        <tr>
                            <td class="px-6 py-3"><?= e($label) ?></td>
                            <td class="px-6 py-3 whitespace-nowrap"><?= e(date('d M Y', strtotime($c['created_on']))) ?></td>
                            <td class="px-6 py-3 text-right num"><?= e(money($amount)) ?></td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                <?php if ($paid >= $amount - 0.004): ?>
                                    <span class="text-green-700">Paid</span>
                                <?php elseif ($paid > 0): ?>
                                    <span class="text-amber-700">Part paid (<?= e(money($paid)) ?>)</span>
                                <?php else: ?>
                                    <span class="text-red-700">Unpaid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="bg-white rounded-lg shadow overflow-x-auto">
            <h2 class="text-lg font-semibold p-6 pb-3">Payments</h2>
            <?php if ($payments): ?>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-6 py-3">M-Pesa code</th>
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3 text-right">Applied to this student</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($payments as $pm): ?>
                            <tr class="<?= $pm['status'] === 'VALID' ? '' : 'text-slate-400 line-through' ?>">
                                <td class="px-6 py-3 font-mono"><?= e($pm['mpesa_code']) ?></td>
                                <td class="px-6 py-3 whitespace-nowrap"><?= e(date('d M Y H:i', strtotime($pm['paid_on']))) ?></td>
                                <td class="px-6 py-3 text-right num"><?= e(money($pm['applied'])) ?></td>
                                <td class="px-6 py-3 text-right">
                                    <a class="text-indigo-700 hover:underline" href="<?= e(url('receipt.php?code=' . urlencode($pm['mpesa_code']))) ?>">Receipt</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="px-6 pb-6 text-sm text-slate-500">No payments yet.</p>
            <?php endif; ?>
        </section>
    </div>

    <!-- Account summary -->
    <aside>
        <section class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Account</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Total charged</dt>
                     <dd class="num"><?= e(money($totalCharged)) ?></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Total paid</dt>
                     <dd class="num"><?= e(money($totalPaid)) ?></dd></div>
                <div class="flex justify-between border-t pt-2 font-semibold">
                    <dt>Balance</dt>
                    <dd class="num">
                        <?php if ($balance > 0.004): ?>
                            <span class="text-red-700"><?= e(money($balance)) ?> due</span>
                        <?php elseif ($balance < -0.004): ?>
                            <span class="text-blue-700"><?= e(money(-$balance)) ?> credit</span>
                        <?php else: ?>
                            <span class="text-green-700">Paid up</span>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        </section>
    </aside>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
