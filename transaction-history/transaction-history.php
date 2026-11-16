<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$activePage = 'transaction-history';
$student = current_student();
$paymentStatement = db()->prepare(
    'SELECT p.reference_number, p.amount, p.status, p.created_at, ft.name AS fee_name
     FROM payments p
     JOIN student_charges sc ON sc.id = p.student_charge_id
     JOIN fee_types ft ON ft.id = sc.fee_type_id
     WHERE sc.student_id = :student_id
     ORDER BY p.created_at DESC, p.id DESC'
);
$paymentStatement->execute(['student_id' => (int)($student['id'] ?? 0)]);
$payments = $paymentStatement->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Transaction History</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" />
    <link rel="stylesheet" href="../home/home.css">
    <link rel="stylesheet" href="transaction-history.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="dashboard dash-dashboard">
        <div class="dash-content">
            <header class="dash-header">
                <div>
                    <h1 class="dash-title">Transaction History</h1>
                    <p class="dash-subtitle">Review your recent payment activity.</p>
                </div>
                <div class="dash-user-card">
                    <span class="dash-avatar"><?= e($student['initials'] ?? '') ?></span>
                    <span class="dash-user-details">
                        <strong><?= e($student['name'] ?? '') ?></strong>
                        <span>Student account</span>
                    </span>
                </div>
            </header>

            <section class="dash-panel history-panel" aria-label="Transactions">
                <h2 class="dash-panel-title">Transactions</h2>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr><th scope="col">Date</th><th scope="col">Reference</th><th scope="col">Description</th><th scope="col">Amount</th><th scope="col">Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($payments === []): ?>
                                <tr><td colspan="5">No payments yet. Your payments will appear here.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= e(date('M d, Y', strtotime((string)$payment['created_at']))) ?></td>
                                        <td><?= e((string)$payment['reference_number']) ?></td>
                                        <td><?= e((string)$payment['fee_name']) ?></td>
                                        <td><?= e(money((float)$payment['amount'])) ?></td>
                                        <td><?= e(ucfirst((string)$payment['status'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>