<?php
session_start();
require __DIR__ . '/db.php';

require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

// require __DIR__ . '/components/header.php';



?>
<!DOCTYPE html>
<html>

<head>
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer" />



</head>

<body class="bg-light d-flex justify-content-center align-items-center vh-100">

    <div class="card p-4 shadow-sm" style="width: 100%; max-width: 400px;">
        <h3 class="mb-3 text-center">🔐 Login</h3>
        <?php
        ob_start();
        // session_start();
        if (isset($_POST['g-recaptcha-response']) && !empty($_POST['g-recaptcha-response'])) {

            // 1. Validate reCAPTCHA token
            $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

            $verifyResponse = file_get_contents(
                'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($_ENV['RECAPTCHA_SECRET_KEY']) .
                    '&response=' . urlencode($recaptchaResponse)
            );

            $captchaData = json_decode($verifyResponse, true);

            if (!$captchaData['success']) {
                echo "<div class='alert alert-danger mt-3'>❌ reCAPTCHA failed. Please try again.</div>";
                exit;
            }

            $stmt = $conn->prepare("SELECT id, password, role, username FROM users WHERE BINARY username = ?");
            $stmt->bind_param("s", $_POST['username']);
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($id, $hash, $role, $username);
            if ($stmt->num_rows > 0 && $stmt->fetch() && password_verify($_POST['password'], $hash)) {
                $_SESSION['user_id'] = $id;
                $_SESSION['role'] = $role;
                $_SESSION['username'] = $username;
                echo "<script>window.location.href='index.php';</script>";
                // echo "<a href='index.php' class='btn btn-success-lg mt-3'>DashBoard</a>";
            } else {
                echo "<div class='alert alert-danger mt-3'>❌ Invalid credentials.</div>";
            }
        }
        ?>


        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input name="username" class="form-control" placeholder="Enter Username / Email" required>
            </div>

            <div class="mb-2">
                <label class="form-label">Password</label>
                <div class="input-group ">

                    <input class="form-control  shadow-none" type="password" name="password" id="password" placeholder="Enter password" autocomplete="off" required>

                    <button class="btn btn-outline-secondary shadow-none" type="button" onclick="togglePassword()">
                        <i id="toggleIcon" class="fa-solid fa-eye"></i>
                    </button>


                </div>

            </div>

            <div class="d-flex flex-row justify-content-center mt-4">
                <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($_ENV['RECAPTCHA_SITE_KEY']); ?>" data-callback="enableSubmit"></div>

            </div> <br />

            <button class="btn btn-primary w-100" id="submit-btn" disabled>Login</button>
        </form>
    </div>
    <script>
        function enableSubmit() {
            document.getElementById('submit-btn').disabled = false;
        }

        function togglePassword() {
            const pwdInput = document.getElementById('password');
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
    </script>


</body>

</html>