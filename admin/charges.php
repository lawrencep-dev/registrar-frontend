<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];
$createdCount = null;
$skippedCount = null;
$createQueryCount = admin_positive_int($_GET['created'] ?? null);
$skipQueryCount = admin_positive_int($_GET['skipped'] ?? null);
if (array_key_exists('created', $_GET) || array_key_exists('skipped', $_GET)) {
    $createdCount = $createQueryCount ?? 0;
    $skippedCount = $skipQueryCount ?? 0;
}

$statusMessages = [
    'updated' => 'Charge updated.',
    'deleted' => 'Charge deleted.',
];
$notice = '';
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $notice = $statusMessages[$status];
}

$rawPage = $_GET['page'] ?? '1';
$page = admin_positive_int($rawPage);
if ($page === null) {
    http_response_code(400);
    exit('Invalid request.');
}

$editChargeId = null;
if (array_key_exists('edit_id', $_GET)) {
    $editChargeId = admin_positive_int($_GET['edit_id']);
    if ($editChargeId === null) {
        http_response_code(400);
        exit('Invalid request.');
    }
}

$recipient = 'all_active';
$selectedFeeTypeId = null;
$dueDate = '';
$amountInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!admin_csrf_verify(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your request could not be verified. Reload the page and try again.';
    } else {
        $actionValue = $_POST['action'] ?? '';
        $action = is_string($actionValue) ? $actionValue : '';
        if ($action === 'assign') {
            $recipient = $_POST['recipient'] ?? '';
            if (!is_string($recipient)) {
                $recipient = '';
            }
            $selectedFeeTypeId = admin_positive_int($_POST['fee_type_id'] ?? null);
            $dueDate = isset($_POST['due_date']) && is_string($_POST['due_date']) ? $_POST['due_date'] : '';
            $amountInput = array_key_exists('amount', $_POST)
                ? (is_string($_POST['amount']) ? trim($_POST['amount']) : "\0")
                : '';
            $submittedAmountCents = $amountInput === '' ? null : admin_amount_to_cents($amountInput);

            if ($selectedFeeTypeId === null) {
                $errors[] = 'Select an active fee type.';
            }
            if ($recipient !== 'all_active' && admin_positive_int($recipient) === null) {
                $errors[] = 'Select a valid student or all active students.';
            }
            if (!admin_is_valid_date($dueDate)) {
                $errors[] = 'Enter a valid due date.';
            }
            if ($amountInput !== '' && $submittedAmountCents === null) {
                $errors[] = 'Enter an amount greater than zero with no more than two decimal places.';
            }

            if ($errors === []) {
                $pdo = null;
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    $feeTypeStatement = $pdo->prepare(
                        'SELECT id, default_amount
                         FROM fee_types
                         WHERE id = :fee_type_id AND is_active = 1
                         FOR UPDATE'
                    );
                    $feeTypeStatement->execute(['fee_type_id' => $selectedFeeTypeId]);
                    $feeType = $feeTypeStatement->fetch();
                    if (!$feeType) {
                        $pdo->rollBack();
                        $errors[] = 'The selected fee type is unavailable.';
                    } else {
                        $amountCents = $amountInput === ''
                            ? admin_amount_to_cents((string)$feeType['default_amount'])
                            : $submittedAmountCents;
                        if ($amountCents === null) {
                            $pdo->rollBack();
                            $errors[] = 'Enter an amount greater than zero with no more than two decimal places.';
                        } else {
                            $amount = decimal_from_cents($amountCents);
                            $created = 0;
                            $skipped = 0;
                            $duplicateStatement = $pdo->prepare(
                                'SELECT id
                                 FROM student_charges
                                 WHERE student_id = :student_id AND fee_type_id = :fee_type_id
                                 LIMIT 1
                                 FOR UPDATE'
                            );
                            $insertStatement = $pdo->prepare(
                                'INSERT INTO student_charges (student_id, fee_type_id, amount, due_date)
                                 VALUES (:student_id, :fee_type_id, :amount, :due_date)'
                            );

                            if ($recipient === 'all_active') {
                                $studentsStatement = $pdo->prepare(
                                    'SELECT id FROM students WHERE is_active = 1 ORDER BY id FOR UPDATE'
                                );
                                $studentsStatement->execute();
                                $activeStudents = $studentsStatement->fetchAll();
                                foreach ($activeStudents as $activeStudent) {
                                    $studentId = (int)$activeStudent['id'];
                                    $duplicateStatement->execute([
                                        'student_id' => $studentId,
                                        'fee_type_id' => $selectedFeeTypeId,
                                    ]);
                                    if ($duplicateStatement->fetchColumn() !== false) {
                                        $skipped++;
                                        continue;
                                    }

                                    $insertStatement->execute([
                                        'student_id' => $studentId,
                                        'fee_type_id' => $selectedFeeTypeId,
                                        'amount' => $amount,
                                        'due_date' => $dueDate,
                                    ]);
                                    $chargeId = (int)$pdo->lastInsertId();
                                    admin_audit_log(
                                        (int)$_SESSION[ADMIN_SESSION_ID_KEY],
                                        'charge_assigned',
                                        'charge',
                                        $chargeId,
                                        [
                                            'student_id' => $studentId,
                                            'fee_type_id' => $selectedFeeTypeId,
                                            'amount' => $amount,
                                            'due_date' => $dueDate,
                                        ]
                                    );
                                    $created++;
                                }
                            } else {
                                $studentId = admin_positive_int($recipient);
                                $studentStatement = $pdo->prepare(
                                    'SELECT id FROM students WHERE id = :student_id AND is_active = 1 FOR UPDATE'
                                );
                                $studentStatement->execute(['student_id' => $studentId]);
                                if ($studentStatement->fetchColumn() === false) {
                                    $pdo->rollBack();
                                    $errors[] = 'The selected student is unavailable.';
                                } else {
                                    $duplicateStatement->execute([
                                        'student_id' => $studentId,
                                        'fee_type_id' => $selectedFeeTypeId,
                                    ]);
                                    if ($duplicateStatement->fetchColumn() !== false) {
                                        $pdo->rollBack();
                                        $errors[] = 'A charge for this fee type already exists for the student.';
                                    } else {
                                        $insertStatement->execute([
                                            'student_id' => $studentId,
                                            'fee_type_id' => $selectedFeeTypeId,
                                            'amount' => $amount,
                                            'due_date' => $dueDate,
                                        ]);
                                        $chargeId = (int)$pdo->lastInsertId();
                                        admin_audit_log(
                                            (int)$_SESSION[ADMIN_SESSION_ID_KEY],
                                            'charge_assigned',
                                            'charge',
                                            $chargeId,
                                            [
                                                'student_id' => $studentId,
                                                'fee_type_id' => $selectedFeeTypeId,
                                                'amount' => $amount,
                                                'due_date' => $dueDate,
                                            ]
                                        );
                                        $created++;
                                    }
                                }
                            }

                            if ($errors === []) {
                                $pdo->commit();
                                header('Location: charges.php?created=' . $created . '&skipped=' . $skipped);
                                exit;
                            }
                        }
                    }
                } catch (Throwable $exception) {
                    if ($pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('LCUPAY admin charge assignment failed.');
                    $errors[] = 'Unable to assign the charge right now.';
                }
            }
        } elseif ($action === 'edit' || $action === 'delete') {
            $chargeId = admin_positive_int($_POST['id'] ?? null);
            if ($chargeId === null) {
                $errors[] = 'Invalid charge.';
            } else {
                $newAmountInput = isset($_POST['amount']) && is_string($_POST['amount'])
                    ? trim($_POST['amount'])
                    : '';
                $newAmountCents = $action === 'edit' ? admin_amount_to_cents($newAmountInput) : null;
                $newDueDate = isset($_POST['due_date']) && is_string($_POST['due_date'])
                    ? $_POST['due_date']
                    : '';
                if ($action === 'edit' && $newAmountCents === null) {
                    $errors[] = 'Enter an amount greater than zero with no more than two decimal places.';
                }
                if ($action === 'edit' && !admin_is_valid_date($newDueDate)) {
                    $errors[] = 'Enter a valid due date.';
                }

                if ($errors === []) {
                    $pdo = null;
                    try {
                        $pdo = db();
                        $pdo->beginTransaction();
                        $chargeStatement = $pdo->prepare(
                            'SELECT id, student_id, fee_type_id, amount, due_date
                             FROM student_charges
                             WHERE id = :charge_id
                             FOR UPDATE'
                        );
                        $chargeStatement->execute(['charge_id' => $chargeId]);
                        $charge = $chargeStatement->fetch();
                        if (!$charge) {
                            $pdo->rollBack();
                            $errors[] = 'Charge not found.';
                        } elseif ($action === 'edit') {
                            $paidStatement = $pdo->prepare(
                                'SELECT COALESCE(SUM(amount), 0)
                                 FROM payments
                                 WHERE student_charge_id = :charge_id AND status = \'paid\''
                            );
                            $paidStatement->execute(['charge_id' => $chargeId]);
                            $paidCents = admin_decimal_to_cents((string)$paidStatement->fetchColumn());
                            $currentAmountCents = admin_amount_to_cents((string)$charge['amount']);
                            if ($paidCents === null || $currentAmountCents === null) {
                                $pdo->rollBack();
                                $errors[] = 'Unable to calculate the charge balance.';
                            } elseif ($newAmountCents < $paidCents) {
                                $pdo->rollBack();
                                $errors[] = 'The charge amount cannot be less than the amount already paid.';
                            } else {
                                $newStatus = $paidCents === 0
                                    ? 'unpaid'
                                    : ($paidCents === $newAmountCents ? 'paid' : 'partial');
                                $newAmount = decimal_from_cents($newAmountCents);
                                $updateStatement = $pdo->prepare(
                                    'UPDATE student_charges
                                     SET amount = :amount, due_date = :due_date, status = :status
                                     WHERE id = :charge_id'
                                );
                                $updateStatement->execute([
                                    'amount' => $newAmount,
                                    'due_date' => $newDueDate,
                                    'status' => $newStatus,
                                    'charge_id' => $chargeId,
                                ]);
                                admin_audit_log(
                                    (int)$_SESSION[ADMIN_SESSION_ID_KEY],
                                    'charge_updated',
                                    'charge',
                                    $chargeId,
                                    [
                                        'student_id' => (int)$charge['student_id'],
                                        'fee_type_id' => (int)$charge['fee_type_id'],
                                        'previous_amount' => (string)$charge['amount'],
                                        'new_amount' => $newAmount,
                                        'paid_amount' => decimal_from_cents($paidCents),
                                        'previous_due_date' => (string)$charge['due_date'],
                                        'due_date' => $newDueDate,
                                    ]
                                );
                                $pdo->commit();
                                header('Location: charges.php?status=updated');
                                exit;
                            }
                        } else {
                            $paymentsStatement = $pdo->prepare(
                                'SELECT id
                                 FROM payments
                                 WHERE student_charge_id = :charge_id
                                 LIMIT 1
                                 FOR UPDATE'
                            );
                            $paymentsStatement->execute(['charge_id' => $chargeId]);
                            if ($paymentsStatement->fetchColumn() !== false) {
                                $pdo->rollBack();
                                $errors[] = 'A charge with payments cannot be deleted.';
                            } else {
                                $deleteStatement = $pdo->prepare(
                                    'DELETE FROM student_charges WHERE id = :charge_id'
                                );
                                $deleteStatement->execute(['charge_id' => $chargeId]);
                                admin_audit_log(
                                    (int)$_SESSION[ADMIN_SESSION_ID_KEY],
                                    'charge_deleted',
                                    'charge',
                                    $chargeId,
                                    [
                                        'student_id' => (int)$charge['student_id'],
                                        'fee_type_id' => (int)$charge['fee_type_id'],
                                        'amount' => (string)$charge['amount'],
                                        'due_date' => (string)$charge['due_date'],
                                    ]
                                );
                                $pdo->commit();
                                header('Location: charges.php?status=deleted');
                                exit;
                            }
                        }
                    } catch (Throwable $exception) {
                        if ($pdo instanceof PDO && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('LCUPAY admin charge update failed.');
                        $errors[] = 'Unable to update the charge right now.';
                    }
                }
            }
        } else {
            $errors[] = 'Invalid action.';
        }
    }
}

$activeFeeTypes = [];
$activeStudents = [];
$charges = [];
$totalPages = 1;
$editCharge = null;
$loadFailed = false;
try {
    $feeTypesStatement = db()->prepare(
        'SELECT id, code, name, default_amount FROM fee_types WHERE is_active = 1 ORDER BY name, code'
    );
    $feeTypesStatement->execute();
    $activeFeeTypes = $feeTypesStatement->fetchAll();

    $studentsStatement = db()->prepare(
        'SELECT id, student_number, first_name, last_name
         FROM students
         WHERE is_active = 1
         ORDER BY last_name, first_name, student_number'
    );
    $studentsStatement->execute();
    $activeStudents = $studentsStatement->fetchAll();

    $countStatement = db()->prepare('SELECT COUNT(*) FROM student_charges');
    $countStatement->execute();
    $totalCharges = (int)$countStatement->fetchColumn();
    $pageSize = 25;
    $totalPages = max(1, (int)ceil($totalCharges / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;
    $chargeListStatement = db()->prepare(
        'SELECT sc.id, sc.student_id, sc.fee_type_id, sc.amount, sc.due_date, sc.status,
                s.student_number, s.first_name, s.last_name, ft.code AS fee_code, ft.name AS fee_name
         FROM student_charges sc
         INNER JOIN students s ON s.id = sc.student_id
         INNER JOIN fee_types ft ON ft.id = sc.fee_type_id
         ORDER BY sc.id DESC
         LIMIT :page_size OFFSET :page_offset'
    );
    $chargeListStatement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
    $chargeListStatement->bindValue(':page_offset', $offset, PDO::PARAM_INT);
    $chargeListStatement->execute();
    $charges = $chargeListStatement->fetchAll();

    if ($editChargeId !== null) {
        $editStatement = db()->prepare(
            'SELECT id, amount, due_date FROM student_charges WHERE id = :charge_id LIMIT 1'
        );
        $editStatement->execute(['charge_id' => $editChargeId]);
        $editCharge = $editStatement->fetch();
        if (!$editCharge) {
            http_response_code(404);
            exit('Charge not found.');
        }
    }
} catch (Throwable $exception) {
    error_log('LCUPAY admin charges page load failed.');
    $loadFailed = true;
    $errors[] = 'Unable to load fee types, students, or charges right now.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'edit') {
    $postedChargeId = admin_positive_int($_POST['id'] ?? null);
    if ($postedChargeId !== null && $editChargeId === null) {
        $editChargeId = $postedChargeId;
        $editCharge = [
            'id' => $editChargeId,
            'amount' => isset($_POST['amount']) && is_string($_POST['amount']) ? $_POST['amount'] : '',
            'due_date' => isset($_POST['due_date']) && is_string($_POST['due_date']) ? $_POST['due_date'] : '',
        ];
    }
}

$defaultFeeType = $activeFeeTypes[0] ?? null;
if ($selectedFeeTypeId !== null) {
    foreach ($activeFeeTypes as $feeType) {
        if ((int)$feeType['id'] === $selectedFeeTypeId) {
            $defaultFeeType = $feeType;
            break;
        }
    }
}
$amountInput = $amountInput !== '' && $amountInput !== "\0"
    ? $amountInput
    : ($defaultFeeType !== null ? (string)$defaultFeeType['default_amount'] : '');
$activePage = 'fee-types';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Charges | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <header class="admin-page-header">
                    <div>
                        <h1 class="admin-page-title">Fee types and charges</h1>
                        <p class="admin-page-subtitle">Assign charges and manage existing balances.</p>
                    </div>
                    <a class="admin-button admin-button-secondary" href="fee-types.php">Manage fee types</a>
                </header>
                <?php if ($notice !== ''): ?>
                    <p class="admin-success" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if ($createdCount !== null && $skippedCount !== null): ?>
                    <p class="admin-success" role="status">Created <?= htmlspecialchars((string)$createdCount, ENT_QUOTES, 'UTF-8') ?> charge(s); skipped <?= htmlspecialchars((string)$skippedCount, ENT_QUOTES, 'UTF-8') ?> student(s).</p>
                <?php endif; ?>
                <?php foreach ($errors as $error): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
                <section class="admin-panel admin-form-panel">
                    <h2 class="admin-panel-title">Assign a charge</h2>
                    <?php if ($loadFailed): ?>
                        <p>Fee types and students are unavailable right now.</p>
                    <?php elseif ($activeFeeTypes === []): ?>
                        <p>No active fee types are available. Add or activate a fee type first.</p>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="assign">
                            <div class="admin-form-grid">
                                <div class="admin-form-field">
                                    <label for="charge-recipient">Student</label>
                                    <select id="charge-recipient" name="recipient" required>
                                        <option value="all_active" <?= $recipient === 'all_active' ? 'selected' : '' ?>>All active students</option>
                                        <?php foreach ($activeStudents as $student): ?>
                                            <?php $studentOptionId = (string)$student['id']; ?>
                                            <option value="<?= htmlspecialchars($studentOptionId, ENT_QUOTES, 'UTF-8') ?>" <?= $recipient === $studentOptionId ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)$student['student_number'] . ' — ' . trim((string)$student['first_name'] . ' ' . (string)$student['last_name']), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="admin-form-field">
                                    <label for="fee-type">Fee type</label>
                                    <select id="fee-type" name="fee_type_id" required>
                                        <?php foreach ($activeFeeTypes as $feeType): ?>
                                            <?php $feeOptionId = (string)$feeType['id']; ?>
                                            <option value="<?= htmlspecialchars($feeOptionId, ENT_QUOTES, 'UTF-8') ?>" data-default-amount="<?= htmlspecialchars((string)$feeType['default_amount'], ENT_QUOTES, 'UTF-8') ?>" <?= (string)$selectedFeeTypeId === $feeOptionId ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)$feeType['name'] . ' (' . (string)$feeType['code'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="admin-form-field">
                                    <label for="charge-amount">Amount</label>
                                    <input id="charge-amount" name="amount" type="text" inputmode="decimal" value="<?= htmlspecialchars($amountInput, ENT_QUOTES, 'UTF-8') ?>" pattern="([0-9]{1,8}(\.[0-9]{1,2})?|\.[0-9]{1,2})" placeholder="Uses fee type default when blank">
                                    <small>Leave blank to use the selected fee type default.</small>
                                </div>
                                <div class="admin-form-field">
                                    <label for="charge-due-date">Due date</label>
                                    <input id="charge-due-date" name="due_date" type="date" value="<?= htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>
                            <div class="admin-form-actions">
                                <button class="admin-button" type="submit">Assign charge</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
                <?php if ($editCharge !== null): ?>
                    <section class="admin-panel admin-form-panel">
                        <h2 class="admin-panel-title">Edit charge #<?= htmlspecialchars((string)$editCharge['id'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= htmlspecialchars((string)$editCharge['id'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="admin-form-grid">
                                <div class="admin-form-field">
                                    <label for="edit-charge-amount">Amount</label>
                                    <input id="edit-charge-amount" name="amount" type="text" inputmode="decimal" value="<?= htmlspecialchars((string)$editCharge['amount'], ENT_QUOTES, 'UTF-8') ?>" pattern="([0-9]{1,8}(\.[0-9]{1,2})?|\.[0-9]{1,2})" required>
                                </div>
                                <div class="admin-form-field">
                                    <label for="edit-charge-due-date">Due date</label>
                                    <input id="edit-charge-due-date" name="due_date" type="date" value="<?= htmlspecialchars((string)$editCharge['due_date'], ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>
                            <div class="admin-form-actions">
                                <button class="admin-button" type="submit">Save charge</button>
                                <a class="admin-button admin-button-secondary" href="charges.php">Cancel</a>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
                <section class="admin-panel">
                    <h2 class="admin-panel-title">Charges</h2>
                    <?php if (!$loadFailed): ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Student</th><th>Fee type</th><th>Due date</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php if ($charges === []): ?>
                                        <tr><td colspan="6">No charges found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($charges as $charge): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$charge['student_number'] . ' — ' . trim((string)$charge['first_name'] . ' ' . (string)$charge['last_name']), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)$charge['fee_name'] . ' (' . (string)$charge['fee_code'] . ')', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)$charge['due_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars(peso((string)$charge['amount']), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars(ucfirst((string)$charge['status']), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="admin-action-cell">
                                                    <a class="admin-button admin-button-secondary" href="charges.php?edit_id=<?= htmlspecialchars((string)$charge['id'], ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= htmlspecialchars((string)$charge['id'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <button class="admin-button admin-button-danger" type="submit">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPages > 1): ?>
                            <nav class="admin-pagination" aria-label="Charge pages">
                                <span>Page <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars((string)$totalPages, ENT_QUOTES, 'UTF-8') ?></span>
                                <div>
                                    <?php if ($page > 1): ?>
                                        <a class="admin-button admin-button-secondary" href="charges.php?page=<?= htmlspecialchars((string)($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                                    <?php endif; ?>
                                    <?php if ($page < $totalPages): ?>
                                        <a class="admin-button admin-button-secondary" href="charges.php?page=<?= htmlspecialchars((string)($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                                    <?php endif; ?>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
    <script>
        const feeTypeSelect = document.getElementById('fee-type');
        const chargeAmountInput = document.getElementById('charge-amount');
        if (feeTypeSelect && chargeAmountInput) {
            feeTypeSelect.addEventListener('change', () => {
                chargeAmountInput.value = feeTypeSelect.selectedOptions[0].dataset.defaultAmount;
            });
        }
    </script>
</body>
</html>
