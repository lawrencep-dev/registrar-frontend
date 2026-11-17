<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';

if ((int)($_SESSION[ADMIN_SESSION_ID_KEY] ?? 0) > 0) {
    header('Location: index.php');
    exit;
}

$loginError = '';
$lockoutUntil = (int)($_SESSION['admin_login_lockout_until'] ?? 0);
$isLockedOut = $lockoutUntil > time();

if (!$isLockedOut && $lockoutUntil !== 0) {
    unset($_SESSION['admin_login_lockout_until'], $_SESSION['admin_login_failed_attempts']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        $loginError = 'Invalid credentials';
    } elseif ($isLockedOut) {
        $loginError = 'Invalid credentials';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $admin = false;

        try {
            $statement = db()->prepare(
                'SELECT id, name, password_hash
                 FROM admins
                 WHERE email = :email AND is_active = 1
                 LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $admin = $statement->fetch();
        } catch (Throwable $exception) {
            error_log('LCUPAY admin login query failed.');
            $loginError = 'Unable to sign in right now.';
        }

        if ($loginError === '' && $admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION[ADMIN_SESSION_ID_KEY] = (int)$admin['id'];
            $_SESSION['admin_name'] = (string)$admin['name'];
            $_SESSION['admin_last_activity'] = time();
            unset(
                $_SESSION['admin_login_failed_attempts'],
                $_SESSION['admin_login_lockout_until'],
                $_SESSION[ADMIN_CSRF_SESSION_KEY]
            );

            try {
                $updateLogin = db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = :admin_id');
                $updateLogin->execute(['admin_id' => (int)$admin['id']]);
                admin_audit_log((int)$admin['id'], 'admin_login', 'admin', (int)$admin['id']);
                header('Location: index.php');
                exit;
            } catch (Throwable $exception) {
                $_SESSION = [];
                session_regenerate_id(true);
                error_log('LCUPAY admin login finalization failed.');
                $loginError = 'Unable to sign in right now.';
            }
        } elseif ($loginError === '') {
            $_SESSION['admin_login_failed_attempts'] = (int)($_SESSION['admin_login_failed_attempts'] ?? 0) + 1;
            if ($_SESSION['admin_login_failed_attempts'] >= 5) {
                $_SESSION['admin_login_lockout_until'] = time() + 60;
            }
            $loginError = 'Invalid credentials';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar sign in | LCUPAY</title>
    <link rel="stylesheet" href="../login.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-login">
    <main class="login-layout">
        <section class="login-left" aria-label="LCUPAY Registrar Portal">
            <div class="login-left-inner">
                <div class="login-brand">
                    <img src="../images/LCUP.png" alt="LCUP school logo" class="login-brand-image">
                    <h1>LCUPAY</h1>
                </div>
                <div class="login-story">
                    <div class="login-intro">
                        <h2>Registrar<br>portal.</h2>
                        <p>Manage student accounts, fee assignments, and payment records in one secure place.</p>
                    </div>
                    <section class="login-highlights" aria-labelledby="admin-highlights-title">
                        <h3 id="admin-highlights-title">Registrar tools</h3>
                        <ul>
                            <li>
                                <span class="login-highlight-index" aria-hidden="true">01</span>
                                <div><h4>Student accounts</h4><p>Keep student records and charges up to date.</p></div>
                            </li>
                            <li>
                                <span class="login-highlight-index" aria-hidden="true">02</span>
                                <div><h4>Payment tracking</h4><p>Review collections and outstanding balances.</p></div>
                            </li>
                        </ul>
                    </section>
                </div>
                <div class="login-promise"><p>SECURE · SIMPLE · CONVENIENT</p></div>
            </div>
        </section>
        <section class="login-right" aria-label="Admin sign in">
            <header class="login-heading">
                <h2>Welcome back</h2>
                <p>Sign in to access the registrar portal.</p>
            </header>
            <img src="../images/LCUP.png" alt="La Consolacion University Philippines seal" class="login-seal">
            <div class="login-form-area">
                <form class="login-card" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($loginError !== ''): ?>
                        <p role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <div class="login-field">
                        <label for="admin-email">Email</label>
                        <input id="admin-email" type="email" name="email" placeholder="Enter your admin email" autocomplete="username" required>
                    </div>
                    <div class="login-field">
                        <label for="admin-password">Password</label>
                        <input id="admin-password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    </div>
                    <div class="login-options"></div>
                    <button class="login-submit" type="submit">Sign in</button>
                </form>
            </div>
            <p class="login-footer">© 2026 Student Payment Portal</p>
        </section>
    </main>
</body>
</html>
