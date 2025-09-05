<?php
require '../db.php';
session_start();
if ($_SESSION['role'] !== 'admin') exit('Access denied');

$id = (int)$_GET['id'];
$result = $conn->query("SELECT role FROM users WHERE id=$id");
$row = $result->fetch_assoc();
$new_role = ($row['role'] === 'admin') ? 'user' : 'admin';

$conn->query("UPDATE users SET role='$new_role' WHERE id=$id");
header("Location: ../admin/users.php");
