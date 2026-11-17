<?php
const ADMIN_SESSION_NAME = 'LCUPAY_ADMIN';
const ADMIN_SESSION_ID_KEY = 'admin_id';
const ADMIN_CSRF_SESSION_KEY = 'admin_csrf_token';
const ADMIN_IDLE_TIMEOUT = 1800;

if (session_status() === PHP_SESSION_NONE) {
    session_name(ADMIN_SESSION_NAME);
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
} elseif (session_name() !== ADMIN_SESSION_NAME) {
    throw new RuntimeException('Admin pages cannot use a different active session.');
}

if (isset($_SESSION[ADMIN_SESSION_ID_KEY])) {
    $lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity < 1 || time() - $lastActivity >= ADMIN_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['admin_last_activity'] = time();
    }
}

function admin_csrf_token(): string {
    if (!isset($_SESSION[ADMIN_CSRF_SESSION_KEY]) || !is_string($_SESSION[ADMIN_CSRF_SESSION_KEY])) {
        $_SESSION[ADMIN_CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[ADMIN_CSRF_SESSION_KEY];
}

function admin_csrf_verify(?string $token): bool {
    return isset($_SESSION[ADMIN_CSRF_SESSION_KEY])
        && is_string($token)
        && hash_equals($_SESSION[ADMIN_CSRF_SESSION_KEY], $token);
}

function admin_audit_log(int $adminId, string $action, ?string $targetType = null, ?int $targetId = null): void {
    require_once __DIR__ . '/db.php';
    $statement = db()->prepare(
        'INSERT INTO admin_audit_log (admin_id, action, target_type, target_id)
         VALUES (:admin_id, :action, :target_type, :target_id)'
    );
    $statement->execute([
        'admin_id' => $adminId,
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
    ]);
}

function require_admin(): void {
    $adminId = (int)($_SESSION[ADMIN_SESSION_ID_KEY] ?? 0);
    if ($adminId < 1) {
        header('Location: login.php');
        exit;
    }

    try {
        require_once __DIR__ . '/db.php';
        $statement = db()->prepare('SELECT is_active FROM admins WHERE id = :admin_id LIMIT 1');
        $statement->execute(['admin_id' => $adminId]);
        $isActive = $statement->fetchColumn();
    } catch (Throwable $exception) {
        error_log('LCUPAY admin session verification failed.');
        http_response_code(500);
        exit('Unable to verify your admin session.');
    }

    if ($isActive === false || (int)$isActive !== 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }
}
