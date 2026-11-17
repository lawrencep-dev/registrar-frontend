<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

$errors = [];
$studentNumber = '';
$email = '';
$firstName = '';
$lastName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        $errors[] = 'Your request could not be verified. Reload the page and try again.';
    } else {
        $studentNumber = trim((string)($_POST['student_number'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $temporaryPassword = (string)($_POST['temporary_password'] ?? '');
        unset($_POST['temporary_password']);
        $passwordLength = preg_match_all('/./us', $temporaryPassword, $passwordCharacters);
        unset($passwordCharacters);

        if (!preg_match('/^\d{4}-\d{6}$/D', $studentNumber)) {
            $errors[] = 'Enter a student number in the format YYYY-######.';
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($firstName === '' || mb_strlen($firstName, 'UTF-8') > 100) {
            $errors[] = 'Enter a first name of up to 100 characters.';
        }
        if ($lastName === '' || mb_strlen($lastName, 'UTF-8') > 100) {
            $errors[] = 'Enter a last name of up to 100 characters.';
        }
        if ($passwordLength === false || $passwordLength < 10) {
            $errors[] = 'The temporary password must be at least 10 characters long.';
        }

        if ($errors === []) {
            try {
                $pdo = db();
                $duplicateStatement = $pdo->prepare(
                    'SELECT id FROM students
                     WHERE student_number = :student_number OR email = :email
                     LIMIT 1'
                );
                $duplicateStatement->execute([
                    'student_number' => $studentNumber,
                    'email' => $email,
                ]);
                if ($duplicateStatement->fetchColumn() !== false) {
                    $errors[] = 'Unable to add student. Check the details and try again.';
                } else {
                    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                    unset($temporaryPassword);
                    $pdo->beginTransaction();
                    $insertStatement = $pdo->prepare(
                        'INSERT INTO students (student_number, email, password_hash, first_name, last_name)
                         VALUES (:student_number, :email, :password_hash, :first_name, :last_name)'
                    );
                    $insertStatement->execute([
                        'student_number' => $studentNumber,
                        'email' => $email,
                        'password_hash' => $passwordHash,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ]);
                    $studentId = (int)$pdo->lastInsertId();
                    unset($passwordHash);
                    admin_audit_log((int)$_SESSION[ADMIN_SESSION_ID_KEY], 'student_added', 'student', $studentId);
                    $pdo->commit();
                    header('Location: student-view.php?id=' . $studentId . '&status=created');
                    exit;
                }
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                unset($temporaryPassword, $passwordHash);
                error_log('LCUPAY admin student creation failed.');
                $errors[] = 'Unable to add student. Check the details and try again.';
            }
        }
        unset($temporaryPassword);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add student | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php $activePage = 'students'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <header class="admin-page-header">
                    <div>
                        <h1 class="admin-page-title">Add student</h1>
                        <p class="admin-page-subtitle">Create a student account with a temporary password.</p>
                    </div>
                    <a class="admin-button admin-button-secondary" href="students.php">Back to students</a>
                </header>
                <?php foreach ($errors as $error): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
                <section class="admin-panel admin-form-panel">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="admin-form-grid">
                            <div class="admin-form-field">
                                <label for="student-number">Student number</label>
                                <input id="student-number" name="student_number" type="text" value="<?= htmlspecialchars($studentNumber, ENT_QUOTES, 'UTF-8') ?>" pattern="[0-9]{4}-[0-9]{6}" maxlength="32" required>
                                <small>Format: YYYY-######</small>
                            </div>
                            <div class="admin-form-field">
                                <label for="student-email">Email</label>
                                <input id="student-email" name="email" type="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" maxlength="254" required>
                            </div>
                            <div class="admin-form-field">
                                <label for="first-name">First name</label>
                                <input id="first-name" name="first_name" type="text" value="<?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
                            </div>
                            <div class="admin-form-field">
                                <label for="last-name">Last name</label>
                                <input id="last-name" name="last_name" type="text" value="<?= htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
                            </div>
                            <div class="admin-form-field">
                                <label for="temporary-password">Temporary password</label>
                                <input id="temporary-password" name="temporary_password" type="password" minlength="10" autocomplete="new-password" required>
                                <small>At least 10 characters.</small>
                            </div>
                        </div>
                        <div class="admin-form-actions">
                            <button class="admin-button" type="submit">Add student</button>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
