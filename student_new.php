<?php
/**
 * student_new.php
 * Registers a student, and their guardian, in one go.
 *
 * What happens when the form is submitted (all or nothing, in one transaction):
 *   1. Validate everything.
 *   2. Find the guardian by ID number, or create them.
 *   3. Save the student.
 *   4. Add the one-time registration fee (Ksh 2,000) to the student's account.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/registration.php';

$user = require_login();      // Admins and Moderators may both register students

$pageTitle   = 'Register student';
$pageScripts = ['assets/js/student_form.js'];     // loaded by footer.php
$relations   = RELATIONS;

// The form fields. $old holds what the user typed so we can refill the form after an error.
$old = [
    'full_name'      => '',
    'date_of_birth'  => '',
    'nationality'    => 'Kenyan',
    'id_passport_no' => '',
    'g_id_number'    => '',
    'g_full_name'    => '',
    'relation'       => '',
    'g_phone'        => '',
    'g_alt_phone'    => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach (array_keys($old) as $field) {
        $old[$field] = post_str($field);
    }

    // ---- 1. Validate (the same rules the public registration page uses) ----
    $child = validate_child($old, $errors);

    if (!in_array($old['relation'], $relations, true)) {
        $errors[] = 'Choose the guardian\'s relation to the student.';
    }

    $guardian = validate_guardian([
        'id_number' => $old['g_id_number'],
        'full_name' => $old['g_full_name'],
        'phone'     => $old['g_phone'],
        'alt_phone' => $old['g_alt_phone'],
    ], $errors);

    // Duplicate checks (only worth running once the basics are valid)
    if (!$errors) {
        if ($taken = student_id_taken($child['id_no'])) {
            $errors[] = 'That ID/passport number is already registered to ' . $taken . '.';
        }
        if ($guardian['existing'] !== null
            && find_duplicate_child((int) $guardian['existing']['id'], $child['full_name'], $child['dob'])) {
            $errors[] = 'A student with this name and date of birth is already registered under this guardian.';
        }
    }

    // ---- 2. Save: guardian, student and registration fee, all or nothing ----
    if (!$errors) {
        $pdo = db();

        try {
            $pdo->beginTransaction();
            $guardianId = save_guardian($pdo, $guardian, true);     // staff may correct phone numbers
            $studentId  = save_child($pdo, $guardianId, $child, $old['relation'], (int) $user['id']);
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // 23000 = a UNIQUE rule was broken, e.g. two staff saved the same guardian at the same moment.
            if ($ex->getCode() === '23000') {
                $errors[] = 'Someone saved a matching record a moment ago. Please check the details and try again.';
            } else {
                throw $ex;     // unexpected: let it show (in debug mode) so we can fix it
            }
        }

        if (!$errors) {
            $message = $child['full_name'] . ' registered. A registration fee of ' . money(REGISTRATION_FEE) . ' was added.';
            if ($guardian['existing'] !== null) {
                $message .= ' Linked to existing guardian ' . $guardian['existing']['full_name'] . '.';
            }
            flash('success', $message);
            redirect('student.php?id=' . $studentId);
        }
    }
}

require __DIR__ . '/includes/header.php';

// Small helper so the long input class list is written once.
$inputClass = 'w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Register a student</h1>
    <a href="<?= url('students.php') ?>" class="text-indigo-700 hover:underline text-sm">&larr; All students</a>
</div>

<?php if ($errors): ?>
    <div class="mb-6 rounded bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
        <p class="font-medium mb-1">Please fix the following:</p>
        <ul class="list-disc pl-5">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form id="student-form" method="post" class="space-y-6"
      data-lookup-url="<?= e(url('guardian_lookup.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <!-- Student -->
    <section class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Student</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="full_name" class="block text-sm font-medium mb-1">Full name</label>
                <input id="full_name" name="full_name" required maxlength="120"
                       value="<?= e($old['full_name']) ?>" class="<?= $inputClass ?>">
            </div>

            <div>
                <label for="date_of_birth" class="block text-sm font-medium mb-1">Date of birth</label>
                <input id="date_of_birth" name="date_of_birth" type="date" required
                       max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($old['date_of_birth']) ?>" class="<?= $inputClass ?>">
            </div>

            <div>
                <label for="nationality" class="block text-sm font-medium mb-1">Nationality</label>
                <input id="nationality" name="nationality" required maxlength="60" list="nationalities"
                       value="<?= e($old['nationality']) ?>" class="<?= $inputClass ?>">
                <datalist id="nationalities">
                    <option value="Kenyan"><option value="Ugandan"><option value="Tanzanian">
                    <option value="Rwandan"><option value="Burundian"><option value="South Sudanese">
                    <option value="Somali"><option value="Ethiopian"><option value="Other">
                </datalist>
            </div>

            <div class="sm:col-span-2">
                <label for="id_passport_no" class="block text-sm font-medium mb-1">
                    ID / passport number <span class="font-normal text-slate-500">(optional)</span>
                </label>
                <input id="id_passport_no" name="id_passport_no" maxlength="30"
                       value="<?= e($old['id_passport_no']) ?>" class="<?= $inputClass ?>">
                <p class="text-xs text-slate-500 mt-1">Leave blank if the child does not have one. The guardian is the identifier.</p>
            </div>
        </div>
    </section>

    <!-- Guardian -->
    <section class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-1">Parent / guardian</h2>
        <p class="text-sm text-slate-600 mb-4">
            Start with the ID number. If this guardian already has a child registered, their details fill in automatically.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="g_id_number" class="block text-sm font-medium mb-1">Guardian's ID number</label>
                <input id="g_id_number" name="g_id_number" required maxlength="30" autocomplete="off"
                       value="<?= e($old['g_id_number']) ?>" class="<?= $inputClass ?>">
                <div id="guardian-notice" class="hidden mt-2 rounded px-3 py-2 text-sm"></div>
            </div>

            <div>
                <label for="g_full_name" class="block text-sm font-medium mb-1">Guardian's full name</label>
                <input id="g_full_name" name="g_full_name" maxlength="120"
                       value="<?= e($old['g_full_name']) ?>" class="<?= $inputClass ?>">
            </div>

            <div>
                <label for="relation" class="block text-sm font-medium mb-1">Relation to the student</label>
                <select id="relation" name="relation" required class="<?= $inputClass ?>">
                    <option value="">Choose...</option>
                    <?php foreach ($relations as $r): ?>
                        <option value="<?= e($r) ?>" <?= $old['relation'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="g_phone" class="block text-sm font-medium mb-1">Phone number</label>
                <input id="g_phone" name="g_phone" type="tel" required maxlength="20" placeholder="0712 345 678"
                       value="<?= e($old['g_phone']) ?>" class="<?= $inputClass ?>">
            </div>

            <div>
                <label for="g_alt_phone" class="block text-sm font-medium mb-1">
                    Alternative phone <span class="font-normal text-slate-500">(optional)</span>
                </label>
                <input id="g_alt_phone" name="g_alt_phone" type="tel" maxlength="20"
                       value="<?= e($old['g_alt_phone']) ?>" class="<?= $inputClass ?>">
            </div>
        </div>
    </section>

    <div class="flex items-center justify-between">
        <p class="text-sm text-slate-600">
            A one-time registration fee of <strong><?= e(money(REGISTRATION_FEE)) ?></strong> will be added to this student's account.
        </p>
        <button type="submit" class="bg-indigo-700 hover:bg-indigo-800 text-white font-medium rounded px-6 py-2">
            Register student
        </button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
