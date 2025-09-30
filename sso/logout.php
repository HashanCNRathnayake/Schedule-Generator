<?php
// /schedule_gen/sso/logout.php
declare(strict_types=1);
session_start();
require __DIR__ . '/../db.php'; // for $_ENV

// 1) End local session
$_SESSION = [];
session_destroy();

// 2) Also sign out at Microsoft, then return to your app home
$tenantId   = $_ENV['TENANT_ID'] ?? '';
$base       = rtrim($_ENV['BASE_URL'] ?? '/', '/') . '/';
$logoutUrl  = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/logout"
    . "?post_logout_redirect_uri=" . urlencode($base);

header("Location: {$logoutUrl}");
// header("Location: ../index.php");
exit;
