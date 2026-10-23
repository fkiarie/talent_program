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
