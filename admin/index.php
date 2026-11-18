<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$activePage = 'dashboard';
$dashboardError = '';
$stats = null;
$recentPayments = [];

try {
    $statsStatement = db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM students WHERE is_active = 1) AS active_students,
            (
                SELECT COALESCE(SUM(GREATEST(sc.amount - COALESCE(p.paid_amount, 0), 0)), 0)
                FROM student_charges sc
                LEFT JOIN (
                    SELECT student_charge_id, SUM(amount) AS paid_amount
                    FROM payments
                    WHERE status = \'paid\'
                    GROUP BY student_charge_id
                ) p ON p.student_charge_id = sc.id
            ) AS outstanding_balance,
            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'paid\') AS total_collected,
            (
                SELECT COUNT(*)
                FROM payments
                WHERE status = \'paid\'
                  AND COALESCE(paid_at, created_at) >= CURRENT_DATE
                  AND COALESCE(paid_at, created_at) < CURRENT_DATE + INTERVAL 1 DAY
            ) AS payments_today,
            (
                SELECT COUNT(*)
                FROM (
                    SELECT sc.id, sc.due_date,
                           GREATEST(sc.amount - COALESCE(p.paid_amount, 0), 0) AS remaining_balance
                    FROM student_charges sc
                    LEFT JOIN (
                        SELECT student_charge_id, SUM(amount) AS paid_amount
                        FROM payments
                        WHERE status = \'paid\'
                        GROUP BY student_charge_id
                    ) p ON p.student_charge_id = sc.id
                ) overdue_charges
                WHERE due_date < CURRENT_DATE AND remaining_balance > 0
            ) AS overdue_charges'
    );
    $statsStatement->execute();
    $stats = $statsStatement->fetch();

    $paymentsStatement = db()->prepare(
        'SELECT p.reference_number, p.amount, p.status,
                COALESCE(p.paid_at, p.created_at) AS payment_date,
                CONCAT(s.first_name, \' \', s.last_name) AS student_name
         FROM payments p
         INNER JOIN student_charges sc ON sc.id = p.student_charge_id
         INNER JOIN students s ON s.id = sc.student_id
         ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
         LIMIT 5'
    );
    $paymentsStatement->execute();
    $recentPayments = $paymentsStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('LCUPAY admin dashboard query failed.');
    $dashboardError = 'The dashboard is temporarily unavailable.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <header class="admin-page-header">
                    <div>
                        <h1 class="admin-page-title">Dashboard</h1>
                        <p class="admin-page-subtitle">An overview of student accounts and payment activity.</p>
                    </div>
                </header>
                <?php if ($dashboardError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php elseif ($stats): ?>
                    <section class="admin-stat-grid" aria-label="Registrar statistics">
                        <article class="admin-stat-card">
                            <h2 class="admin-stat-label">Active students</h2>
                            <p class="admin-stat-value"><?= htmlspecialchars((string)$stats['active_students'], ENT_QUOTES, 'UTF-8') ?></p>
                        </article>
                        <article class="admin-stat-card">
                            <h2 class="admin-stat-label">Outstanding balance</h2>
                            <p class="admin-stat-value"><?= htmlspecialchars(peso((string)$stats['outstanding_balance']), ENT_QUOTES, 'UTF-8') ?></p>
                        </article>
                        <article class="admin-stat-card">
                            <h2 class="admin-stat-label">Total collected</h2>
                            <p class="admin-stat-value"><?= htmlspecialchars(peso((string)$stats['total_collected']), ENT_QUOTES, 'UTF-8') ?></p>
                        </article>
                        <article class="admin-stat-card">
                            <h2 class="admin-stat-label">Payments today</h2>
                            <p class="admin-stat-value"><?= htmlspecialchars((string)$stats['payments_today'], ENT_QUOTES, 'UTF-8') ?></p>
                        </article>
                        <article class="admin-stat-card">
                            <h2 class="admin-stat-label">Overdue charges</h2>
                            <p class="admin-stat-value"><?= htmlspecialchars((string)$stats['overdue_charges'], ENT_QUOTES, 'UTF-8') ?></p>
                        </article>
                    </section>
                    <section class="admin-panel" aria-labelledby="recent-payments-title">
                        <h2 class="admin-panel-title" id="recent-payments-title">Recent payments</h2>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recentPayments === []): ?>
                                        <tr><td colspan="5">No payments found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentPayments as $payment): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$payment['student_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)$payment['reference_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars(ucfirst((string)$payment['status']), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$payment['payment_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars(peso((string)$payment['amount']), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
