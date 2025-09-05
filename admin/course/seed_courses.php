<?php
require __DIR__ . '/../../db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if ($_SESSION['role'] !== 'admin') {
    die("Access denied.");
}
// fetch from API
$url = "https://ce.educlaas.com/product-app/views/courses/api/claas-ai-app/admin/get-course-information";
$json = file_get_contents($url);
$data = json_decode($json, true);

// loop and insert/update
$stmt = $conn->prepare("
    INSERT INTO courses (course_id, course_code, course_title_external)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      course_code = VALUES(course_code),
      course_title_external = VALUES(course_title_external)
");

foreach ($data['data'] as $row) {
    $stmt->bind_param("sss", $row['course_id'], $row['course_code'], $row['course_title_external']);
    $stmt->execute();
}

echo "Courses saved/updated: " . count($data['data']);
