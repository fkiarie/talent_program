<?php
// api/mpesa_stk_demo.php - Simulated STK Push Processor
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

try {
    $guardian_id = intval($_POST['guardian_id'] ?? 0);
    $amount      = floatval($_POST['amount'] ?? 0);
    $phone       = trim($_POST['mpesa_phone'] ?? '');

    if ($guardian_id <= 0 || $amount <= 0) {
        throw new Exception("Invalid transaction payload.");
    }

    $pdo = get_db();

    // Fetch student under this guardian
    $stmt_s = $pdo->prepare("SELECT id FROM students WHERE guardian_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_s->execute([$guardian_id]);
    $student = $stmt_s->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Student record not found for allocation.");
    }

    $student_id = $student['id'];

    // Generate random realistic M-Pesa receipt code (e.g. QJK9283712)
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $mpesa_code = 'Q' . substr(str_shuffle($characters), 0, 9);

    $pdo->beginTransaction();

    // Record Payment
    $stmt_p = $pdo->prepare("
        INSERT INTO payments (mpesa_code, student_id, amount, paid_on, payer_phone, status, recorded_by, recorded_at) 
        VALUES (?, ?, ?, NOW(), ?, 'VALID', NULL, NOW())
    ");
    $stmt_p->execute([$mpesa_code, $student_id, $amount, $phone]);
    $payment_id = $pdo->lastInsertId();

    // Allocate payment to unpaid charges
    $stmt_c = $pdo->prepare("SELECT id, amount FROM charges WHERE student_id = ? ORDER BY id ASC");
    $stmt_c->execute([$student_id]);
    $charges = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

    $remaining_payment = $amount;
    foreach ($charges as $charge) {
        if ($remaining_payment <= 0) break;
        $alloc = min($remaining_payment, $charge['amount']);

        $stmt_a = $pdo->prepare("INSERT INTO payment_allocations (payment_id, charge_id, amount) VALUES (?, ?, ?)");
        $stmt_a->execute([$payment_id, $charge['id'], $alloc]);

        $remaining_payment -= $alloc;
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'SUCCESS',
        'message' => 'Payment processed successfully.',
        'mpesa_code' => $mpesa_code
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status' => 'ERROR',
        'message' => $e->getMessage()
    ]);
}