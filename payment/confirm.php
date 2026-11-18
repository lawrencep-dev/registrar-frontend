<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log('LCUPAY payment confirmation rejected a non-POST request.');
    $_SESSION['payment_error'] = '1';
    header('Location: review.php', true, 303);
    exit;
}

$paymentMethod = $_SESSION['payment_method'] ?? null;
if (!is_string($paymentMethod) || !in_array($paymentMethod, ['GCash (demo)', 'Bank transfer (demo)'], true)) {
    header('Location: review.php', true, 303);
    exit;
}

$connection = null;

try {
    if (!csrf_verify(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
        throw new RuntimeException('Payment CSRF validation failed.');
    }
    if (($_POST['confirm'] ?? '') !== '1') {
        throw new RuntimeException('Payment confirmation checkbox was not selected.');
    }

    $draft = $_SESSION['payment_draft'] ?? null;
    if (!is_array($draft) || !isset($draft['charge_id'], $draft['amount_cents']) || !is_int($draft['charge_id']) || !is_int($draft['amount_cents'])) {
        throw new RuntimeException('Payment selection missing or invalid.');
    }

    $studentId = (int)($_SESSION['student_id'] ?? 0);
    $config = require __DIR__ . '/../includes/config.php';
    $convenienceFeeCents = amount_to_cents((string)($config['convenience_fee'] ?? ''));
    if ($studentId < 1 || $draft['amount_cents'] < 100 || $convenienceFeeCents === null) {
        throw new RuntimeException('Payment amount or configuration is invalid.');
    }

    $connection = db();
    $connection->beginTransaction();

    $chargeStatement = $connection->prepare(
        'SELECT amount
         FROM student_charges
         WHERE id = :charge_id AND student_id = :student_id
         FOR UPDATE'
    );
    $chargeStatement->execute([
        'charge_id' => $draft['charge_id'],
        'student_id' => $studentId,
    ]);
    $charge = $chargeStatement->fetch();
    if (!$charge) {
        throw new RuntimeException('Payment charge not found for student.');
    }

    $paidStatement = $connection->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE student_charge_id = :charge_id AND status = \'paid\''
    );
    $paidStatement->execute(['charge_id' => $draft['charge_id']]);
    $chargeCents = amount_to_cents((string)$charge['amount']);
    $paidCents = amount_to_cents((string)$paidStatement->fetchColumn());
    if ($chargeCents === null || $paidCents === null) {
        throw new RuntimeException('Charge balance could not be calculated.');
    }

    $remainingCents = max(0, $chargeCents - $paidCents);
    if ($draft['amount_cents'] < 100 || $draft['amount_cents'] > $remainingCents) {
        throw new RuntimeException('Payment amount exceeds the remaining charge balance.');
    }

    $newStatus = $draft['amount_cents'] === $remainingCents ? 'paid' : 'partial';
    $insertPayment = $connection->prepare(
        'INSERT INTO payments
            (student_charge_id, reference_number, amount, convenience_fee, payment_method, status, paid_at)
         VALUES
            (:student_charge_id, :reference_number, :amount, :convenience_fee, :payment_method, \'paid\', NOW())'
    );
    $reference = '';
    $paymentStored = false;

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $reference = 'TXN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $insertPayment->execute([
                'student_charge_id' => $draft['charge_id'],
                'reference_number' => $reference,
                'amount' => decimal_from_cents($draft['amount_cents']),
                'convenience_fee' => decimal_from_cents($convenienceFeeCents),
                'payment_method' => $paymentMethod,
            ]);
            $paymentStored = true;
            break;
        } catch (PDOException $exception) {
            $mysqlError = (int)($exception->errorInfo[1] ?? 0);
            if ($mysqlError !== 1062 || $attempt === 5) {
                throw $exception;
            }
        }
    }

    if (!$paymentStored) {
        throw new RuntimeException('Payment reference generation failed.');
    }

    $updateCharge = $connection->prepare(
        'UPDATE student_charges
         SET status = :status
         WHERE id = :charge_id AND student_id = :student_id'
    );
    $updateCharge->execute([
        'status' => $newStatus,
        'charge_id' => $draft['charge_id'],
        'student_id' => $studentId,
    ]);

    $connection->commit();
    unset($_SESSION['payment_draft'], $_SESSION['payment_method'], $_SESSION['payment_error']);
    header('Location: success.php?ref=' . rawurlencode($reference), true, 303);
    exit;
} catch (Throwable $exception) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('LCUPAY payment confirmation failed: ' . $exception->getMessage());
    $_SESSION['payment_error'] = '1';
    header('Location: review.php', true, 303);
    exit;
}