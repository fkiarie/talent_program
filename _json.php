<?php
/** api/_json.php - tiny helpers for the JSON endpoints in this folder. */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mpesa.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/** CSRF check that answers in JSON instead of an HTML error page. */
function json_csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 419);
    }
}
