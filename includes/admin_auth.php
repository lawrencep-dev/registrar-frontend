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

function admin_positive_int(mixed $value): ?int {
    if (!is_string($value) && !is_int($value)) {
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $validated === false ? null : $validated;
}

function admin_decimal_to_cents(mixed $value): ?int {
    if (!is_string($value) && !is_int($value)) {
        return null;
    }

    if (!preg_match('/^(?:\d{1,8}(?:\.\d{1,2})?|\.\d{1,2})$/D', (string)$value)) {
        return null;
    }

    $parts = explode('.', (string)$value, 2);
    $whole = $parts[0] === '' ? '0' : $parts[0];
    $fraction = $parts[1] ?? '';
    return ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
}

function admin_amount_to_cents(mixed $value): ?int {
    $cents = admin_decimal_to_cents($value);
    return $cents !== null && $cents > 0 ? $cents : null;
}

function admin_is_valid_date(mixed $value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }

    return checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4));
}

function admin_audit_log(
    int $adminId,
    string $action,
    ?string $targetType = null,
    ?int $targetId = null,
    array $details = []
): void {
    require_once __DIR__ . '/db.php';
    $allowedDetailKeys = [
        'student_id',
        'fee_type_id',
        'charge_id',
        'payment_id',
        'amount',
        'previous_amount',
        'new_amount',
        'paid_amount',
        'due_date',
        'previous_due_date',
        'default_amount',
        'previous_default_amount',
        'created_count',
        'skipped_count',
        'from_date',
        'to_date',
        'status',
        'search_applied',
        'search_length',
    ];
    $safeDetails = [];
    foreach ($allowedDetailKeys as $key) {
        if (array_key_exists($key, $details) && (is_string($details[$key]) || is_int($details[$key]))) {
            $safeDetails[$key] = $details[$key];
        }
    }
    $encodedDetails = $safeDetails === [] ? null : json_encode($safeDetails, JSON_THROW_ON_ERROR);
    $statement = db()->prepare(
        'INSERT INTO admin_audit_log (admin_id, action, target_type, target_id, details)
         VALUES (:admin_id, :action, :target_type, :target_id, :details)'
    );
    $statement->execute([
        'admin_id' => $adminId,
        'action' => $action,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'details' => $encodedDetails,
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
