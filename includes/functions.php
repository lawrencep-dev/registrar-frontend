<?php
require_once __DIR__ . '/db.php';

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float|int|string $amount): string {
    return '₱ ' . number_format((float)$amount, 2);
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

function remaining_balance(int $chargeId): float {
    $student = current_student();
    if (!$student) {
        return 0.0;
    }

    $statement = db()->prepare(
        'SELECT sc.amount - COALESCE(SUM(p.amount), 0) AS remaining_balance
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
    return $balance === false ? 0.0 : max(0.0, (float)$balance);
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
            COALESCE(SUM(sc.amount - COALESCE(p.paid_amount, 0)), 0) AS balance,
            COUNT(sc.id) AS charge_count,
            MIN(CASE WHEN sc.status IN (\'unpaid\', \'partial\') THEN sc.due_date END) AS next_due_date
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