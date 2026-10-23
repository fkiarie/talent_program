<?php
/**
 * periods.php
 * Admin only: list billing periods and create a new one.
 *
 * A billing period is the stretch of time one set of monthly charges covers: a calendar
 * month, or a shorter 3-week holiday block. Creating a period also creates the activity
 * charges for it, in the same step.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';

$me = require_role('ADMIN');

$pageTitle   = 'Billing periods';
$pageScripts = ['assets/js/period_form.js'];
$errors      = [];
$old         = ['label' => '', 'start_date' => '', 'end_date' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach (array_keys($old) as $field) {
        $old[$field] = post_str($field);
    }

    // ---- Validate ---------------------------------------------------------
    if ($old['label'] === '' || mb_strlen($old['label']) > 60) {
        $errors[] = 'Enter a label for the period (up to 60 characters), e.g. "December 2026".';
    }

    $start = parse_date($old['start_date']);
    $end   = parse_date($old['end_date']);

    if ($start === null) {
        $errors[] = 'Enter a valid start date.';
    }
    if ($end === null) {
        $errors[] = 'Enter a valid end date.';
    }
    if ($start !== null && $end !== null) {
        $days = (int) $start->diff($end)->days + 1;     // counting both the first and last day

        if ($end <= $start) {
            $errors[] = 'The end date must be after the start date.';
        } elseif ($days > 62) {
            $errors[] = 'A billing period can be at most about two months (62 days). Please check the dates.';
        }
    }

    if (!$errors) {
        $dup = db()->prepare('SELECT 1 FROM billing_periods WHERE label = ?');
        $dup->execute([$old['label']]);
        if ($dup->fetchColumn()) {
            $errors[] = 'A period called "' . $old['label'] . '" already exists. Use a different label.';
        }

        // Overlapping periods would bill the same days twice.
        if ($clash = period_overlap($start->format('Y-m-d'), $end->format('Y-m-d'))) {
            $errors[] = 'These dates overlap the existing period "' . $clash['label'] . '".';
        }
    }

    // ---- Save the period and its charges together -----------------------------
    if (!$errors) {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT INTO billing_periods (label, start_date, end_date) VALUES (?, ?, ?)')
                ->execute([$old['label'], $start->format('Y-m-d'), $end->format('Y-m-d')]);
            $periodId = (int) $pdo->lastInsertId();

            $created = generate_period_charges($periodId);

            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                $errors[] = 'A period with that label already exists.';
            } else {
                throw $ex;
            }
        }

        if (!$errors) {
            flash('success', '"' . $old['label'] . '" created with ' . $created . ' activity charge' . ($created === 1 ? '' : 's') . '.');
            redirect('period.php?id=' . $periodId);
        }
    }
}

// ---- Load the list ---------------------------------------------------------------
$periods = db()->query(
    "SELECT bp.id, bp.label, bp.start_date, bp.end_date,
            (SELECT COUNT(*) FROM charges c WHERE c.billing_period_id = bp.id) AS charge_count,
            (SELECT COALESCE(SUM(c.amount), 0) FROM charges c WHERE c.billing_period_id = bp.id) AS charge_total
       FROM billing_periods bp
      ORDER BY bp.start_date DESC"
)->fetchAll();

$inputClass = 'w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500';

require __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-2">Billing periods</h1>
<p class="text-slate-600 text-sm mb-6">
    A period covers one round of monthly fees: a calendar month or a 3-week holiday block (both cost the full monthly fee).
    Students who join after a period has started are first billed in the next period.
</p>

<div class="grid gap-6 lg:grid-cols-3">

    <!-- Existing periods -->
    <div class="lg:col-span-2 bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">Period</th>
                    <th class="px-4 py-3">Dates</th>
                    <th class="px-4 py-3 text-right">Charges</th>
                    <th class="px-4 py-3 text-right">Total billed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (!$periods): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                            No billing periods yet. Create the first one using the form.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($periods as $p): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium">
                            <a class="text-indigo-700 hover:underline" href="<?= url('period.php?id=' . (int) $p['id']) ?>">
                                <?= e($p['label']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <?= e(date('d M Y', strtotime($p['start_date']))) ?> &ndash; <?= e(date('d M Y', strtotime($p['end_date']))) ?>
                        </td>
                        <td class="px-4 py-3 text-right num"><?= (int) $p['charge_count'] ?></td>
                        <td class="px-4 py-3 text-right num whitespace-nowrap"><?= e(money($p['charge_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- New period -->
    <div>
        <section class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Create a period</h2>

            <?php if ($errors): ?>
                <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-800 px-3 py-2 text-sm">
                    <ul class="list-disc pl-5">
                        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="period-form" method="post" class="space-y-4">
                <?= csrf_field() ?>

                <div>
                    <label for="start_date" class="block text-sm font-medium mb-1">Start date</label>
                    <input id="start_date" name="start_date" type="date" required
                           value="<?= e($old['start_date']) ?>" class="<?= $inputClass ?>">
                </div>

                <div>
                    <label for="end_date" class="block text-sm font-medium mb-1">End date</label>
                    <input id="end_date" name="end_date" type="date" required
                           value="<?= e($old['end_date']) ?>" class="<?= $inputClass ?>">
                    <!-- Shortcuts: filled in by assets/js/period_form.js (the form also works without them) -->
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        <button type="button" id="btn-month-end"
                                class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-50">End of that month</button>
                        <button type="button" id="btn-three-weeks"
                                class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-50">3 weeks from start</button>
                    </div>
                </div>

                <div>
                    <label for="label" class="block text-sm font-medium mb-1">Label</label>
                    <input id="label" name="label" required maxlength="60" placeholder="e.g. December 2026"
                           value="<?= e($old['label']) ?>" class="<?= $inputClass ?>">
                </div>

                <p class="text-sm text-slate-600 bg-slate-50 rounded px-3 py-2">
                    Saving creates one charge for every student enrolled on or before the start date,
                    using the fee they signed up with.
                </p>

                <button type="submit" class="w-full bg-indigo-700 hover:bg-indigo-800 text-white font-medium rounded px-4 py-2">
                    Create period and charges
                </button>
            </form>
        </section>
    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
