<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
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
}

function require_login(): void {
    $studentId = (int)($_SESSION['student_id'] ?? 0);
    if ($studentId < 1) {
        header('Location: ../login.php');
        exit;
    }

    try {
        require_once __DIR__ . '/db.php';
        $statement = db()->prepare('SELECT is_active FROM students WHERE id = :student_id LIMIT 1');
        $statement->execute(['student_id' => $studentId]);
        $isActive = $statement->fetchColumn();
    } catch (Throwable $exception) {
        error_log('LCUPAY student session verification failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('Unable to verify your student session.');
    }

    if ($isActive === false || (int)$isActive !== 1) {
        $_SESSION = [];
        $cookie = session_get_cookie_params();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        header('Location: ../login.php');
        exit;
    }
}

function csrf_token(): string {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool {
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}