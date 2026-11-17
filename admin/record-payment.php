<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';

function create_admin_payment_form_token(): string {
    $tokens = $_SESSION['admin_payment_form_tokens'] ?? [];
    if (!is_array($tokens)) {
        $tokens = [];
    }
    $now = time();
    foreach ($tokens as $token => $createdAt) {
        if (!is_int($createdAt) || $now - $createdAt >= ADMIN_IDLE_TIMEOUT) {
            unset($tokens[$token]);
        }
    }
    if (count($tokens) >= 10) {
        asort($tokens);
        $oldestToken = array_key_first($tokens);
        if ($oldestToken !== null) {
            unset($tokens[$oldestToken]);
        }
    }

    $token = bin2hex(random_bytes(32));
    $tokens[$token] = $now;
    $_SESSION['admin_payment_form_tokens'] = $tokens;
    return $token;
}

function consume_admin_payment_form_token(mixed $token): bool {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return false;
    }
    $tokens = $_SESSION['admin_payment_form_tokens'] ?? [];
    if (!is_array($tokens) || !array_key_exists($token, $tokens)) {
        return false;
    }

    $createdAt = $tokens[$token];
    unset($tokens[$token]);
    $_SESSION['admin_payment_form_tokens'] = $tokens;
    return is_int($createdAt) && time() >= $createdAt && time() - $createdAt < ADMIN_IDLE_TIMEOUT;
}

$errors = [];
$chargeId = null;
$amountInput = '';
$charge = null;
$remainingCents = null;
$openCharges = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chargeId = admin_positive_int($_POST['charge_id'] ?? null);
    $oneTimeTokenValid = consume_admin_payment_form_token($_POST['payment_form_token'] ?? null);
    $csrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = admin_csrf_verify(is_string($csrfToken) ? $csrfToken : null);
    $amountInput = isset($_POST['amount']) && is_string($_POST['amount']) ? trim($_POST['amount']) : '';

    if ($chargeId === null) {
        http_response_code(400);
        exit('Invalid request.');
    }
    if (!$csrfValid) {
        $errors[] = 'Your request could not be verified. Reload the page and try again.';
    } elseif (!$oneTimeTokenValid) {
        $errors[] = 'This payment form was already submitted or has expired. Review the balance and try again.';
    } else {
        $amountCents = admin_amount_to_cents($amountInput);
        if ($amountCents === null || $amountCents < 100) {
            $errors[] = 'Enter a payment amount of at least ₱ 1.00.';
        } else {
            try {
                $payment = record_admin_cash_payment(
                    $chargeId,
                    $amountCents,
                    (int)$_SESSION[ADMIN_SESSION_ID_KEY]
                );
                $_SESSION['admin_payment_success'] = [
                    'reference_number' => $payment['reference_number'],
                    'amount' => $payment['amount'],
                ];
                header('Location: payment-success.php');
                exit;
            } catch (Throwable $exception) {
                error_log('LCUPAY admin cash payment failed.');
                $errors[] = 'Unable to record this payment. Verify the remaining balance and try again.';
            }
        }
    }
} elseif (array_key_exists('charge_id', $_GET)) {
    $chargeId = admin_positive_int($_GET['charge_id']);
    if ($chargeId === null) {
        http_response_code(400);
        exit('Invalid request.');
    }
}

if ($chargeId !== null) {
    try {
        $chargeStatement = db()->prepare(
            'SELECT sc.id, sc.student_id, sc.fee_type_id, sc.amount, sc.due_date, sc.status,
                    s.student_number, s.first_name, s.last_name, ft.code AS fee_code, ft.name AS fee_name
             FROM student_charges sc
             INNER JOIN students s ON s.id = sc.student_id
             INNER JOIN fee_types ft ON ft.id = sc.fee_type_id
             WHERE sc.id = :charge_id
             LIMIT 1'
        );
        $chargeStatement->execute(['charge_id' => $chargeId]);
        $charge = $chargeStatement->fetch();
        if (!$charge) {
            http_response_code(404);
            exit('Charge not found.');
        }

        $paidStatement = db()->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE student_charge_id = :charge_id AND status = \'paid\''
        );
        $paidStatement->execute(['charge_id' => $chargeId]);
        $chargeCents = amount_to_cents((string)$charge['amount']);
        $paidCents = amount_to_cents((string)$paidStatement->fetchColumn());
        if ($chargeCents === null || $paidCents === null) {
            throw new RuntimeException('Charge balance could not be calculated.');
        }
        $remainingCents = max(0, $chargeCents - $paidCents);
    } catch (Throwable $exception) {
        error_log('LCUPAY admin cash payment form load failed.');
        http_response_code(500);
        exit('Unable to load the charge right now.');
    }
} else {
    try {
        $openChargesStatement = db()->prepare(
            'SELECT sc.id, sc.amount, s.student_number, s.first_name, s.last_name,
                    ft.name AS fee_name, ft.code AS fee_code,
                    GREATEST(sc.amount - COALESCE(p.paid_amount, 0), 0) AS remaining_balance
             FROM student_charges sc
             INNER JOIN students s ON s.id = sc.student_id
             INNER JOIN fee_types ft ON ft.id = sc.fee_type_id
             LEFT JOIN (
                 SELECT student_charge_id, SUM(amount) AS paid_amount
                 FROM payments
                 WHERE status = \'paid\'
                 GROUP BY student_charge_id
             ) p ON p.student_charge_id = sc.id
             WHERE GREATEST(sc.amount - COALESCE(p.paid_amount, 0), 0) >= 1.00
             ORDER BY s.last_name, s.first_name, sc.due_date, sc.id'
        );
        $openChargesStatement->execute();
        $openCharges = $openChargesStatement->fetchAll();
    } catch (Throwable $exception) {
        error_log('LCUPAY admin open charges lookup failed.');
        http_response_code(500);
        exit('Unable to load charges right now.');
    }
}

$paymentFormToken = $charge !== null ? create_admin_payment_form_token() : '';
$activePage = 'payments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record cash payment | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <header class="admin-page-header">
                    <div>
                        <h1 class="admin-page-title">Record cash payment</h1>
                        <p class="admin-page-subtitle">Select an outstanding charge, then confirm the amount received.</p>
                    </div>
                    <a class="admin-button admin-button-secondary" href="payments.php">Back to payments</a>
                </header>
                <?php foreach ($errors as $error): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
                <?php if ($charge === null): ?>
                    <section class="admin-panel">
                        <h2 class="admin-panel-title">Outstanding charges</h2>
                        <?php if ($openCharges === []): ?>
                            <p>No charges have a remaining balance of at least ₱ 1.00.</p>
                        <?php else: ?>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Student</th><th>Fee</th><th>Remaining balance</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($openCharges as $openCharge): ?>
                                            <tr>
                                                <td><?= htmlspecialchars(trim((string)$openCharge['first_name'] . ' ' . (string)$openCharge['last_name']) . ' (' . (string)$openCharge['student_number'] . ')', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)$openCharge['fee_name'] . ' (' . (string)$openCharge['fee_code'] . ')', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars(peso((string)$openCharge['remaining_balance']), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><a class="admin-button admin-button-secondary" href="record-payment.php?charge_id=<?= htmlspecialchars((string)$openCharge['id'], ENT_QUOTES, 'UTF-8') ?>">Select</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <section class="admin-panel admin-payment-confirm">
                        <h2 class="admin-panel-title">Charge details</h2>
                        <dl>
                            <div><dt>Student</dt><dd><?= htmlspecialchars(trim((string)$charge['first_name'] . ' ' . (string)$charge['last_name']), ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>Student number</dt><dd><?= htmlspecialchars((string)$charge['student_number'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>Fee</dt><dd><?= htmlspecialchars((string)$charge['fee_name'] . ' (' . (string)$charge['fee_code'] . ')', ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>Remaining balance</dt><dd><?= htmlspecialchars(peso(decimal_from_cents((int)$remainingCents)), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        </dl>
                        <?php if ($remainingCents < 100): ?>
                            <p>This charge has no remaining amount of at least ₱ 1.00 to collect.</p>
                        <?php else: ?>
                            <form method="post" class="admin-record-payment-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="payment_form_token" value="<?= htmlspecialchars($paymentFormToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="charge_id" value="<?= htmlspecialchars((string)$chargeId, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="admin-form-field">
                                    <label for="cash-amount">Cash amount received</label>
                                    <input id="cash-amount" name="amount" type="text" inputmode="decimal" pattern="([0-9]{1,8}(\.[0-9]{1,2})?|\.[0-9]{1,2})" value="<?= htmlspecialchars($amountInput, ENT_QUOTES, 'UTF-8') ?>" required>
                                    <small>Enter at least ₱ 1.00 and no more than the remaining balance.</small>
                                </div>
                                <button class="admin-button" type="submit">Record payment</button>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
