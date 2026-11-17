<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$rawStudentId = $_GET['id'] ?? null;
$studentId = filter_var($rawStudentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($studentId === false) {
    http_response_code(400);
    exit('Invalid request.');
}

$errors = [];
$statusMessages = [
    'created' => 'Student account created.',
    'deactivated' => 'Student deactivated.',
    'reactivated' => 'Student reactivated.',
    'password-reset' => 'Temporary password reset.',
];
$statusMessage = '';
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $statusMessage = $statusMessages[$status];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_verify(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        $errors[] = 'Your request could not be verified. Reload the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $temporaryPassword = (string)($_POST['temporary_password'] ?? '');
        unset($_POST['temporary_password']);
        $passwordLength = $action === 'reset_password'
            ? preg_match_all('/./us', $temporaryPassword, $passwordCharacters)
            : null;
        unset($passwordCharacters);

        if ($action === 'reset_password' && ($passwordLength === false || $passwordLength < 10)) {
            $errors[] = 'The temporary password must be at least 10 characters long.';
        } elseif (!in_array($action, ['deactivate', 'reactivate', 'reset_password'], true)) {
            $errors[] = 'Invalid action.';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                if ($action === 'deactivate' || $action === 'reactivate') {
                    $isActive = $action === 'reactivate' ? 1 : 0;
                    $updateStatement = $pdo->prepare(
                        'UPDATE students SET is_active = :is_active WHERE id = :student_id'
                    );
                    $updateStatement->execute([
                        'is_active' => $isActive,
                        'student_id' => $studentId,
                    ]);
                    $auditAction = $action === 'reactivate' ? 'student_reactivated' : 'student_deactivated';
                } else {
                    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                    unset($temporaryPassword);
                    $updateStatement = $pdo->prepare(
                        'UPDATE students SET password_hash = :password_hash WHERE id = :student_id'
                    );
                    $updateStatement->execute([
                        'password_hash' => $passwordHash,
                        'student_id' => $studentId,
                    ]);
                    unset($passwordHash);
                    $auditAction = 'student_password_reset';
                }

                if ($updateStatement->rowCount() < 1) {
                    throw new RuntimeException('Student record was not updated.');
                }
                admin_audit_log((int)$_SESSION[ADMIN_SESSION_ID_KEY], $auditAction, 'student', $studentId);
                $pdo->commit();
                $redirectStatus = $action === 'reset_password'
                    ? 'password-reset'
                    : ($action === 'reactivate' ? 'reactivated' : 'deactivated');
                header('Location: student-view.php?id=' . $studentId . '&status=' . $redirectStatus);
                exit;
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                unset($temporaryPassword, $passwordHash);
                error_log('LCUPAY admin student update failed.');
                $errors[] = 'Unable to update the student right now.';
            }
        }
        unset($temporaryPassword);
    }
}

try {
    $studentStatement = db()->prepare(
        'SELECT id, student_number, email, first_name, last_name, is_active, created_at
         FROM students
         WHERE id = :student_id
         LIMIT 1'
    );
    $studentStatement->execute(['student_id' => $studentId]);
    $student = $studentStatement->fetch();
    if (!$student) {
        http_response_code(404);
        exit('Student not found.');
    }

    $chargesStatement = db()->prepare(
        'SELECT sc.id, ft.code AS fee_code, ft.name AS fee_name, sc.amount, sc.due_date, sc.status,
                GREATEST(sc.amount - COALESCE(p.paid_amount, 0), 0) AS remaining_balance
         FROM student_charges sc
         INNER JOIN fee_types ft ON ft.id = sc.fee_type_id
         LEFT JOIN (
             SELECT student_charge_id, SUM(amount) AS paid_amount
             FROM payments
             WHERE status = \'paid\'
             GROUP BY student_charge_id
         ) p ON p.student_charge_id = sc.id
         WHERE sc.student_id = :student_id
         ORDER BY sc.due_date, sc.id'
    );
    $chargesStatement->execute(['student_id' => $studentId]);
    $charges = $chargesStatement->fetchAll();

    $paymentsStatement = db()->prepare(
        'SELECT p.reference_number, p.amount, p.convenience_fee, p.payment_method, p.status,
                COALESCE(p.paid_at, p.created_at) AS payment_date,
                sc.id AS charge_id, ft.name AS fee_name
         FROM payments p
         INNER JOIN student_charges sc ON sc.id = p.student_charge_id
         INNER JOIN fee_types ft ON ft.id = sc.fee_type_id
         WHERE sc.student_id = :student_id
         ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC'
    );
    $paymentsStatement->execute(['student_id' => $studentId]);
    $payments = $paymentsStatement->fetchAll();
} catch (Throwable $exception) {
    if (isset($student) && $student) {
        error_log('LCUPAY admin student details failed.');
        http_response_code(500);
        exit('Unable to load student details right now.');
    }
    error_log('LCUPAY admin student lookup failed.');
    http_response_code(500);
    exit('Unable to load student details right now.');
}

$activePage = 'students';
$studentName = trim((string)$student['first_name'] . ' ' . (string)$student['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student details | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <header class="admin-page-header">
                    <div>
                        <h1 class="admin-page-title"><?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="admin-page-subtitle">Student account details, charges, and payments.</p>
                    </div>
                    <a class="admin-button admin-button-secondary" href="students.php">Back to students</a>
                </header>
                <?php if ($statusMessage !== ''): ?>
                    <p class="admin-success" role="status"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php foreach ($errors as $error): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
                <section class="admin-panel admin-student-details">
                    <dl>
                        <div><dt>Student number</dt><dd><?= htmlspecialchars((string)$student['student_number'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt>Email</dt><dd><?= htmlspecialchars((string)$student['email'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt>Status</dt><dd><?= (int)$student['is_active'] === 1 ? 'Active' : 'Inactive' ?></dd></div>
                        <div><dt>Created</dt><dd><?= htmlspecialchars((string)$student['created_at'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                    </dl>
                    <div class="admin-inline-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="<?= (int)$student['is_active'] === 1 ? 'deactivate' : 'reactivate' ?>">
                            <button class="admin-button <?= (int)$student['is_active'] === 1 ? 'admin-button-danger' : '' ?>" type="submit"><?= (int)$student['is_active'] === 1 ? 'Deactivate student' : 'Reactivate student' ?></button>
                        </form>
                    </div>
                </section>
                <section class="admin-panel admin-form-panel">
                    <h2 class="admin-panel-title">Reset temporary password</h2>
                    <form method="post" class="admin-password-reset-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <div class="admin-form-field">
                            <label for="temporary-password">New temporary password</label>
                            <input id="temporary-password" name="temporary_password" type="password" minlength="10" autocomplete="new-password" required>
                        </div>
                        <button class="admin-button" type="submit">Reset password</button>
                    </form>
                </section>
                <section class="admin-panel">
                    <h2 class="admin-panel-title">Charges</h2>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Fee type</th><th>Due date</th><th>Amount</th><th>Remaining</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php if ($charges === []): ?>
                                    <tr><td colspan="5">No charges found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($charges as $charge): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$charge['fee_name'] . ' (' . (string)$charge['fee_code'] . ')', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$charge['due_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(peso((string)$charge['amount']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(peso((string)$charge['remaining_balance']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(ucfirst((string)$charge['status']), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section class="admin-panel">
                    <h2 class="admin-panel-title">Payments</h2>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Date</th><th>Reference</th><th>Charge</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead>
                            <tbody>
                                <?php if ($payments === []): ?>
                                    <tr><td colspan="6">No payments found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$payment['payment_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$payment['reference_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$payment['fee_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$payment['payment_method'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(ucfirst((string)$payment['status']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(peso((string)$payment['amount']), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
