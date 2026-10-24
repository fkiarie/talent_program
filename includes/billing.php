<?php
/**
 * includes/billing.php
 * Money rules that more than one page needs. Phase 6 (payments) adds to this file.
 */

require_once __DIR__ . '/db.php';

/**
 * Has this student paid their one-time registration fee in full?
 * "Paid" means the payments allocated to that charge (ignoring voided payments)
 * add up to at least the charge amount.
 */
function registration_is_paid(int $studentId): bool
{
    $stmt = db()->prepare(
        "SELECT c.amount,
                COALESCE((SELECT SUM(a.amount)
                            FROM payment_allocations a
                            JOIN payments p ON p.id = a.payment_id
                           WHERE a.charge_id = c.id AND p.status = 'VALID'), 0) AS paid
           FROM charges c
          WHERE c.student_id = ? AND c.charge_type = 'REGISTRATION'"
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();

    return $row !== false && (float) $row['paid'] >= (float) $row['amount'] - 0.004;
}

/**
 * Does a proposed billing period overlap one that already exists?
 * Two periods overlap when each one starts on or before the day the other ends.
 * Returns the clashing period (id, label) or null. $exceptId lets a period ignore itself.
 *
 * $pdo is optional; pages leave it out and get the normal database connection.
 */
function period_overlap(string $start, string $end, int $exceptId = 0, ?PDO $pdo = null): ?array
{
    $pdo ??= db();

    $stmt = $pdo->prepare(
        'SELECT id, label FROM billing_periods
          WHERE id <> ? AND start_date <= ? AND end_date >= ?
          LIMIT 1'
    );
    $stmt->execute([$exceptId, $end, $start]);

    return $stmt->fetch() ?: null;
}

/**
 * Create the activity charges for one billing period. Returns how many NEW charges were created.
 *
 * Who is billed: every ACTIVE enrollment that began on or before the period's start date.
 * Anyone who joined after the period started waits for the next period (no part-period charges).
 * The charge uses the fee saved on the enrollment (the snapshot), not today's price list.
 *
 * Safe to run again: an enrollment that already has a charge for this period is skipped, and the
 * database's UNIQUE (enrollment_id, billing_period_id) rule backs that up. Running it again only
 * picks up students who were enrolled (on or before the start date) after the first run.
 */
function generate_period_charges(int $periodId, ?PDO $pdo = null): int
{
    $pdo ??= db();

    $stmt = $pdo->prepare('SELECT start_date, end_date FROM billing_periods WHERE id = ?');
    $stmt->execute([$periodId]);
    $period = $stmt->fetch();

    if ($period === false) {
        return 0;
    }
    $start = $period['start_date'];
    $end   = $period['end_date'];

    // One INSERT ... SELECT creates every missing charge in a single step.
    $insert = $pdo->prepare(
        "INSERT INTO charges (student_id, enrollment_id, billing_period_id, charge_type, amount)
         SELECT e.student_id, e.id, ?, 'ACTIVITY', e.monthly_fee
           FROM enrollments e
          WHERE e.status = 'ACTIVE'
            AND e.enrolled_on <= ?
            AND NOT EXISTS (SELECT 1 FROM charges c
                             WHERE c.enrollment_id = e.id AND c.billing_period_id = ?)
            -- The joining-month charge (made at enrolment, see create_first_month_charge) already
            -- covers a period that contains its date, so do not bill that month twice.
            AND NOT EXISTS (SELECT 1 FROM charges j
                             WHERE j.enrollment_id = e.id AND j.billing_period_id IS NULL
                               AND j.charge_type = 'ACTIVITY' AND j.created_on BETWEEN ? AND ?)"
    );
    $insert->execute([$periodId, $start, $periodId, $start, $end]);

    return $insert->rowCount();
}


// ===========================================================================
// First-month charge, balances, and payment allocation
// ===========================================================================

/** Money is handled in whole cents inside the allocation code, so 0.1 + 0.2 never goes wrong. */
function to_cents($amount): int
{
    return (int) round(((float) $amount) * 100);
}

/**
 * Bill the joining month when a student is enrolled in an activity.
 *
 * The parent pays "registration fee + first month" in one go, so the first month needs a charge
 * right away. If a billing period already covers today the charge belongs to that period (and the
 * period run will not bill it again); otherwise it is a joining-month charge with no period.
 * generate_period_charges() skips any month this charge already covers.
 *
 * Returns the new charge id, or null if that enrolment was already billed for the current period
 * (e.g. a student who dropped and re-joined in the same month).
 */
function create_first_month_charge(PDO $pdo, int $studentId, int $enrollmentId, $fee): ?int
{
    $p = $pdo->query('SELECT id FROM billing_periods WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1');
    $periodId = $p->fetchColumn();
    $periodId = $periodId === false ? null : (int) $periodId;

    if ($periodId !== null) {
        $chk = $pdo->prepare('SELECT 1 FROM charges WHERE enrollment_id = ? AND billing_period_id = ?');
        $chk->execute([$enrollmentId, $periodId]);
        if ($chk->fetchColumn()) {
            return null;
        }
    }

    $pdo->prepare(
        "INSERT INTO charges (student_id, enrollment_id, billing_period_id, charge_type, amount)
         VALUES (?, ?, ?, 'ACTIVITY', ?)"
    )->execute([$studentId, $enrollmentId, $periodId, $fee]);

    return (int) $pdo->lastInsertId();
}

/**
 * Every charge of the given students that is not fully paid, oldest first (registration fees first).
 * Each row has: id, student_id, student_name, charge_type, amount, created_on, label, paid, outstanding.
 * Pass $lock = true inside a transaction to lock these charges while you allocate money to them.
 *
 * @param int[] $studentIds
 */
function outstanding_charges(array $studentIds, ?PDO $pdo = null, bool $lock = false): array
{
    $pdo ??= db();
    $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
    if (!$studentIds) {
        return [];
    }

    $in  = implode(',', array_fill(0, count($studentIds), '?'));
    $sql = "SELECT c.id, c.student_id, s.full_name AS student_name, c.charge_type, c.amount, c.created_on,
                   bp.label AS period_label, act.name AS activity_name, lv.proficiency,
                   COALESCE((SELECT SUM(a.amount)
                               FROM payment_allocations a
                               JOIN payments p ON p.id = a.payment_id
                              WHERE a.charge_id = c.id AND p.status = 'VALID'), 0) AS paid
              FROM charges c
              JOIN students s ON s.id = c.student_id
              LEFT JOIN billing_periods bp ON bp.id = c.billing_period_id
              LEFT JOIN enrollments en ON en.id = c.enrollment_id
              LEFT JOIN activity_levels lv ON lv.id = en.activity_level_id
              LEFT JOIN activities act ON act.id = lv.activity_id
             WHERE c.student_id IN ($in)
             ORDER BY (c.charge_type = 'REGISTRATION') DESC, c.created_on, c.id"
         . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($studentIds);

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $outCents = to_cents($r['amount']) - to_cents($r['paid']);
        if ($outCents <= 0) {
            continue;
        }
        $r['outstanding'] = $outCents / 100;
        $r['label'] = $r['charge_type'] === 'REGISTRATION'
            ? 'Registration fee (one-time)'
            : trim(($r['activity_name'] ? $r['activity_name'] . ' (' . $r['proficiency'] . ') - ' : 'Activity fee - ')
                   . ($r['period_label'] ?: 'first month'));
        $rows[] = $r;
    }
    return $rows;
}

/** Total still owed by these students, in Ksh. @param int[] $studentIds */
function amount_due(array $studentIds, ?PDO $pdo = null): float
{
    $cents = 0;
    foreach (outstanding_charges($studentIds, $pdo) as $r) {
        $cents += to_cents($r['outstanding']);
    }
    return $cents / 100;
}

/**
 * The checkout summary shown to the parent: what is owed, grouped by child.
 * Returns ['children' => [studentId => ['name', 'lines' => [...], 'subtotal']], 'total' => float].
 *
 * @param int[] $studentIds
 */
function checkout_summary(array $studentIds, ?PDO $pdo = null): array
{
    $children = [];
    $totalCents = 0;
    foreach (outstanding_charges($studentIds, $pdo) as $r) {
        $sid = (int) $r['student_id'];
        $children[$sid]['name']  = $r['student_name'];
        $children[$sid]['lines'][] = ['label' => $r['label'], 'amount' => $r['outstanding']];
        $children[$sid]['subtotal'] = ($children[$sid]['subtotal'] ?? 0) + $r['outstanding'];
        $totalCents += to_cents($r['outstanding']);
    }
    return ['children' => $children, 'total' => $totalCents / 100];
}

/**
 * Spread a payment over the students' unpaid charges: registration fees first, then oldest first.
 * Call inside a transaction. Charges are locked while this runs, so two payments arriving at the
 * same moment cannot both pay the same charge.
 *
 * Money that is left over (the parent paid more than was owed) is NOT allocated; it stays on the
 * payment record as credit. Returns ['allocated' => float, 'unallocated' => float].
 *
 * @param int[] $studentIds
 */
function allocate_payment(PDO $pdo, int $paymentId, $amount, array $studentIds): array
{
    $remaining = to_cents($amount);
    $allocated = 0;

    $insert = $pdo->prepare('INSERT INTO payment_allocations (payment_id, charge_id, amount) VALUES (?, ?, ?)');

    foreach (outstanding_charges($studentIds, $pdo, true) as $charge) {
        if ($remaining <= 0) {
            break;
        }
        $part = min($remaining, to_cents($charge['outstanding']));
        $insert->execute([$paymentId, $charge['id'], $part / 100]);
        $remaining -= $part;
        $allocated += $part;
    }

    // Anyone who is now fully paid becomes ACTIVE and leaves the "pay later" follow-up list.
    try {
        sync_reservations($pdo, $studentIds);
    } catch (Throwable $ex) {
        error_log('Could not update registration status: ' . $ex->getMessage());   // never block a payment
    }

    return ['allocated' => $allocated / 100, 'unallocated' => $remaining / 100];
}

/** How much of a student's charges have been paid (valid payments only). */
function student_paid_total(int $studentId, ?PDO $pdo = null): float
{
    $pdo ??= db();
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(a.amount), 0)
           FROM payment_allocations a
           JOIN payments p ON p.id = a.payment_id
           JOIN charges c ON c.id = a.charge_id
          WHERE c.student_id = ? AND p.status = 'VALID'"
    );
    $stmt->execute([$studentId]);
    return (float) $stmt->fetchColumn();
}


// ---- "Pay later" reservations --------------------------------------------------------------------

/** Has migration 003 been run? Lets the site keep working if the new table is not there yet. */
function reservations_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'payment_reservations'")->fetchColumn();
        } catch (Throwable $ex) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The parent chose "Pay later": mark each of their children who still owes money as RESERVED so a
 * moderator can follow up. Safe to repeat; a child already CONTACTED stays CONTACTED.
 * Call inside a transaction. Returns how many children are on the follow-up list.
 *
 * @param int[] $studentIds
 */
function reserve_students(PDO $pdo, int $guardianId, array $studentIds): int
{
    $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
    if (!$studentIds) {
        return 0;
    }

    // Only this guardian's own children, and only those who still owe something.
    $in   = implode(',', array_fill(0, count($studentIds), '?'));
    $mine = $pdo->prepare("SELECT id FROM students WHERE guardian_id = ? AND id IN ($in)");
    $mine->execute(array_merge([$guardianId], $studentIds));
    $own  = array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN));

    $owing = array_unique(array_map('intval', array_column(outstanding_charges($own, $pdo), 'student_id')));

    // In ON DUPLICATE KEY UPDATE the columns are changed left to right, so `status` goes last.
    $insert = $pdo->prepare(
        "INSERT INTO payment_reservations (student_id, guardian_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
            reserved_at = IF(status = 'PAID', NOW(), reserved_at),
            paid_at     = IF(status = 'PAID', NULL, paid_at),
            status      = IF(status = 'PAID', 'RESERVED', status)"
    );
    $mark = $pdo->prepare("UPDATE students SET registration_status = 'RESERVED' WHERE id = ? AND registration_status = 'PAYMENT_PENDING'");
    foreach ($owing as $sid) {
        $insert->execute([$sid, $guardianId]);
        $mark->execute([$sid]);
    }
    return count($owing);
}

/**
 * Students who no longer owe anything become ACTIVE (students.registration_status), and their
 * pay-later follow-up (if any) is marked PAID. Call inside the payment's transaction.
 *
 * @param int[] $studentIds
 */
function sync_reservations(PDO $pdo, array $studentIds): void
{
    $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
    if (!$studentIds) {
        return;
    }
    $owing = array_map('intval', array_column(outstanding_charges($studentIds, $pdo), 'student_id'));

    $activate = $pdo->prepare("UPDATE students SET registration_status = 'ACTIVE' WHERE id = ? AND registration_status <> 'ACTIVE'");
    $done     = reservations_ready()
        ? $pdo->prepare("UPDATE payment_reservations SET status = 'PAID', paid_at = NOW() WHERE student_id = ? AND status <> 'PAID'")
        : null;

    foreach ($studentIds as $sid) {
        if (in_array($sid, $owing, true)) {
            continue;
        }
        $activate->execute([$sid]);
        if ($done !== null) {
            $done->execute([$sid]);
        }
    }
}

/** How many families chose "Pay later" and have not been contacted yet (shown in the staff menu). */
function reservation_new_count(): int
{
    if (!reservations_ready()) {
        return 0;
    }
    try {
        return (int) db()->query("SELECT COUNT(*) FROM payment_reservations WHERE status = 'RESERVED'")->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}
