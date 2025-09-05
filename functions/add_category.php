<?php
require __DIR__ . '/../db.php';

$name  = trim($_POST['name'] ?? '');
$color = strtolower(trim($_POST['color'] ?? 'blue'));
$allowed = ['blue', 'green', 'yellow', 'pink', 'red', 'purple', 'orange', 'teal', 'gray', 'brown'];
if (!in_array($color, $allowed, true)) $color = 'blue';

if ($name !== '') {
    $stmt = $conn->prepare("INSERT IGNORE INTO categories (name, color) VALUES (?, ?)");
    $stmt->bind_param('ss', $name, $color);
    $stmt->execute();
    $stmt->close();
}
header("Location: ../categories.php?ok=1");
