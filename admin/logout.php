<?php
require_once __DIR__ . '/../includes/admin_auth.php';

$adminId = (int)($_SESSION[ADMIN_SESSION_ID_KEY] ?? 0);
if ($adminId > 0) {
    try {
        admin_audit_log($adminId, 'admin_logout', 'admin', $adminId);
    } catch (Throwable $exception) {
        error_log('LCUPAY admin logout audit failed.');
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookieParameters = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => '/',
        'domain' => $cookieParameters['domain'],
        'secure' => $cookieParameters['secure'],
        'httponly' => $cookieParameters['httponly'],
        'samesite' => $cookieParameters['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;
