<?php
/**
 * students.php
 * List of registered students with search and paging.
 * Search matches: student name, guardian name, guardian ID number,
 * student ID/passport number, and phone numbers.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';

$user = require_login();

$pageTitle = 'Students';
$perPage   = 20;

// Read the search text and page number from the URL (?q=...&page=2)
$q    = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

// ---- Build the WHERE part from the search text ------------------------------
$conditions = [];
$params     = [];

if ($q !== '') {
    // Escape % and _ so that people searching for them don't get wildcards.
    $like = '%' . addcslashes($q, '%_\\') . '%';

    foreach (['s.full_name', 'g.full_name', 'g.id_number', 's.id_passport_no'] as $column) {
        $conditions[] = "$column LIKE ?";
        $params[]     = $like;
    }

    // Phones are stored as 2547XXXXXXXX, but people type 07XX..., so convert before searching.
    $digits = preg_replace('/\D+/', '', $q);
    if (strlen($digits) >= 4) {
        if ($digits[0] === '0') {
            $digits = '254' . substr($digits, 1);
        }
        $phoneLike = '%' . $digits . '%';
        $conditions[] = 'g.phone LIKE ?';
        $params[]     = $phoneLike;
        $conditions[] = 'g.alt_phone LIKE ?';
        $params[]     = $phoneLike;
    }
}

$where = $conditions ? 'WHERE (' . implode(' OR ', $conditions) . ')' : '';

// ---- How many matches in total? ---------------------------------------------
$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM students s JOIN guardians g ON g.id = s.guardian_id $where"
);
$countStmt->execute($params);
$total      = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ---- Fetch this page of results ---------------------------------------------
// balance = everything charged to the student minus every valid payment applied to those charges.
// Positive = they owe money, negative = they have credit.
$sql = "SELECT s.id, s.full_name, s.date_of_birth, s.registered_on, s.registration_status,
               g.full_name AS guardian_name, g.phone AS guardian_phone,
               COALESCE((SELECT SUM(c.amount) FROM charges c WHERE c.student_id = s.id), 0)
             - COALESCE((SELECT SUM(a.amount)
                           FROM payment_allocations a
                           JOIN payments p ON p.id = a.payment_id
                           JOIN charges c2 ON c2.id = a.charge_id
                          WHERE c2.student_id = s.id AND p.status = 'VALID'), 0) AS balance
          FROM students s
          JOIN guardians g ON g.id = s.guardian_id
          $where
         ORDER BY s.registered_on DESC, s.id DESC
         LIMIT ? OFFSET ?";

$stmt = db()->prepare($sql);

// LIMIT and OFFSET must be bound as integers, so we bind each value with its type.
$i = 1;
foreach ($params as $p) {
    $stmt->bindValue($i++, $p, PDO::PARAM_STR);
}
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll();

/** Link to another page of results, keeping the search text. */
function page_link(int $page, string $q): string
{
    return url('students.php') . '?' . http_build_query(['q' => $q, 'page' => $page]);
}

require __DIR__ . '/includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="text-2xl font-bold">Students</h1>
    <a href="<?= url('student_new.php') ?>"
       class="bg-indigo-700 hover:bg-indigo-800 text-white font-medium rounded px-4 py-2">
        Register a student
    </a>
</div>

<form method="get" class="mb-6 flex gap-2 max-w-xl">
    <input name="q" value="<?= e($q) ?>" placeholder="Search name, guardian, ID number or phone"
           class="flex-1 rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
    <button class="bg-slate-700 hover:bg-slate-800 text-white rounded px-4 py-2">Search</button>
    <?php if ($q !== ''): ?>
        <a href="<?= url('students.php') ?>" class="px-3 py-2 text-slate-600 hover:underline">Clear</a>
    <?php endif; ?>
</form>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
                <th class="px-4 py-3">Student</th>
                <th class="px-4 py-3">Age</th>
                <th class="px-4 py-3">Guardian</th>
                <th class="px-4 py-3">Phone</th>
                <th class="px-4 py-3">Registered</th>
                <th class="px-4 py-3 text-right">Balance</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (!$students): ?>
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                        <?= $q !== '' ? 'No students match your search.' : 'No students registered yet.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($students as $s): ?>
                <?php $balance = (float) $s['balance']; ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium">
                        <a class="text-indigo-700 hover:underline" href="<?= url('student.php?id=' . (int) $s['id']) ?>">
                            <?= e($s['full_name']) ?>
                        </a>
                        <?php if ($s['registration_status'] === 'RESERVED'): ?>
                            <span class="ml-1 text-[11px] font-bold px-2 py-0.5 rounded bg-amber-100 text-amber-800">Pay later</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><?= (int) age_years($s['date_of_birth']) ?></td>
                    <td class="px-4 py-3"><?= e($s['guardian_name']) ?></td>
                    <td class="px-4 py-3 whitespace-nowrap"><?= e(format_phone($s['guardian_phone'])) ?></td>
                    <td class="px-4 py-3 whitespace-nowrap"><?= e(date('d M Y', strtotime($s['registered_on']))) ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap num">
                        <?php if ($balance > 0.004): ?>
                            <span class="text-red-700">Owes <?= e(money($balance)) ?></span>
                        <?php elseif ($balance < -0.004): ?>
                            <span class="text-blue-700">Credit <?= e(money(-$balance)) ?></span>
                        <?php else: ?>
                            <span class="text-green-700">Paid up</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="flex items-center justify-between mt-4 text-sm text-slate-600">
    <span><?= $total ?> student<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' found' : '' ?></span>

    <?php if ($totalPages > 1): ?>
        <span class="flex items-center gap-3">
            <?php if ($page > 1): ?>
                <a class="text-indigo-700 hover:underline" href="<?= e(page_link($page - 1, $q)) ?>">&larr; Previous</a>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="text-indigo-700 hover:underline" href="<?= e(page_link($page + 1, $q)) ?>">Next &rarr;</a>
            <?php endif; ?>
        </span>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
