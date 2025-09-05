<?php
session_start();

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require '../db.php';
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$success = $error = '';
$usernameDisplay = $passDisplay = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['new_username'];
    $role = $_POST['role'];
    $pass = $_POST['new_password'];
    $password = password_hash($pass, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password, $role);

    if ($stmt->execute()) {
        $success = "✅ User Created Successfully!";
        $usernameDisplay = $username;
        $passDisplay = $pass;
    } else {
        $error = "❌ Username may already exist.";
    }
}
?>

<head>
    <title>Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class=" ">
    <div class=" ">
        <?php include '../components/navbar.php'; ?>

        <div class="d-flex flex-column align-items-center">
            <div class="card shadow p-4 mt-5" style="max-width: 500px; width: 100%;">
                <h4 class="mb-2 text-center">Create Users</h4>

                <?php if ($success): ?>
                    <div class="alert alert-success text-center">
                        <?= $success ?><br>
                        <strong>Username:</strong> <?= htmlspecialchars($usernameDisplay) ?><br>
                        <strong>Password:</strong> <?= htmlspecialchars($passDisplay) ?>
                    </div>
                <?php elseif ($error): ?>
                    <div class="alert alert-danger text-center"><?= $error ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <div class="mb-2">
                        <label for="role" class="form-label">User Role</label>
                        <select class="form-select shadow-none" name="role" id="role" required>
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Username</label>
                        <input class="form-control  shadow-none" name="new_username" id="new_username" placeholder="Enter username" autocomplete="off" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Password</label>
                        <div class="input-group ">

                            <input class="form-control  shadow-none" type="password" name="new_password" id="new_password" placeholder="Enter password" autocomplete="off" required>
                            <button class="btn btn-outline-secondary shadow-none" type="button" onclick="togglePassword()">
                                <i id="toggleIcon" class="fa-solid fa-eye"></i>
                            </button>

                            <button type="button" class="btn btn-outline-secondary " onclick="generatePassword()">🔐 Generate</button>


                        </div>

                    </div>

                    <!-- <div class="mb-3 d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm" onclick="generatePassword()">🔐 Generate</button>
                        <input type="text" id="generated_password" class="form-control form-control-sm" readonly style="max-width: 200px;">
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="copyPassword()"><i class="fa-solid fa-copy text-primary"></i></button>
                    </div> -->

                    <button class="btn btn-primary w-100">Create</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const pwdInput = document.getElementById('new_password');
            const icon = document.getElementById('toggleIcon');

            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pwdInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function generatePassword() {
            const digits = '0123456789';
            const letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            const specials = '@#$%&';

            function getRandomChars(source, count) {
                return Array.from({
                    length: count
                }, () => source[Math.floor(Math.random() * source.length)]);
            }

            const partDigits = getRandomChars(digits, 2);
            const partLetters = getRandomChars(letters, 2);
            const partSpecials = getRandomChars(specials, 2);

            const fullSet = [...partDigits, ...partLetters, ...partSpecials];

            // Shuffle the characters
            const password = fullSet.sort(() => Math.random() - 0.5).join('');

            // document.getElementById('generated_password').value = password;
            document.getElementById('new_password').value = password;
        }

        function copyPassword() {
            const passField = document.getElementById('generated_password');
            passField.select();
            passField.setSelectionRange(0, 99999);
            document.execCommand("copy");
        }
    </script>
</body>

</html>