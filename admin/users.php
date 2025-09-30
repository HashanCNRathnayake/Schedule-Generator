<?php
// /schedule_gen/admin/users.php
declare(strict_types=1);
require __DIR__ . '/../auth/guard.php';
$me = $_SESSION['auth'] ?? null;

// Require Admin or SuperAdmin
// requireRole($conn, 'Admin');
requireLogin();   // anyone logged in can see the navbar

require __DIR__ . '/../db.php';
$baseUrl = $_ENV['BASE_URL'] ?? '/';




// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'update_roles' && $userId) {
        // Clear old roles
        $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // Insert new roles
        if (!empty($_POST['roles']) && is_array($_POST['roles'])) {
            $roleStmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($_POST['roles'] as $roleId) {
                $roleStmt->bind_param("ii", $userId, $roleId);
                $roleStmt->execute();
            }
        }
    }

    if ($action === 'delete' && $userId) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
    }

    //refresh the page to show changes
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/navbar.php';


// Fetch users
$sql = "SELECT u.id, u.display_name, u.email, GROUP_CONCAT(r.name) AS roles
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        GROUP BY u.id
        ORDER BY u.created_at DESC";
$result = $conn->query($sql);
$users = $result->fetch_all(MYSQLI_ASSOC);

// Fetch roles
$roleRes = $conn->query("SELECT id, name FROM roles ORDER BY id");
$allRoles = $roleRes->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html>

<head>
    <title>User Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        .dropdown-menu {
            max-height: 200px;
            overflow-y: auto;
        }
    </style>
</head>

<body class="container py-4">
    <?php if (hasRole($conn, 'Admin')): ?>

        <h1>User Management</h1>


        <table class="table table-bordered table-striped align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Roles</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['id']) ?></td>
                        <td><?= htmlspecialchars($user['display_name']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars($user['roles'] ?? 'None') ?></td>
                        <td>
                            <!-- Role Dropdown -->
                            <form method="post" class="d-inline">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="action" value="update_roles">

                                <div class="dropdown d-inline">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Change Roles
                                    </button>
                                    <div class="dropdown-menu p-2">
                                        <?php foreach ($allRoles as $role): ?>
                                            <?php $checked = (strpos($user['roles'] ?? '', $role['name']) !== false) ? 'checked' : ''; ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                    name="roles[]" value="<?= $role['id'] ?>" id="role<?= $user['id'] ?>-<?= $role['id'] ?>" <?= $checked ?>>
                                                <label class="form-check-label" for="role<?= $user['id'] ?>-<?= $role['id'] ?>">
                                                    <?= htmlspecialchars($role['name']) ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="mt-2">
                                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                        </div>
                                    </div>
                                </div>
                            </form>

                            <!-- Delete User -->
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this user?');">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif (!hasRole($conn, "Admin")): ?>
        <div class="alert alert-danger">You do not have permission to view this page.</div>
    <?php endif; ?>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>