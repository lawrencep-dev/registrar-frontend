<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$errors = [];
$values = [
    'student_number' => '',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        $errors[] = 'Your session has expired. Please try again.';
    } else {
        $values['student_number'] = trim((string)($_POST['student_number'] ?? ''));
        $values['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
        $values['first_name'] = trim((string)($_POST['first_name'] ?? ''));
        $values['last_name'] = trim((string)($_POST['last_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');

        if (!preg_match('/^[0-9]{4}-[0-9]{6}$/', $values['student_number'])) {
            $errors[] = 'Enter a valid student ID in YYYY-XXXXXX format.';
        }
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($values['first_name'] === '' || mb_strlen($values['first_name']) > 100) {
            $errors[] = 'Enter a valid first name.';
        }
        if ($values['last_name'] === '' || mb_strlen($values['last_name']) > 100) {
            $errors[] = 'Enter a valid last name.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!hash_equals($password, $passwordConfirmation)) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors === []) {
            $connection = null;
            try {
                $connection = db();
                $connection->beginTransaction();
                $duplicate = $connection->prepare(
                    'SELECT id FROM students
                     WHERE student_number = :student_number OR email = :email
                     LIMIT 1'
                );
                $duplicate->execute([
                    'student_number' => $values['student_number'],
                    'email' => $values['email'],
                ]);

                if ($duplicate->fetch()) {
                    $connection->rollBack();
                    $errors[] = 'A student account already exists with that student ID or email.';
                } else {
                    $feeTypes = $connection->query(
                        'SELECT id, default_amount
                         FROM fee_types
                         WHERE is_active = 1
                         ORDER BY id
                         FOR UPDATE'
                    )->fetchAll();

                    if ($feeTypes === []) {
                        $connection->rollBack();
                        $errors[] = 'Unable to create your account because no active fee types are configured.';
                    } else {
                        $insert = $connection->prepare(
                            'INSERT INTO students
                                (student_number, email, password_hash, first_name, last_name, is_active)
                             VALUES
                                (:student_number, :email, :password_hash, :first_name, :last_name, 1)'
                        );
                        $insert->execute([
                            'student_number' => $values['student_number'],
                            'email' => $values['email'],
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'first_name' => $values['first_name'],
                            'last_name' => $values['last_name'],
                        ]);
                        $studentId = (int)$connection->lastInsertId();

                        $insertCharge = $connection->prepare(
                            'INSERT INTO student_charges (student_id, fee_type_id, amount, due_date)
                             VALUES (:student_id, :fee_type_id, :amount, DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY))'
                        );
                        foreach ($feeTypes as $feeType) {
                            $insertCharge->execute([
                                'student_id' => $studentId,
                                'fee_type_id' => (int)$feeType['id'],
                                'amount' => (string)$feeType['default_amount'],
                            ]);
                        }

                        $connection->commit();
                        $_SESSION['registration_success'] = 'Account created successfully. You can now sign in.';
                        header('Location: login.php');
                        exit;
                    }
                }
            } catch (Throwable $exception) {
                if ($connection instanceof PDO && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                error_log('LCUPAY registration failed: ' . $exception->getMessage());
                $errors[] = 'Unable to create your account right now. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
    <main class="login-layout">
        <section class="login-left" aria-label="LCUPAY Portal">
            <div class="login-left-inner">
                <div class="login-brand">
                    <img src="images/LCUP.png" alt="LCUP school logo" class="login-brand-image">
                    <h1>LCUPAY PORTAL</h1>
                </div>
                <div class="login-story">
                    <div class="login-intro">
                        <h2>Set up your account.<br>Stay on track.</h2>
                        <p>Create your student portal account to view fees and manage payments.</p>
                    </div>
                </div>
                <div class="login-promise">
                    <p>SECURE · SIMPLE · CONVENIENT</p>
                </div>
            </div>
        </section>

        <section class="login-right" aria-label="Create account">
            <header class="login-heading">
                <h2>Create your account</h2>
                <p>Enter your student details to register.</p>
            </header>

            <img src="images/LCUP.png" alt="La Consolacion University Philippines seal" class="login-seal">

            <div class="login-form-area">
                <form class="login-card" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($errors !== []): ?>
                        <div role="alert">
                            <?php foreach ($errors as $error): ?>
                                <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="login-field">
                        <label for="student-number">Student ID</label>
                        <input id="student-number" type="text" name="student_number" value="<?= htmlspecialchars($values['student_number'], ENT_QUOTES, 'UTF-8') ?>" placeholder="2026-001284" pattern="[0-9]{4}-[0-9]{6}" maxlength="32" autocomplete="username" required>
                    </div>
                    <div class="login-field">
                        <label for="register-email">Email</label>
                        <input id="register-email" type="email" name="email" value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" maxlength="254" autocomplete="email" required>
                    </div>
                    <div class="login-field">
                        <label for="first-name">First name</label>
                        <input id="first-name" type="text" name="first_name" value="<?= htmlspecialchars($values['first_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" autocomplete="given-name" required>
                    </div>
                    <div class="login-field">
                        <label for="last-name">Last name</label>
                        <input id="last-name" type="text" name="last_name" value="<?= htmlspecialchars($values['last_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" autocomplete="family-name" required>
                    </div>
                    <div class="login-field">
                        <label for="register-password">Password</label>
                        <input id="register-password" type="password" name="password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="login-field">
                        <label for="register-password-confirmation">Confirm password</label>
                        <input id="register-password-confirmation" type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required>
                    </div>

                    <button class="login-submit" type="submit" style="margin-top: 22px;">Create account</button>
                    <p class="login-register">Already have an account? <a href="login.php">Sign in</a></p>
                </form>
            </div>

            <p class="login-footer">© 2026 Student Payment Portal</p>
        </section>
    </main>
</body>
</html>
