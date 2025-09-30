<?php
// /schedule_gen/auth/guard.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db.php';

/** Redirect to Microsoft login if not signed in */
function requireLogin(): void
{
    if (empty($_SESSION['auth']['oid'])) {
        $base = rtrim($_ENV['BASE_URL'] ?? '/schedule_gen', '/');
        header('Location: ' . $base . '/login.php');
        // header('Location: ' . $base . '/sso/callback.php');
        exit;
    }
}

/** Fetch roles from DB for the current user_id (fresh each request) */
function getUserRoles(mysqli $conn): array
{
    if (empty($_SESSION['auth']['user_id'])) return [];
    $sql = "SELECT r.name
          FROM user_roles ur
          JOIN roles r ON r.id = ur.role_id
          WHERE ur.user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $_SESSION['auth']['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $roles = [];
    while ($row = $res->fetch_assoc()) {
        $roles[] = $row['name'];
    }
    return $roles;
}

function hasRole(mysqli $conn, string $role): bool
{
    return in_array($role, getUserRoles($conn), true);
}

function requireRole(mysqli $conn, string $role): void
{
    requireLogin();
    if (!hasRole($conn, $role)) {
        http_response_code(403);
        echo "Forbidden: {$role} role required.";
        exit;
    }
}
