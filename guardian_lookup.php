<?php
/**
 * guardian_lookup.php
 * A tiny "API" used by the registration form. The browser asks:
 *     guardian_lookup.php?id_number=12345678
 * and gets back JSON saying whether that guardian already exists, with their details.
 * (JSON is just text in a format JavaScript can read easily.)
 *
 * Only logged-in staff may use it. This is a convenience for the form: the real
 * check happens again on the server when the form is submitted.
 */
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in again.']);
    exit;
}

$raw = $_GET['id_number'] ?? '';
$id  = is_string($raw) ? normalize_id_number($raw) : '';

if (!valid_id_number($id)) {
    echo json_encode(['found' => false]);
    exit;
}

$stmt = db()->prepare(
    'SELECT g.full_name, g.phone, g.alt_phone,
            (SELECT COUNT(*) FROM students s WHERE s.guardian_id = g.id) AS children
       FROM guardians g
      WHERE g.id_number = ?'
);
$stmt->execute([$id]);
$g = $stmt->fetch();

if (!$g) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode([
    'found'     => true,
    'full_name' => $g['full_name'],
    'phone'     => phone_for_input($g['phone']),
    'alt_phone' => $g['alt_phone'] ? phone_for_input($g['alt_phone']) : '',
    'children'  => (int) $g['children'],
]);
