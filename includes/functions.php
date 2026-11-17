<?php
require_once __DIR__ . '/db.php';

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float|int|string $amount): string {
    return '₱ ' . number_format((float)$amount, 2);
}

function amount_to_cents(string|int $amount): ?int {
    $value = trim(str_replace(['₱', ',', ' '], '', (string)$amount));
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $value, $matches)) {
        return null;
    }

    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 8) {
        return null;
    }

    $fraction = str_pad($matches[2] ?? '', 2, '0');
    return ((int)$whole * 100) + (int)$fraction;
}

function decimal_from_cents(int $cents): string {
    $whole = intdiv($cents, 100);
    $fraction = $cents % 100;
    return $whole . '.' . str_pad((string)$fraction, 2, '0', STR_PAD_LEFT);
}

function current_student(): ?array {
    static $student;
    static $loaded = false;

    if ($loaded) {
        return $student;
    }
    $loaded = true;

    $studentId = (int)($_SESSION['student_id'] ?? 0);
    if ($studentId < 1) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, student_number, email, first_name, last_name
         FROM students
         WHERE id = :student_id AND is_active = 1
         LIMIT 1'
    );
    $statement->execute(['student_id' => $studentId]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }

    $firstName = (string)$row['first_name'];
    $lastName = (string)$row['last_name'];
    $initials = mb_strtoupper(mb_substr($firstName, 0, 1, 'UTF-8') . mb_substr($lastName, 0, 1, 'UTF-8'), 'UTF-8');

    $student = [
        'id' => (int)$row['id'],
        'name' => trim($firstName . ' ' . $lastName),
        'initials' => $initials,
        'student_number' => (string)$row['student_number'],
        'email' => (string)$row['email'],
    ];
    return $student;
}

function remaining_balance(int $chargeId): string {
    $student = current_student();
    if (!$student) {
        return '0.00';
    }

    $statement = db()->prepare(
        'SELECT GREATEST(sc.amount - COALESCE(SUM(p.amount), 0), 0) AS remaining_balance
         FROM student_charges sc
         LEFT JOIN payments p
           ON p.student_charge_id = sc.id AND p.status = \'paid\'
         WHERE sc.id = :charge_id AND sc.student_id = :student_id
         GROUP BY sc.id, sc.amount'
    );
    $statement->execute([
        'charge_id' => $chargeId,
        'student_id' => $student['id'],
    ]);
    $balance = $statement->fetchColumn();
    return $balance === false ? '0.00' : (string)$balance;
}

function student_balance_summary(int $studentId): array {
    $student = current_student();
    if (!$student || $student['id'] !== $studentId) {
        return [
            'total_charges' => 0.0,
            'total_paid' => 0.0,
            'balance' => 0.0,
            'charge_count' => 0,
            'next_due_date' => null,
        ];
    }

    $statement = db()->prepare(
        'SELECT
            COALESCE(SUM(sc.amount), 0) AS total_charges,
            COALESCE(SUM(COALESCE(p.paid_amount, 0)), 0) AS total_paid,
                COALESCE(SUM(GREATEST(sc.amount - COALESCE(p.paid_amount, 0), 0)), 0) AS balance,
            COUNT(sc.id) AS charge_count,
                MIN(CASE WHEN sc.amount > COALESCE(p.paid_amount, 0) THEN sc.due_date END) AS next_due_date
         FROM student_charges sc
         LEFT JOIN (
            SELECT student_charge_id, SUM(amount) AS paid_amount
            FROM payments
            WHERE status = \'paid\'
            GROUP BY student_charge_id
         ) p ON p.student_charge_id = sc.id
         WHERE sc.student_id = :student_id AND sc.student_id = :session_student_id'
    );
    $statement->execute([
        'student_id' => $studentId,
        'session_student_id' => $student['id'],
    ]);
    $summary = $statement->fetch();

    return [
        'total_charges' => (float)$summary['total_charges'],
        'total_paid' => (float)$summary['total_paid'],
        'balance' => (float)$summary['balance'],
        'charge_count' => (int)$summary['charge_count'],
        'next_due_date' => $summary['next_due_date'],
    ];
}

function charge_status_label(string $status): string {
    return match ($status) {
        'unpaid' => 'Unpaid',
        'partial' => 'Partially paid',
        'paid' => 'Paid',
        default => 'Unpaid',
    };
}

function peso($amount)
{
    $cents = amount_to_cents((string)$amount);
    if ($cents === null) {
        return '₱ 0.00';
    }

    return '₱ ' . number_format(intdiv($cents, 100)) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}