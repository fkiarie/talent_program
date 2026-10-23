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

    $stmt = $pdo->prepare('SELECT start_date FROM billing_periods WHERE id = ?');
    $stmt->execute([$periodId]);
    $start = $stmt->fetchColumn();

    if ($start === false) {
        return 0;
    }

    // One INSERT ... SELECT creates every missing charge in a single step.
    $insert = $pdo->prepare(
        "INSERT INTO charges (student_id, enrollment_id, billing_period_id, charge_type, amount)
         SELECT e.student_id, e.id, ?, 'ACTIVITY', e.monthly_fee
           FROM enrollments e
          WHERE e.status = 'ACTIVE'
            AND e.enrolled_on <= ?
            AND NOT EXISTS (SELECT 1 FROM charges c
                             WHERE c.enrollment_id = e.id AND c.billing_period_id = ?)"
    );
    $insert->execute([$periodId, $start, $periodId]);

    return $insert->rowCount();
}
