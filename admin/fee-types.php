<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];
$feeTypeId = null;
$formAction = 'add';
$code = '';
$name = '';
$amountInput = '';
$statusMessages = [
    'saved' => 'Fee type saved.',
    'activated' => 'Fee type activated.',
    'deactivated' => 'Fee type deactivated.',
];
$notice = '';
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $notice = $statusMessages[$status];
}

if (array_key_exists('id', $_GET)) {
    $feeTypeId = admin_positive_int($_GET['id']);
    if ($feeTypeId === null) {
        http_response_code(400);
        exit('Invalid request.');
    }
    $formAction = 'edit';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!admin_csrf_verify(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your request could not be verified. Reload the page and try again.';
    } else {
        $actionValue = $_POST['action'] ?? '';
        $action = is_string($actionValue) ? $actionValue : '';
        if ($action === 'add' || $action === 'edit') {
            $code = isset($_POST['code']) && is_string($_POST['code']) ? trim($_POST['code']) : '';
            $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
            $amountInput = isset($_POST['default_amount']) && is_string($_POST['default_amount'])
                ? trim($_POST['default_amount'])
                : '';
            $formAction = $action;
            if ($action === 'edit') {
                $feeTypeId = admin_positive_int($_POST['id'] ?? null);
            }

            $amountCents = admin_amount_to_cents($amountInput);
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $code)) {
                $errors[] = 'Code must contain only letters, numbers, or underscores, up to 64 characters.';
            }
            if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
                $errors[] = 'Enter a name of up to 150 characters.';
            }
            if ($amountCents === null) {
                $errors[] = 'Enter an amount greater than zero with no more than two decimal places.';
            }
            if ($action === 'edit' && $feeTypeId === null) {
                $errors[] = 'Invalid fee type.';
            }

            if ($errors === []) {
                $pdo = null;
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    if ($action === 'add') {
                        $duplicateStatement = $pdo->prepare(
                            'SELECT id FROM fee_types WHERE code = :code LIMIT 1 FOR UPDATE'
                        );
                        $duplicateStatement->execute(['code' => $code]);
                        if ($duplicateStatement->fetchColumn() !== false) {
                            $pdo->rollBack();
                            $errors[] = 'Unable to save fee type. Check the code and details.';
                        } else {
                            $insertStatement = $pdo->prepare(
                                'INSERT INTO fee_types (code, name, default_amount)
                                 VALUES (:code, :name, :default_amount)'
                            );
                            $amount = decimal_from_cents($amountCents);
                            $insertStatement->execute([
                                'code' => $code,
                                'name' => $name,
                                'default_amount' => $amount,
                            ]);
                            $feeTypeId = (int)$pdo->lastInsertId();
                            admin_audit_log(
                                (int)$_SESSION[ADMIN_SESSION_ID_KEY],
                                'fee_type_added',
                                'fee_type',
                                $feeTypeId,
                                ['default_amount' => $amount]
                            );
                            $pdo->commit();
                            header('Location: fee-types.php?status=saved');
                            exit;
                        }
                    } else {
                        $feeTypeStatement = $pdo->prepare(
                            'SELECT default_amount
                             FROM fee_types
                             WHERE id = :fee_type_id
                             FOR UPDATE'
                        );
                        $feeTypeStatement->execute(['fee_type_id' => $feeTypeId]);
                        $existingFeeType = $feeTypeStatement->fetch();
                        if (!$existingFeeType) {
                            $pdo->rollBack();
                            $errors[] = 'Fee type not found.';
                        } else {
                            $duplicateStatement = $pdo->prepare(
                                'SELECT id
                                 FROM fee_types
                                 WHERE code = :code AND id <> :fee_type_id
                                 LIMIT 1
                                 FOR UPDATE'
                            );
                            $duplicateStatement->execute([
                                'code' => $code,
                                'fee_type_id' => $feeTypeId,
                            ]);
                            if ($duplicateStatement->fetchColumn() !== false) {
                                $pdo->rollBack();
                                $errors[] = 'Unable to save fee type. Check the code and details.';
                            } else {
                                $updateStatement = $pdo->prepare(
                                    'UPDATE fee_types
                                     SET code = :code, name = :name, default_amount = :default_amount
                                     WHERE id = :fee_type_id'
                                );
                                $amount = decimal_from_cents($amountCents);
                                $updateStatement->execute([
                                    'code' => $code,
                                    'name' => $name,
                                    'default_amount' => $amount,
                                    'fee_type_id' => $feeTypeId,
                                ]);
                                $previousAmount = (string)$existingFeeType['default_amount'];
                                admin_audit_log(
                                    (int)$_SESSION[ADMIN_SESSION_ID_KEY],
                                    'fee_type_updated',
                                    'fee_type',
                                    $feeTypeId,
                                    [
                                        'previous_default_amount' => $previousAmount,
                                        'default_amount' => $amount,
                                    ]
                                );
                                $pdo->commit();
                                header('Location: fee-types.php?status=saved');
                                exit;
                            }
                        }
                    }
                } catch (Throwable $exception) {
                    if ($pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('LCUPAY admin fee type save failed.');
                    $errors[] = 'Unable to save fee type. Check the code and details.';
                }
            }
        } elseif ($action === 'activate' || $action === 'deactivate') {
            $feeTypeId = admin_positive_int($_POST['id'] ?? null);
            if ($feeTypeId === null) {
                $errors[] = 'Invalid fee type.';
            } else {
                $pdo = null;
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    $statement = $pdo->prepare(
                        'SELECT default_amount FROM fee_types WHERE id = :fee_type_id FOR UPDATE'
                    );
                    $statement->execute(['fee_type_id' => $feeTypeId]);
                    $feeType = $statement->fetch();
                    if (!$feeType) {
                        $pdo->rollBack();
                        $errors[] = 'Fee type not found.';
                    } else {
                        $isActive = $action === 'activate' ? 1 : 0;
                        $updateStatement = $pdo->prepare(
                            'UPDATE fee_types SET is_active = :is_active WHERE id = :fee_type_id'
                        );
                        $updateStatement->execute([
                            'is_active' => $isActive,
                            'fee_type_id' => $feeTypeId,
                        ]);
                        $auditAction = $isActive === 1 ? 'fee_type_activated' : 'fee_type_deactivated';
                        admin_audit_log(
                            (int)$_SESSION[ADMIN_SESSION_ID_KEY],
                            $auditAction,
                            'fee_type',
                            $feeTypeId,
                            ['default_amount' => (string)$feeType['default_amount']]
                        );
                        $pdo->commit();
                        $redirectStatus = $isActive === 1 ? 'activated' : 'deactivated';
                        header('Location: fee-types.php?status=' . $redirectStatus);
                        exit;
                    }
                } catch (Throwable $exception) {
                    if ($pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('LCUPAY admin fee type status update failed.');
                    $errors[] = 'Unable to update the fee type right now.';
                }
            }
        } else {
            $errors[] = 'Invalid action.';
        }
    }
}

$feeTypes = [];
$listFailed = false;
try {
    $listStatement = db()->prepare(
        'SELECT id, code, name, default_amount, is_active
         FROM fee_types
         ORDER BY name, code'
    );
    $listStatement->execute();
    $feeTypes = $listStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('LCUPAY admin fee type list failed.');
    $listFailed = true;
    $errors[] = 'Unable to load fee types right now.';
}

$editingFeeType = null;
if ($formAction === 'edit' && $feeTypeId !== null) {
    foreach ($feeTypes as $feeType) {
        if ((int)$feeType['id'] === $feeTypeId) {
            $editingFeeType = $feeType;
            break;
        }
    }
    if ($editingFeeType === null && $errors === []) {
        http_response_code(404);
        exit('Fee type not found.');
    }
    if ($editingFeeType !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $code = (string)$editingFeeType['code'];
        $name = (string)$editingFeeType['name'];
        $amountInput = (string)$editingFeeType['default_amount'];
    }
}

$activePage = 'fee-types';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee types | LCUPAY Registrar</title>
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
                        <p class="admin-page-subtitle">Maintain fee types, then assign charges to students.</p>
                    </div>
                    <a class="admin-button admin-button-secondary" href="charges.php">Manage charges</a>
                </header>
                <?php if ($notice !== ''): ?>
                    <p class="admin-success" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php foreach ($errors as $error): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
                <section class="admin-panel admin-form-panel">
                    <h2 class="admin-panel-title"><?= $formAction === 'edit' ? 'Edit fee type' : 'Add fee type' ?></h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($formAction === 'edit' && $feeTypeId !== null): ?>
                            <input type="hidden" name="id" value="<?= htmlspecialchars((string)$feeTypeId, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <div class="admin-form-grid">
                            <div class="admin-form-field">
                                <label for="fee-code">Code</label>
                                <input id="fee-code" name="code" type="text" value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" pattern="[A-Za-z0-9_]+" maxlength="64" required>
                            </div>
                            <div class="admin-form-field">
                                <label for="fee-name">Name</label>
                                <input id="fee-name" name="name" type="text" value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" maxlength="150" required>
                            </div>
                            <div class="admin-form-field">
                                <label for="default-amount">Default amount</label>
                                <input id="default-amount" name="default_amount" type="text" inputmode="decimal" value="<?= htmlspecialchars($amountInput, ENT_QUOTES, 'UTF-8') ?>" pattern="([0-9]{1,8}(\.[0-9]{1,2})?|\.[0-9]{1,2})" required>
                            </div>
                        </div>
                        <div class="admin-form-actions">
                            <button class="admin-button" type="submit"><?= $formAction === 'edit' ? 'Save changes' : 'Add fee type' ?></button>
                            <?php if ($formAction === 'edit'): ?>
                                <a class="admin-button admin-button-secondary" href="fee-types.php">Cancel edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>
                <section class="admin-panel">
                    <h2 class="admin-panel-title">Fee types</h2>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Code</th><th>Name</th><th>Default amount</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php if ($listFailed): ?>
                                    <tr><td colspan="5">Fee type results are unavailable.</td></tr>
                                <?php elseif ($feeTypes === []): ?>
                                    <tr><td colspan="5">No fee types found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($feeTypes as $feeType): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$feeType['code'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$feeType['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(peso((string)$feeType['default_amount']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="admin-status <?= (int)$feeType['is_active'] === 1 ? 'admin-status-active' : 'admin-status-inactive' ?>"><?= (int)$feeType['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                                            <td class="admin-action-cell">
                                                <a class="admin-button admin-button-secondary" href="fee-types.php?id=<?= htmlspecialchars((string)$feeType['id'], ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$feeType['id'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="<?= (int)$feeType['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                                                    <button class="admin-button <?= (int)$feeType['is_active'] === 1 ? 'admin-button-danger' : 'admin-button-secondary' ?>" type="submit"><?= (int)$feeType['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                                </form>
                                            </td>
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
