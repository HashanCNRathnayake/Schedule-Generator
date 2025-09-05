<?php
require __DIR__ . '/db.php';

// Add to a common file or at the top of each relevant file
function set_flash($type, $message)
{
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $_SESSION['flash'] = [
    'type' => $type, // 'success', 'danger', etc.
    'message' => $message
  ];
}

// Create tables

// Create users table
$conn->query("
CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL UNIQUE,
  password VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL,
  role ENUM('admin','user') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'user'
) 
");

// Seed users if empty
$res = $conn->query("SELECT COUNT(*) AS c FROM users");
$row = $res->fetch_assoc();
if ((int)$row['c'] === 0) {
  $seed = [
    ['Admin', '$2y$10$IbygD/njdhffGN6Ja/iCNemvprLo3mgcGOi8k2H2rcRz3Mr9xbIZq', 'admin'],
    ['AdminUser', '$2y$10$IbygD/njdhffGN6Ja/iCNemvprLo3mgcGOi8k2H2rcRz3Mr9xbIZq', 'user'],
  ];
  // Admin - admin@A2%0a
  // AdminUser - admin@A2%0a
  $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
  foreach ($seed as $s) {
    $stmt->bind_param('sss', $s[0], $s[1], $s[2]);
    $stmt->execute();
  }
  $stmt->close();
}

$conn->query("
CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id VARCHAR(64) NOT NULL,
  course_code VARCHAR(64),
  course_title_external VARCHAR(255),
  UNIQUE KEY (course_id)
); 
");


set_flash('success', 'Setup complete.');
header("Location: index.php");
exit;


// echo "✅ Setup complete. Add data <a class='btn btn-primary' href='add_data.php'>add</a>";
