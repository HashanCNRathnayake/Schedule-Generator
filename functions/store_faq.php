<?php
require __DIR__ . '/../db.php';
session_start();

if (!isset($_SESSION['user_id'])) exit("Unauthorized");

$userId = $_SESSION['user_id'];

$question = $_POST['question'] ?? '';
$answer   = $_POST['answer'] ?? '';
$category = $_POST['category_id'] ?? '';
$keywords = $_POST['keywords'] ?? null;
$active   = $_POST['active'] ?? 'TRUE';
$pg       = $_POST['person_or_group'] ?? null;

if (!$question || !$answer || !$category) {
  header("Location: ../index.php?err=missing");
  exit;
}

$active = ($active === 'FALSE') ? 'FALSE' : 'TRUE';

$stmt = $conn->prepare("
  INSERT INTO faqs (user_id, question, answer, category_id, keywords, active, person_or_group)
  VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param('ississs', $userId, $question, $answer, $category, $keywords, $active, $pg);
$stmt->execute();
$stmt->close();

header("Location: ../index.php?ok=1");
