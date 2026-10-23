<?php
/**
 * activities.php
 * Admin only: add activities, set the monthly fee for each level, rename,
 * and switch activities on or off.
 *
 * Every activity has exactly three levels (Beginner, Intermediate, Advanced),
 * so adding an activity creates three rows in activity_levels at the same time.
 *
 * Changing a fee only affects students who join AFTER the change: each
 * enrollment keeps its own copy of the fee from the day the student joined.
 */
require_once __DIR__ . '/includes/auth.php';

$me = require_role('ADMIN');

$pageTitle = 'Activities';
$levels    = array_keys(DEFAULT_LEVEL_FEES);       // ['Beginner', 'Intermediate', 'Advanced']
$errors    = [];

// Values for the "add activity" form (refilled after a mistake).
$old = ['name' => ''];
foreach (DEFAULT_LEVEL_FEES as $level => $fee) {
    $old['fee_' . $level] = number_format($fee, 2, '.', '');
}

/**
 * Read and check the three fee fields from the submitted form.
 * Problems are added to $errors. Returns ['Beginner' => 4500.0, ...] (null where invalid).
 */
function read_fees(array $levels, array &$errors, array &$raw): array
{
    $fees = [];
    foreach ($levels as $level) {
        $raw['fee_' . $level] = post_str('fee_' . $level);
        $fees[$level]         = parse_amount($raw['fee_' . $level]);

        if ($fees[$level] === null) {
            $errors[] = 'Enter a valid monthly fee for ' . $level . ' (a number above 0, e.g. 4500).';
        }
    }
    return $fees;
}

/** Is this activity name already used (by a different activity)? */
function name_taken(string $name, int $exceptId = 0): bool
{
    $stmt = db()->prepare('SELECT 1 FROM activities WHERE name = ? AND id <> ?');
    $stmt->execute([$name, $exceptId]);
    return (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post_str('action');

    // ---- Add a new activity ------------------------------------------------
    if ($action === 'create') {
        $old['name'] = post_str('name');
        $fees        = read_fees($levels, $errors, $old);

        if ($old['name'] === '' || mb_strlen($old['name']) > 80) {
            $errors[] = 'Enter the activity name (up to 80 characters).';
        } elseif (name_taken($old['name'])) {
            $errors[] = 'An activity called "' . $old['name'] . '" already exists.';
        }

        if (!$errors) {
            $pdo = db();
            try {
                // One activity + three levels, saved together or not at all.
                $pdo->beginTransaction();

                $pdo->prepare('INSERT INTO activities (name) VALUES (?)')->execute([$old['name']]);
                $activityId = (int) $pdo->lastInsertId();

                $insert = $pdo->prepare(
                    'INSERT INTO activity_levels (activity_id, proficiency, monthly_fee) VALUES (?, ?, ?)'
                );
                foreach ($levels as $level) {
                    $insert->execute([$activityId, $level, $fees[$level]]);
                }

                $pdo->commit();
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($ex->getCode() === '23000') {     // name was taken a moment ago
                    $errors[] = 'An activity called "' . $old['name'] . '" already exists.';
                } else {
                    throw $ex;
                }
            }

            if (!$errors) {
                flash('success', 'Activity "' . $old['name'] . '" added with all three levels.');
                redirect('activities.php');
            }
        }
    }

    // ---- Save changes to an existing activity ------------------------------
    if ($action === 'update') {
        $id   = (int) post_str('id');
        $name = post_str('name');
        $raw  = [];
        $fees = read_fees($levels, $errors, $raw);

        $stmt = db()->prepare('SELECT id FROM activities WHERE id = ?');
        $stmt->execute([$id]);

        if (!$stmt->fetchColumn()) {
            $errors[] = 'Activity not found.';
        }
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = 'Enter the activity name (up to 80 characters).';
        } elseif (name_taken($name, $id)) {
            $errors[] = 'Another activity is already called "' . $name . '".';
        }

        if ($errors) {
            // Errors on a row are shown as a notice after redirecting; the row reloads its saved values.
            foreach ($errors as $err) {
                flash('error', $err);
            }
            redirect('activities.php');
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();

            $pdo->prepare('UPDATE activities SET name = ? WHERE id = ?')->execute([$name, $id]);

            // "Insert, or if this level already exists, update its fee."
            $upsert = $pdo->prepare(
                'INSERT INTO activity_levels (activity_id, proficiency, monthly_fee) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE monthly_fee = VALUES(monthly_fee)'
            );
            foreach ($levels as $level) {
                $upsert->execute([$id, $level, $fees[$level]]);
            }

            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                flash('error', 'Another activity is already called "' . $name . '".');
                redirect('activities.php');
            }
            throw $ex;
        }

        flash('success', '"' . $name . '" saved. New fees apply to students who join from now on; existing students keep their fee.');
        redirect('activities.php');
    }

    // ---- Switch an activity on or off ---------------------------------------
    // Off = it no longer appears in the "enrol" list. Students already in it are not affected.
    if ($action === 'toggle') {
        $id = (int) post_str('id');

        $stmt = db()->prepare('SELECT id, name, is_active FROM activities WHERE id = ?');
        $stmt->execute([$id]);
        $activity = $stmt->fetch();

        if ($activity) {
            db()->prepare('UPDATE activities SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            flash('success', '"' . $activity['name'] . '" is now ' . ((int) $activity['is_active'] === 1 ? 'switched off.' : 'available again.'));
        } else {
            flash('error', 'Activity not found.');
        }
        redirect('activities.php');
    }
}

// ---- Load everything for display ---------------------------------------------
$activities = db()->query(
    "SELECT a.id, a.name, a.is_active,
            (SELECT COUNT(*)
               FROM enrollments e
               JOIN activity_levels al ON al.id = e.activity_level_id
              WHERE al.activity_id = a.id AND e.status = 'ACTIVE') AS enrolled
       FROM activities a
      ORDER BY a.is_active DESC, a.name"
)->fetchAll();

// $feeOf[activity id][level name] = fee
$feeOf = [];
foreach (db()->query('SELECT activity_id, proficiency, monthly_fee FROM activity_levels')->fetchAll() as $row) {
    $feeOf[(int) $row['activity_id']][$row['proficiency']] = $row['monthly_fee'];
}

$inputClass = 'w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500';

require __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-2">Activities</h1>
<p class="text-slate-600 text-sm mb-6">
    Every activity has three levels, each with its own monthly fee.
    Changing a fee only affects students who join afterwards.
</p>

<div class="grid gap-6 lg:grid-cols-3">

    <!-- Existing activities -->
    <div class="lg:col-span-2 space-y-4">
        <?php if (!$activities): ?>
            <div class="bg-white rounded-lg shadow p-8 text-center text-slate-500">
                No activities yet. Add the first one using the form.
            </div>
        <?php endif; ?>

        <?php foreach ($activities as $a): ?>
            <?php $aid = (int) $a['id']; $active = (int) $a['is_active'] === 1; ?>
            <section class="bg-white rounded-lg shadow p-5 <?= $active ? '' : 'opacity-70' ?>">

                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm text-slate-500">
                        <?= (int) $a['enrolled'] ?> student<?= (int) $a['enrolled'] === 1 ? '' : 's' ?> enrolled
                        <?php if (!$active): ?> &middot; <span class="text-amber-700">switched off</span><?php endif; ?>
                    </p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $aid ?>">
                        <button class="text-sm text-indigo-700 hover:underline">
                            <?= $active ? 'Switch off' : 'Switch on' ?>
                        </button>
                    </form>
                </div>

                <form method="post" class="space-y-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= $aid ?>">

                    <div>
                        <label class="block text-sm font-medium mb-1" for="name_<?= $aid ?>">Activity name</label>
                        <input id="name_<?= $aid ?>" name="name" required maxlength="80"
                               value="<?= e($a['name']) ?>" class="<?= $inputClass ?>">
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <?php foreach ($levels as $level): ?>
                            <div>
                                <label class="block text-sm font-medium mb-1" for="fee_<?= $aid . '_' . e($level) ?>">
                                    <?= e($level) ?> (Ksh / month)
                                </label>
                                <input id="fee_<?= $aid . '_' . e($level) ?>" name="fee_<?= e($level) ?>"
                                       inputmode="decimal" required
                                       value="<?= e(number_format((float) ($feeOf[$aid][$level] ?? 0), 2, '.', '')) ?>"
                                       class="<?= $inputClass ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button class="bg-slate-700 hover:bg-slate-800 text-white text-sm rounded px-4 py-2">Save changes</button>
                </form>
            </section>
        <?php endforeach; ?>
    </div>

    <!-- Add activity -->
    <div>
        <section class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Add an activity</h2>

            <?php if ($errors): ?>
                <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-800 px-3 py-2 text-sm">
                    <ul class="list-disc pl-5">
                        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <div>
                    <label for="name" class="block text-sm font-medium mb-1">Activity name</label>
                    <input id="name" name="name" required maxlength="80" placeholder="e.g. Football"
                           value="<?= e($old['name']) ?>" class="<?= $inputClass ?>">
                </div>

                <?php foreach ($levels as $level): ?>
                    <div>
                        <label for="new_fee_<?= e($level) ?>" class="block text-sm font-medium mb-1">
                            <?= e($level) ?> (Ksh / month)
                        </label>
                        <input id="new_fee_<?= e($level) ?>" name="fee_<?= e($level) ?>" inputmode="decimal" required
                               value="<?= e($old['fee_' . $level]) ?>" class="<?= $inputClass ?>">
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="w-full bg-indigo-700 hover:bg-indigo-800 text-white font-medium rounded px-4 py-2">
                    Add activity
                </button>
            </form>
        </section>
    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
