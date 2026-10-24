<?php
/**
 * reservations.php
 * Staff follow-up list: families who chose "Pay later" at checkout.
 * A moderator phones the parent, then records that they were contacted (with a note).
 * A reservation turns PAID by itself once the student's charges are fully paid.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';

$user = require_login();

const RESERVATION_TABS = [
    'RESERVED'  => 'New',
    'CONTACTED' => 'Contacted',
    'PAID'      => 'Paid',
    'ALL'       => 'All',
];

$ready = reservations_ready();
$show  = (isset($_GET['show']) && is_string($_GET['show']) && isset(RESERVATION_TABS[$_GET['show']])) ? $_GET['show'] : 'RESERVED';
$page  = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

/** Link back to this page with the chosen tab and page number. */
function reservations_link(string $show, int $page = 1): string
{
    return url('reservations.php') . '?' . http_build_query(['show' => $show, 'page' => $page]);
}

// ---- Record a follow-up --------------------------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = post_str('action');
    $note   = mb_substr(post_str('note'), 0, 500);

    if ($id > 0 && $action === 'contacted') {
        $stmt = db()->prepare(
            "UPDATE payment_reservations
                SET status = 'CONTACTED', contacted_at = NOW(), contacted_by = ?, note = ?
              WHERE id = ? AND status <> 'PAID'"
        );
        $stmt->execute([(int) $user['id'], $note !== '' ? $note : null, $id]);
        flash('success', 'Saved: marked as contacted.');
    } elseif ($id > 0 && $action === 'reopen') {
        db()->prepare("UPDATE payment_reservations SET status = 'RESERVED' WHERE id = ? AND status = 'CONTACTED'")->execute([$id]);
        flash('success', 'Moved back to New.');
    }
    redirect('reservations.php?' . http_build_query(['show' => $show, 'page' => $page]));
}

// ---- Counts for the tabs and the list for this tab ---------------------------------------
$counts = ['RESERVED' => 0, 'CONTACTED' => 0, 'PAID' => 0, 'ALL' => 0];
$rows   = [];
$total  = 0;

if ($ready) {
    foreach (db()->query('SELECT status, COUNT(*) AS n FROM payment_reservations GROUP BY status')->fetchAll() as $c) {
        $counts[$c['status']] = (int) $c['n'];
        $counts['ALL']       += (int) $c['n'];
    }
    $total      = $counts[$show];
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);

    $where = $show === 'ALL' ? '' : 'WHERE r.status = ?';
    $stmt  = db()->prepare(
        "SELECT r.id, r.status, r.reserved_at, r.contacted_at, r.note, r.paid_at,
                s.id AS student_id, s.full_name AS student_name,
                g.full_name AS guardian_name, g.phone AS guardian_phone, g.alt_phone,
                u.full_name AS contacted_by_name,
                COALESCE((SELECT SUM(c.amount) FROM charges c WHERE c.student_id = s.id), 0)
              - COALESCE((SELECT SUM(a.amount)
                            FROM payment_allocations a
                            JOIN payments p ON p.id = a.payment_id
                            JOIN charges c2 ON c2.id = a.charge_id
                           WHERE c2.student_id = s.id AND p.status = 'VALID'), 0) AS balance
           FROM payment_reservations r
           JOIN students s ON s.id = r.student_id
           JOIN guardians g ON g.id = r.guardian_id
           LEFT JOIN users u ON u.id = r.contacted_by
           $where
          ORDER BY r.reserved_at ASC, r.id ASC
          LIMIT ? OFFSET ?"
    );
    $i = 1;
    if ($show !== 'ALL') {
        $stmt->bindValue($i++, $show, PDO::PARAM_STR);
    }
    $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

/** "3 days ago" style text for when a family asked to pay later. */
function days_ago(string $when): string
{
    $days = (int) floor((time() - strtotime($when)) / 86400);
    return $days <= 0 ? 'today' : ($days === 1 ? 'yesterday' : $days . ' days ago');
}

$pageTitle = 'Pay-later follow-ups';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold">Pay-later follow-ups</h1>
    <p class="text-sm text-slate-600 mt-1">Families who registered and chose to pay later. Call them, then record that you did.</p>
</div>

<?php if (!$ready): ?>
    <div class="rounded border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
        This page needs a one-time database update. Ask the administrator to run
        <code>sql/migrations/003_pay_later_reservations.sql</code>.
    </div>
<?php else: ?>
    <div class="flex flex-wrap gap-2 mb-5" role="tablist">
        <?php foreach (RESERVATION_TABS as $key => $label): ?>
            <a href="<?= e(reservations_link($key)) ?>" role="tab" aria-selected="<?= $show === $key ? 'true' : 'false' ?>"
               class="px-4 py-2 rounded-lg text-sm font-semibold border <?= $show === $key ? 'bg-mistnavy text-white border-mistnavy' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' ?>">
                <?= e($label) ?> <span class="<?= $show === $key ? 'text-mistgold' : 'text-slate-500' ?> num">(<?= (int) $counts[$key] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="space-y-4">
        <?php if (!$rows): ?>
            <div class="bg-white rounded-lg shadow px-4 py-10 text-center text-slate-500">
                <?= $show === 'RESERVED' ? 'No new pay-later requests. Nothing to follow up right now.' : 'Nothing here.' ?>
            </div>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
            <?php
            $balance = (float) $r['balance'];
            $badge = [
                'RESERVED'  => ['New', 'bg-amber-100 text-amber-800'],
                'CONTACTED' => ['Contacted', 'bg-blue-100 text-blue-800'],
                'PAID'      => ['Paid', 'bg-green-100 text-green-800'],
            ][$r['status']];
            ?>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <a class="font-semibold text-indigo-700 hover:underline" href="<?= e(url('student.php?id=' . (int) $r['student_id'])) ?>"><?= e($r['student_name']) ?></a>
                        <span class="ml-2 text-xs font-bold px-2 py-0.5 rounded <?= $badge[1] ?>"><?= e($badge[0]) ?></span>
                        <p class="text-sm text-slate-600 mt-1">
                            <?= e($r['guardian_name']) ?> &middot;
                            <a class="text-indigo-700 hover:underline whitespace-nowrap" href="tel:+<?= e($r['guardian_phone']) ?>"><i class="fas fa-phone text-xs"></i> <?= e(format_phone($r['guardian_phone'])) ?></a>
                            <?php if (!empty($r['alt_phone'])): ?>
                                &middot; <span class="whitespace-nowrap">alt <?= e(format_phone($r['alt_phone'])) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-sm text-right">
                        <p class="num font-bold <?= $balance > 0.004 ? 'text-red-700' : 'text-green-700' ?>">
                            <?= $balance > 0.004 ? 'Owes ' . e(money($balance)) : 'Paid up' ?>
                        </p>
                        <p class="text-xs text-slate-500">Asked to pay later <?= e(days_ago($r['reserved_at'])) ?> (<?= e(date('d M Y', strtotime($r['reserved_at']))) ?>)</p>
                    </div>
                </div>

                <?php if ($r['status'] === 'CONTACTED'): ?>
                    <p class="text-xs text-slate-500 mt-3">
                        Contacted <?= e(date('d M Y, H:i', strtotime($r['contacted_at']))) ?><?= $r['contacted_by_name'] ? ' by ' . e($r['contacted_by_name']) : '' ?>.
                    </p>
                <?php elseif ($r['status'] === 'PAID' && $r['paid_at']): ?>
                    <p class="text-xs text-slate-500 mt-3">Paid on <?= e(date('d M Y', strtotime($r['paid_at']))) ?>.</p>
                <?php endif; ?>

                <?php if ($r['status'] !== 'PAID'): ?>
                    <form method="post" class="mt-3 flex flex-col sm:flex-row gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="page" value="<?= (int) $page ?>">
                        <label class="sr-only" for="note-<?= (int) $r['id'] ?>">Follow-up note</label>
                        <input id="note-<?= (int) $r['id'] ?>" name="note" maxlength="500" value="<?= e((string) $r['note']) ?>"
                               placeholder="Note, e.g. said they will pay on Friday"
                               class="flex-1 rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <button name="action" value="contacted" class="bg-indigo-700 hover:bg-indigo-800 text-white text-sm font-medium rounded px-4 py-2">
                            <?= $r['status'] === 'CONTACTED' ? 'Update note' : 'Mark contacted' ?>
                        </button>
                        <?php if ($r['status'] === 'CONTACTED'): ?>
                            <button name="action" value="reopen" class="text-slate-600 hover:underline text-sm px-2 py-2">Move back to New</button>
                        <?php endif; ?>
                    </form>
                <?php elseif ($r['note']): ?>
                    <p class="text-sm text-slate-600 mt-3">Note: <?= e($r['note']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (($totalPages ?? 1) > 1): ?>
        <div class="flex items-center justify-between mt-4 text-sm text-slate-600">
            <span><?= $total ?> in this list</span>
            <span class="flex items-center gap-3">
                <?php if ($page > 1): ?><a class="text-indigo-700 hover:underline" href="<?= e(reservations_link($show, $page - 1)) ?>">&larr; Previous</a><?php endif; ?>
                <span>Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?><a class="text-indigo-700 hover:underline" href="<?= e(reservations_link($show, $page + 1)) ?>">Next &rarr;</a><?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
