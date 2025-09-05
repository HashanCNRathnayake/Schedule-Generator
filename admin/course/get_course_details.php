<?php
if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id']);
    exit;
}
$id = urlencode($_GET['id']);
$url = "https://ce.educlaas.com/product-app/views/courses/api/claas-ai-app/admin/get-course-details?id=$id";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);
$response = curl_exec($ch);
curl_close($ch);

header('Content-Type: application/json');
echo $response;
