<?php
session_start();
require '../db.php';

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';


if ($_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}
$result = $conn->query("SELECT id, username, role FROM users");

require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/navbar.php';

?>

<head>
    <title>User Management</title>
</head>

<body class=" ">

    <div class=" ">

        <!-- <a href="../index.php" class="btn btn-secondary my-3">← Back</a> -->
        <div class="d-flex align-items-center justify-content-between">
            <h4 class="mb-3">User Management</h4>
            <a class="btn btn-success btn-sm" href="<?= htmlspecialchars($baseUrl) ?>admin/register.php">Create Users</a>

        </div>


        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['username']) ?></td>
                        <td><span class="badge <?= $row['role'] === 'admin' ? 'bg-primary' : 'bg-secondary' ?>"><?= $row['role'] ?></span></td>
                        <td>
                            <a href="../functions/update_role.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Toggle Role</a>
                            <a href="../functions/delete_user.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete user?')">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

    </div>

</body>

</html>