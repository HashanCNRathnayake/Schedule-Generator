<?php
// /schedule_gen/sso/callback.php
declare(strict_types=1);
session_start();

// Your db.php already loads autoload + .env and gives $conn (MySQLi)
require __DIR__ . '/../db.php';

use Jumbojett\OpenIDConnectClient;

$issuer       = $_ENV['AUTHORITY']; // .../{TENANT_ID}/v2.0
$clientId     = $_ENV['CLIENT_ID'];
$clientSecret = $_ENV['CLIENT_SECRET'];

$base     = rtrim($_ENV['BASE_URL'] ?? 'http://localhost:3000/schedule_gen', '/');
$redirect = $base . '/sso/callback.php';

$oidc = new OpenIDConnectClient($issuer, $clientId, $clientSecret);
$oidc->setRedirectURL($redirect);
$oidc->addScope(['openid', 'profile', 'email']); // 'email' may be absent; we'll fallback

try {
    // Redirects to Microsoft if no code; otherwise validates and returns here
    $oidc->authenticate();

    $claims = $oidc->getVerifiedClaims();

    $oid   = $claims->oid ?? null;
    $name  = $claims->name ?? '';
    $email = $claims->email ?? ($claims->preferred_username ?? null);

    if (!$oid) {
        throw new RuntimeException('Missing OID in ID token.');
    }

    // ----- Upsert user by OID -----
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    // Check if user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE oid = ?");
    $stmt->bind_param("s", $oid);
    $stmt->execute();
    $res  = $stmt->get_result();
    $row  = $res->fetch_assoc();

    if ($row) {
        $userId = (int)$row['id'];
        $stmt = $conn->prepare(
            "UPDATE users
       SET email = ?, display_name = ?, last_login_at = NOW(), last_login_ip = ?
       WHERE id = ?"
        );
        $stmt->bind_param("sssi", $email, $name, $ip, $userId);
        $stmt->execute();
    } else {
        // Insert new user
        $stmt = $conn->prepare(
            "INSERT INTO users (oid, email, display_name, last_login_at, last_login_ip)
       VALUES (?, ?, ?, NOW(), ?)"
        );
        $stmt->bind_param("ssss", $oid, $email, $name, $ip);
        $stmt->execute();
        $userId = (int)$conn->insert_id;

        // Give default 'User' role on first login (optional)
        // $ridRes = $conn->query("SELECT id FROM roles WHERE name='User' LIMIT 1");
        // if ($r = $ridRes->fetch_assoc()) {
        //     $roleId = (int)$r['id'];
        //     $stmt = $conn->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        //     $stmt->bind_param("ii", $userId, $roleId);
        //     $stmt->execute();
        // }
    }

    // Audit
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $stmt = $conn->prepare("INSERT INTO login_audit (user_id, ip, user_agent) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $ip, $ua);
    $stmt->execute();

    // ----- Session -----
    session_regenerate_id(true);
    $_SESSION['auth'] = [
        'user_id' => $userId,
        'oid'     => $oid,
        'name'    => $name,
        'email'   => $email,
    ];

    // Go to your real landing page
    header('Location: ' . $base . '/index.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    // echo "Sign-in failed.";
    echo "Sign-in failed: " . $e->getMessage();
}
