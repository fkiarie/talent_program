<?php
/**
 * register.php
 * Public page where a parent registers one or more children and goes on to pay.
 *
 * Validation and saving come from includes/registration.php, the same code the staff form uses.
 * What this page adds for the public:
 *   - CSRF token, a hidden "honeypot" field for bots, and a limit on submissions per IP address
 *   - it never changes an existing guardian's saved details
 *   - the fee amounts come from the database/config, never from the browser
 *   - children are bundled into ONE checkout: registration fee (once per child) + first month
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/registration.php';
require_once __DIR__ . '/includes/billing.php';

const MAX_CHILDREN = 8;

// Active activities with their levels (for the drop-down, and to look fees up on the server).
$rows = db()->query(
    "SELECT a.id AS activity_id, a.name AS activity_name, l.id AS level_id, l.proficiency, l.monthly_fee
       FROM activities a
       JOIN activity_levels l ON l.activity_id = a.id
      WHERE a.is_active = 1
      ORDER BY a.name, FIELD(l.proficiency, 'Beginner', 'Intermediate', 'Advanced')"
)->fetchAll();

$activities = [];
$levels     = [];
foreach ($rows as $r) {
    $activities[$r['activity_name']][] = $r;
    $levels[(int) $r['level_id']] = $r;
}

$errors = [];
$old = [
    'g_id_number' => '', 'g_full_name' => '', 'g_phone' => '', 'g_alt_phone' => '', 'relation' => 'Mother',
    'children'    => [['full_name' => '', 'date_of_birth' => '', 'nationality' => 'Kenyan', 'level' => 0]],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Bots fill in every field, including this hidden one. Pretend it worked and do nothing.
    if (post_str('website') !== '') {
        redirect('index.php');
    }

    $ip = client_ip();
    if (rate_limited('register', $ip, 10, 3600)) {
        http_response_code(429);
        $errors[] = 'Too many registrations from this connection. Please try again later or visit our office.';
    }
    rate_limit_hit('register', $ip);

    foreach (['g_id_number', 'g_full_name', 'g_phone', 'g_alt_phone', 'relation'] as $f) {
        $old[$f] = post_str($f);
    }

    // The per-child fields arrive as arrays; ignore anything that is not an array of strings.
    $col = static function (string $key): array {
        $v = $_POST[$key] ?? [];
        return is_array($v) ? array_values(array_map(static fn($x) => is_string($x) ? trim($x) : '', $v)) : [];
    };
    $names = $col('child_name');
    $dobs  = $col('child_dob');
    $nats  = $col('child_nationality');
    $lvls  = $col('activity_level_id');

    $old['children'] = [];
    $count = min(count($names), MAX_CHILDREN + 1);
    for ($i = 0; $i < $count; $i++) {
        $old['children'][] = [
            'full_name'     => $names[$i] ?? '',
            'date_of_birth' => $dobs[$i] ?? '',
            'nationality'   => ($nats[$i] ?? '') !== '' ? $nats[$i] : 'Kenyan',
            'level'         => (int) ($lvls[$i] ?? 0),
        ];
    }
    if (!$old['children']) {
        $old['children'][] = ['full_name' => '', 'date_of_birth' => '', 'nationality' => 'Kenyan', 'level' => 0];
    }

    if (count($old['children']) > MAX_CHILDREN) {
        $errors[] = 'You can register up to ' . MAX_CHILDREN . ' children at a time.';
    }
    if (!in_array($old['relation'], RELATIONS, true)) {
        $errors[] = 'Choose your relationship to the children.';
    }

    $guardian = validate_guardian([
        'id_number' => $old['g_id_number'], 'full_name' => $old['g_full_name'],
        'phone' => $old['g_phone'], 'alt_phone' => $old['g_alt_phone'],
    ], $errors);

    // A phone number that already belongs to a different person is almost certainly a typo or a mix-up.
    if ($guardian['phone'] !== null && $guardian['existing'] === null) {
        $own = db()->prepare('SELECT 1 FROM guardians WHERE phone = ? LIMIT 1');
        $own->execute([$guardian['phone']]);
        if ($own->fetchColumn()) {
            $errors[] = 'That phone number is already registered under a different ID number. Check the numbers, or contact the office.';
        }
    }

    $children = [];
    $seen = [];
    foreach ($old['children'] as $i => $c) {
        $label = count($old['children']) > 1 ? 'Child ' . ($i + 1) . ': ' : '';
        $child = validate_child([
            'full_name' => $c['full_name'], 'date_of_birth' => $c['date_of_birth'], 'nationality' => $c['nationality'],
        ], $errors, $label);

        if ($c['level'] > 0 && !isset($levels[$c['level']])) {
            $errors[] = $label . 'Choose an activity from the list.';
        }
        $key = mb_strtolower($child['full_name']) . '|' . $child['dob'];
        if (isset($seen[$key])) {
            $errors[] = $label . 'This child is listed twice.';
        }
        $seen[$key] = true;

        $child['level'] = $c['level'];
        $children[] = $child;
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $guardianId = save_guardian($pdo, $guardian, false);      // public form never edits a saved guardian
            $studentIds = [];

            foreach ($children as $child) {
                // The same child registered earlier (e.g. the parent went back and resubmitted):
                // reuse that record so they are not charged twice, and just add it to this checkout.
                if ($guardian['existing'] !== null
                    && ($dupId = find_duplicate_child($guardianId, $child['full_name'], $child['dob']))) {
                    $studentIds[] = $dupId;
                    continue;
                }

                $studentId = save_child($pdo, $guardianId, $child, $old['relation'], null);
                $studentIds[] = $studentId;

                if ($child['level'] > 0) {
                    $fee = $levels[$child['level']]['monthly_fee'];       // fee comes from the database
                    $pdo->prepare('INSERT INTO enrollments (student_id, activity_level_id, monthly_fee) VALUES (?, ?, ?)')
                        ->execute([$studentId, $child['level'], $fee]);
                    create_first_month_charge($pdo, $studentId, (int) $pdo->lastInsertId(), $fee);
                }
            }

            $pdo->commit();

            // The checkout page works from this: which family, which children, which phone.
            $_SESSION['checkout'] = [
                'guardian_id' => $guardianId,
                'student_ids' => $studentIds,
                'phone'       => $guardian['phone'],
            ];
            redirect('pay.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {      // a UNIQUE rule was hit (two submissions at the same moment)
                $errors[] = 'This registration was just saved. Please check the details and try again.';
            } else {
                throw $ex;
            }
        }
    }
}

/** One child's row. Used for the rows already on the page and as the template for "Add another child". */
function child_row(array $c, array $activities, string $inputClass): void
{
    ?>
    <div class="child-row p-4 rounded-xl bg-slate-50 border border-slate-200 relative">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Child's full name <span class="text-rose-500">*</span></label>
                <input type="text" name="child_name[]" required maxlength="120" value="<?= e($c['full_name']) ?>" class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date of birth <span class="text-rose-500">*</span></label>
                <input type="date" name="child_dob[]" required max="<?= e(date('Y-m-d')) ?>" value="<?= e($c['date_of_birth']) ?>" class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nationality</label>
                <input type="text" name="child_nationality[]" required maxlength="60" value="<?= e($c['nationality']) ?>" class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Talent / activity</label>
                <select name="activity_level_id[]" class="<?= $inputClass ?>">
                    <option value="0">-- Register only (choose an activity later) --</option>
                    <?php foreach ($activities as $name => $lvls): ?>
                        <optgroup label="<?= e($name) ?>">
                            <?php foreach ($lvls as $l): ?>
                                <option value="<?= (int) $l['level_id'] ?>" <?= (int) $c['level'] === (int) $l['level_id'] ? 'selected' : '' ?>>
                                    <?= e($l['proficiency']) ?> &ndash; <?= e(money($l['monthly_fee'])) ?>/month
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <?php
}

$pageTitle   = 'Register your child';
$pageScripts = ['assets/js/register_form.js'];
$inputClass  = 'w-full px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-mistnavy text-sm';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-gradient-to-r from-mistnavy to-indigo-900 rounded-2xl p-6 mb-8 text-white shadow-xl">
        <span class="bg-mistgreen text-white text-xs font-bold uppercase tracking-wider px-3 py-1 rounded-full mb-3 inline-block">Admissions open</span>
        <h1 class="text-2xl md:text-3xl font-extrabold mb-2">Register your child for the MIST Youth Talent Program</h1>
        <p class="text-slate-200 text-sm max-w-2xl">Fill in the form, then pay the registration fee and first month by M-Pesa. You can register several children at once.</p>
    </div>

    <?php if ($errors): ?>
        <div class="bg-rose-50 border-l-4 border-rose-500 text-rose-800 p-4 mb-6 rounded-r shadow-sm text-sm" role="alert">
            <p class="font-semibold mb-1">Please fix the following:</p>
            <ul class="list-disc pl-5"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-8" novalidate>
        <?= csrf_field() ?>
        <!-- Honeypot: invisible to people, tempting to bots. -->
        <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <section class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
            <h2 class="text-lg font-bold text-mistnavy mb-6">1. Parent / guardian</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">Full name <span class="text-rose-500">*</span></label>
                    <input name="g_full_name" required maxlength="120" value="<?= e($old['g_full_name']) ?>" class="<?= $inputClass ?>">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">National ID / passport number <span class="text-rose-500">*</span></label>
                    <input name="g_id_number" required maxlength="30" autocomplete="off" value="<?= e($old['g_id_number']) ?>" class="<?= $inputClass ?>">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">M-Pesa phone number <span class="text-rose-500">*</span></label>
                    <input name="g_phone" type="tel" required maxlength="20" placeholder="0712 345 678" value="<?= e($old['g_phone']) ?>" class="<?= $inputClass ?>">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">Alternative phone <span class="text-slate-400 normal-case">(optional)</span></label>
                    <input name="g_alt_phone" type="tel" maxlength="20" value="<?= e($old['g_alt_phone']) ?>" class="<?= $inputClass ?>">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-2">Relationship to the children <span class="text-rose-500">*</span></label>
                    <select name="relation" required class="<?= $inputClass ?>">
                        <?php foreach (RELATIONS as $r): ?>
                            <option value="<?= e($r) ?>" <?= $old['relation'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-bold text-mistnavy">2. Children</h2>
                <button type="button" id="add-child-btn" data-max="<?= MAX_CHILDREN ?>"
                        class="bg-slate-100 hover:bg-slate-200 text-mistnavy text-xs font-bold px-3 py-2 rounded-lg">
                    <i class="fas fa-plus text-mistgreen"></i> Add another child
                </button>
            </div>

            <div id="children-container" class="space-y-6">
                <?php foreach ($old['children'] as $c): child_row($c, $activities, $inputClass); endforeach; ?>
            </div>

            <template id="child-template">
                <?php child_row(['full_name' => '', 'date_of_birth' => '', 'nationality' => 'Kenyan', 'level' => 0], $activities, $inputClass); ?>
            </template>

            <div class="mt-6 bg-amber-50 rounded-lg p-4 border border-amber-200 text-xs text-amber-900">
                <p class="font-bold">Fees</p>
                <p>A one-time, non-refundable registration fee of <strong><?= e(money(REGISTRATION_FEE)) ?></strong> is charged per child, plus the first month's activity fee. You will see the full total before you pay.</p>
            </div>
        </section>

        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <p class="text-xs text-slate-500">By submitting you agree to the MIST Talent Program guidelines.</p>
            <button type="submit" class="w-full sm:w-auto bg-mistgreen hover:bg-emerald-700 text-white font-bold px-8 py-3 rounded-xl shadow-lg">
                Continue to payment <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
