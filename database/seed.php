<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$students = [
    [
        'student_number' => '2026-001284',
        'email' => 'lei.clemente@example.com',
        'first_name' => 'Lei Justin',
        'last_name' => 'Clemente',
    ],
    
    [
        'student_number' => '2026-001286',
        'email' => 'catu_ryan@gmail.com',
        'first_name' => 'Ryan',
        'last_name' => 'Catu',
    ],
    [
        'student_number' => '2026-001287',
        'email' => 'arpista_michael@gmail.com',
        'first_name' => 'Michael',
        'last_name' => 'Arpista',
    ],

];

$feeTypes = [
    ['tuition', 'Tuition fee', '15000.00'],
    ['laboratory', 'Laboratory fee', '2000.00'],
    ['miscellaneous', 'Miscellaneous fee', '1500.00'],
];

try {
    $connection = db();
    $connection->beginTransaction();

    $insertFee = $connection->prepare(
        'INSERT INTO fee_types (code, name, default_amount, is_active)
         VALUES (:code, :name, :amount, 1)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $feeIds = [];
    foreach ($feeTypes as [$code, $name, $amount]) {
        $insertFee->execute([
            'code' => $code,
            'name' => $name,
            'amount' => $amount,
        ]);
        $feeIds[$code] = (int)$connection->lastInsertId();
    }

    $passwordHash = password_hash('Test1234!', PASSWORD_DEFAULT);
    $insertStudent = $connection->prepare(
        'INSERT INTO students
            (student_number, email, password_hash, first_name, last_name, is_active)
         VALUES (:student_number, :email, :password_hash, :first_name, :last_name, 1)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $studentIds = [];
    foreach ($students as $student) {
        $insertStudent->execute([
            'student_number' => $student['student_number'],
            'email' => $student['email'],
            'password_hash' => $passwordHash,
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
        ]);
        $studentIds[] = (int)$connection->lastInsertId();
    }

    $insertCharge = $connection->prepare(
        'INSERT INTO student_charges
            (student_id, fee_type_id, amount, due_date, status)
         VALUES (:student_id, :fee_type_id, :amount, :due_date, \'unpaid\')
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    foreach ($studentIds as $studentId) {
        foreach ($feeTypes as [$code, $name, $amount]) {
            $insertCharge->execute([
                'student_id' => $studentId,
                'fee_type_id' => $feeIds[$code],
                'amount' => $amount,
                'due_date' => '2026-11-30',
            ]);
        }
    }

    $connection->commit();

    fwrite(STDOUT, "Demo students seeded:\n");
    foreach ($students as $student) {
        fwrite(STDOUT, $student['student_number'] . ' ' . $student['email'] . "\n");
    }
} catch (Throwable $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('LCUPAY seed failed: ' . $exception->getMessage());
    fwrite(STDERR, "Seed failed. Check the PHP error log and database configuration.\n");
    exit(1);
}