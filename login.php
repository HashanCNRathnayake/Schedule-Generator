<?php
// session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/auth/guard.php';
$me = $_SESSION['auth'] ?? null;

require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$baseUrl = $_ENV['BASE_URL'] ?? '/';

require __DIR__ . '/components/header.php';
require __DIR__ . '/components/navbar.php';

?>
<!DOCTYPE html>
<html>

<head>
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<!-- <body class="bg-light d-flex justify-content-center align-items-center vh-100"> -->

<body class="bg-light">

    <div class="card p-4 shadow-sm text-center" style="width: 100%; max-width: 400px;margin: auto; margin-top: 5%;">

        <h3 class="mb-3 text-center">Sign-in</h3><br><br>

        <?php if ($me): ?>
            <h6>Signed in as: </h6><br>
            <h4>
                <?= htmlspecialchars($me['name'] ?: ($me['email'] ?? $me['oid'])) ?>
            </h4>

            <?php if (hasRole($conn, 'Admin')): ?>
                <p><a href="<?= htmlspecialchars($_ENV['BASE_URL']) ?>/admin/courses/master_temp.php"><button class="btn btn-primary mt-4">Upload Your Template</button></a></p>
            <?php endif; ?>

        <?php else: ?>
            <a href="<?= htmlspecialchars($_ENV['BASE_URL']) ?>/sso/callback.php">
                <img src="<?= htmlspecialchars($_ENV['BASE_URL']) ?>/imgs/ms-symbollockup_signin_light.svg"
                    alt="Sign in with Microsoft"
                    style="height: 40px;" />
            </a>
        <?php endif; ?>

        <div class="mb-3">

        </div>

        <div class="mb-2">

        </div>


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