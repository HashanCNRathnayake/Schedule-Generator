<?php
// get_template.php
require __DIR__ . '/../../db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$course_id    = trim($_GET['course_id']    ?? '');
$module_code  = trim($_GET['module_code']  ?? '');
$learning_mode = trim($_GET['learning_mode'] ?? '');

if (!$user_id || !$course_id || !$module_code || !$learning_mode) {
    echo json_encode(['ok' => false, 'rows' => []]);
    exit;
}

function fetch_latest_template_rows(mysqli $conn, array $keys): array
{
    $sql = "SELECT id FROM session_templates
          WHERE course_id=? AND module_code=? AND learning_mode=? AND user_id=?
          ORDER BY created_at DESC LIMIT 1";
    $s = $conn->prepare($sql);
    $s->bind_param("sssi", $keys['course_id'], $keys['module_code'], $keys['learning_mode'], $keys['user_id']);
    $s->execute();
    $s->bind_result($tid);
    if (!$s->fetch()) {
        $s->close();
        return [];
    }
    $s->close();

    $rows = [];
    $r = $conn->prepare("SELECT session_no, session_type, session_details, duration_hr
                                 FROM session_template_rows
                                 WHERE template_id=? ORDER BY CAST(session_no AS UNSIGNED), session_no");
    $r->bind_param("i", $tid);
    $r->execute();
    $res = $r->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $r->close();
    return $rows;
}

$rows = fetch_latest_template_rows($conn, [
    'course_id' => $course_id,
    'module_code' => $module_code,
    'learning_mode' => $learning_mode,
    'user_id' => $user_id
]);

echo json_encode(['ok' => true, 'rows' => $rows]);
