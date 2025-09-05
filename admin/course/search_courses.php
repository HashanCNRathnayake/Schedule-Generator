<?php
require __DIR__ . '/../../db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if ($_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

$q = $_GET['q'] ?? '';

// remove square brackets
$q = preg_replace('/[\[\]]/', '', $q);

// split into words
$words = preg_split('/\s+/', trim($q)); // split by spaces

$conditions = [];
foreach ($words as $w) {
    $w = "%" . $conn->real_escape_string($w) . "%";
    $conditions[] = "(course_title_external LIKE '$w' 
                     OR course_code LIKE '$w' 
                     OR course_id LIKE '$w')";
}

// join with AND so all words must match
$where = implode(" AND ", $conditions);

$sql = "SELECT course_id, course_code, course_title_external 
        FROM courses 
        WHERE $where
        ORDER BY course_title_external 
        LIMIT 10";

$res = $conn->query($sql);

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode($data);
