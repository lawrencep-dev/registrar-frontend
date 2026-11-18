<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_auth.php';

function record_admin_cash_payment(int $chargeId, int $amountCents, int $adminId): array {
    if ($chargeId < 1 || $adminId < 1 || $amountCents < 100) {
        throw new InvalidArgumentException('Invalid cash payment details.');
    }

    $connection = null;
    try {
        $connection = db();
        $connection->beginTransaction();

        $chargeStatement = $connection->prepare(
            'SELECT id, amount
             FROM student_charges
             WHERE id = :charge_id
             FOR UPDATE'
        );
        $chargeStatement->execute(['charge_id' => $chargeId]);
        $charge = $chargeStatement->fetch();
        if (!$charge) {
            throw new RuntimeException('Charge not found.');
        }

        $paidStatement = $connection->prepare(
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
        if ($amountCents > $remainingCents) {
            throw new RuntimeException('Payment exceeds the remaining balance.');
        }

        $paymentStatement = $connection->prepare(
            'INSERT INTO payments
                (student_charge_id, reference_number, amount, convenience_fee, payment_method, status, paid_at)
             VALUES
                (:charge_id, :reference_number, :amount, \'0.00\', \'Cash (cashier)\', \'paid\', NOW())'
        );
        $referenceNumber = '';
        $paymentStored = false;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $referenceNumber = 'TXN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            try {
                $paymentStatement->execute([
                    'charge_id' => $chargeId,
                    'reference_number' => $referenceNumber,
                    'amount' => decimal_from_cents($amountCents),
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

        $paymentId = (int)$connection->lastInsertId();
        $newStatus = $amountCents === $remainingCents ? 'paid' : 'partial';
        $updateCharge = $connection->prepare(
            'UPDATE student_charges SET status = :status WHERE id = :charge_id'
        );
        $updateCharge->execute([
            'status' => $newStatus,
            'charge_id' => $chargeId,
        ]);

        $amount = decimal_from_cents($amountCents);
        admin_audit_log(
            $adminId,
            'cash_payment_recorded',
            'payment',
            $paymentId,
            [
                'charge_id' => $chargeId,
                'amount' => $amount,
            ]
        );

        $connection->commit();
        return [
            'payment_id' => $paymentId,
            'charge_id' => $chargeId,
            'reference_number' => $referenceNumber,
            'amount' => $amount,
        ];
    } catch (Throwable $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}
