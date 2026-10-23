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

$user = require_login();      // Admins and Moderators may both register students

$pageTitle   = 'Register student';
$pageScripts = ['assets/js/student_form.js'];     // loaded by footer.php
$relations   = ['Mother', 'Father', 'Guardian', 'Grandparent', 'Aunt', 'Uncle', 'Sibling', 'Other'];

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

    // ---- 1. Validate ------------------------------------------------------
    // Student
    if ($old['full_name'] === '' || mb_strlen($old['full_name']) > 120) {
        $errors[] = 'Enter the student\'s full name (up to 120 characters).';
    }

    $dob = parse_date($old['date_of_birth']);
    if ($dob === null) {
        $errors[] = 'Enter a valid date of birth.';
    } elseif ($dob > new DateTimeImmutable('today')) {
        $errors[] = 'The date of birth cannot be in the future.';
    } elseif (age_years($dob->format('Y-m-d')) > 25) {
        $errors[] = 'The date of birth makes the student older than 25. Please check it.';
    }

    if ($old['nationality'] === '' || mb_strlen($old['nationality']) > 60) {
        $errors[] = 'Enter the nationality (up to 60 characters).';
    }

    $studentIdNo = normalize_id_number($old['id_passport_no']);
    if ($studentIdNo !== '' && !valid_id_number($studentIdNo)) {
        $errors[] = 'The student\'s ID/passport number may only contain letters, digits, - and / (4 to 30 characters). Leave it blank if there is none.';
    }

    if (!in_array($old['relation'], $relations, true)) {
        $errors[] = 'Choose the guardian\'s relation to the student.';
    }

    // Guardian
    $guardianIdNo = normalize_id_number($old['g_id_number']);
    if (!valid_id_number($guardianIdNo)) {
        $errors[] = 'Enter the guardian\'s ID number (letters, digits, - and / only, 4 to 30 characters).';
    }

    $phone = normalize_phone($old['g_phone']);
    if ($phone === null) {
        $errors[] = 'Enter a valid phone number, e.g. 0712 345 678. Numbers from other countries must start with +.';
    }

    $altPhone = null;
    if ($old['g_alt_phone'] !== '') {
        $altPhone = normalize_phone($old['g_alt_phone']);
        if ($altPhone === null) {
            $errors[] = 'The alternative phone number is not valid. Leave it blank if there is none.';
        }
    }

    // Does this guardian already exist? (We check on the server, we never trust the browser.)
    $guardian = null;
    if (valid_id_number($guardianIdNo)) {
        $stmt = db()->prepare('SELECT id, full_name FROM guardians WHERE id_number = ?');
        $stmt->execute([$guardianIdNo]);
        $guardian = $stmt->fetch() ?: null;
    }

    if ($guardian === null && ($old['g_full_name'] === '' || mb_strlen($old['g_full_name']) > 120)) {
        $errors[] = 'Enter the guardian\'s full name (up to 120 characters).';
    }

    // Duplicate checks (only worth running once the basics are valid)
    if (!$errors) {
        if ($studentIdNo !== '') {
            $dup = db()->prepare('SELECT full_name FROM students WHERE id_passport_no = ?');
            $dup->execute([$studentIdNo]);
            if ($found = $dup->fetch()) {
                $errors[] = 'That ID/passport number is already registered to ' . $found['full_name'] . '.';
            }
        }

        if ($guardian !== null) {
            $dup = db()->prepare(
                'SELECT 1 FROM students WHERE guardian_id = ? AND full_name = ? AND date_of_birth = ?'
            );
            $dup->execute([$guardian['id'], $old['full_name'], $dob->format('Y-m-d')]);
            if ($dup->fetchColumn()) {
                $errors[] = 'A student with this name and date of birth is already registered under this guardian.';
            }
        }
    }

    // ---- 2-4. Save --------------------------------------------------------
    if (!$errors) {
        $pdo = db();

        try {
            // A transaction makes the steps below all-or-nothing: if any step
            // fails, none of them are kept. We never end up with a student
            // who has no registration fee, or a guardian with no student.
            $pdo->beginTransaction();

            if ($guardian !== null) {
                $guardianId = (int) $guardian['id'];
                // Existing guardian: keep their saved name, but let staff correct the phone numbers.
                $pdo->prepare('UPDATE guardians SET phone = ?, alt_phone = ? WHERE id = ?')
                    ->execute([$phone, $altPhone, $guardianId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO guardians (full_name, id_number, phone, alt_phone) VALUES (?, ?, ?, ?)'
                )->execute([$old['g_full_name'], $guardianIdNo, $phone, $altPhone]);
                $guardianId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'INSERT INTO students
                    (full_name, date_of_birth, nationality, id_passport_no, guardian_id, relation, registered_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $old['full_name'],
                $dob->format('Y-m-d'),
                $old['nationality'],
                $studentIdNo !== '' ? $studentIdNo : null,     // NULL, not an empty string, when blank
                $guardianId,
                $old['relation'],
                $user['id'],
            ]);
            $studentId = (int) $pdo->lastInsertId();

            // The one-time registration fee. The amount is saved on the charge itself,
            // so changing REGISTRATION_FEE later never alters existing students.
            $pdo->prepare(
                "INSERT INTO charges (student_id, enrollment_id, billing_period_id, charge_type, amount)
                 VALUES (?, NULL, NULL, 'REGISTRATION', ?)"
            )->execute([$studentId, REGISTRATION_FEE]);

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
            $message = $old['full_name'] . ' registered. A registration fee of ' . money(REGISTRATION_FEE) . ' was added.';
            if ($guardian !== null) {
                $message .= ' Linked to existing guardian ' . $guardian['full_name'] . '.';
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
