<?php
date_default_timezone_set('Asia/Singapore');
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ./../login.php");
};

$username = $_SESSION['username'] ?? '';

$flash = $_SESSION['flash'] ?? null;
// unset($_SESSION['flash']);

require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/navbar.php';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
</head>

<body>
    <h4>Dashboard</h4>

</body>

</html>