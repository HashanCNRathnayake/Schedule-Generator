<?php
// ajax_save_rows.php
session_start();
header('Content-Type: application/json');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!isset($data['rows']) || !is_array($data['rows'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
        exit;
    }

    // Trust the incoming structure: no, type, details, duration, faculty, date (Y-m-d), day, time
    $_SESSION['generated'] = $data['rows'];

    echo json_encode(['ok' => true, 'count' => count($data['rows'])]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
