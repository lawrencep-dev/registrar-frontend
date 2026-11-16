<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$activePage = 'dashboard';
$student = current_student();
$dashboardStatement = db()->prepare(
    'WITH charge_payments AS (
        SELECT
            sc.id,
            sc.amount,
            sc.due_date,
            COUNT(p.id) AS payment_count,
            COALESCE(SUM(CASE WHEN p.status = \'paid\' THEN p.amount ELSE 0 END), 0) AS paid_amount
        FROM student_charges sc
        LEFT JOIN payments p ON p.student_charge_id = sc.id
        WHERE sc.student_id = :charge_student_id
        GROUP BY sc.id, sc.amount, sc.due_date
    ), charge_summary AS (
        SELECT
            COALESCE(SUM(GREATEST(amount - paid_amount, 0)), 0) AS outstanding_balance,
            MIN(CASE WHEN amount > paid_amount THEN due_date END) AS earliest_due_date,
            CASE
                WHEN COUNT(*) = 0 OR COALESCE(SUM(payment_count), 0) = 0 THEN \'Unpaid\'
                WHEN SUM(CASE WHEN amount <= paid_amount THEN 1 ELSE 0 END) = COUNT(*) THEN \'Paid\'
                ELSE \'Partially paid\'
            END AS payment_status
        FROM charge_payments
    ), ranked_payments AS (
        SELECT
            p.id,
            p.amount,
            p.reference_number,
            p.created_at,
            ft.name AS fee_name,
            ROW_NUMBER() OVER (ORDER BY p.created_at DESC, p.id DESC) AS payment_rank
        FROM payments p
        JOIN student_charges sc ON sc.id = p.student_charge_id
        JOIN fee_types ft ON ft.id = sc.fee_type_id
        WHERE sc.student_id = :payment_student_id AND p.status = \'paid\'
    )
    SELECT
        charge_summary.outstanding_balance,
        charge_summary.earliest_due_date,
        charge_summary.payment_status,
        latest_payment.amount AS last_payment_amount,
        latest_payment.created_at AS last_payment_date,
        recent_payment.id AS recent_payment_id,
        recent_payment.amount AS recent_payment_amount,
        recent_payment.reference_number AS recent_payment_reference,
        recent_payment.created_at AS recent_payment_date,
        recent_payment.fee_name AS recent_payment_fee
    FROM charge_summary
    LEFT JOIN ranked_payments latest_payment ON latest_payment.payment_rank = 1
    LEFT JOIN ranked_payments recent_payment ON recent_payment.payment_rank <= 3
    ORDER BY recent_payment.payment_rank'
);
$dashboardStatement->execute([
    'charge_student_id' => (int)($_SESSION['student_id'] ?? 0),
    'payment_student_id' => (int)($_SESSION['student_id'] ?? 0),
]);
$dashboardRows = $dashboardStatement->fetchAll();
$dashboardSummary = $dashboardRows[0] ?? [
    'outstanding_balance' => 0,
    'earliest_due_date' => null,
    'payment_status' => 'Unpaid',
    'last_payment_amount' => null,
    'last_payment_date' => null,
];
$recentPayments = array_values(array_filter($dashboardRows, static fn(array $row): bool => $row['recent_payment_id'] !== null));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" />
    <link rel="stylesheet" href="home.css">
</head>
<body>
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="dashboard dash-dashboard">
        <div class="dash-content">
            <header class="dash-header">
                <div>
                    <h1 class="dash-title">Student Dashboard</h1>
                    <p class="dash-subtitle">Overview of your student account and payment activity.</p>
                </div>
                <div class="dash-user-card">
                        <span class="dash-avatar"><?= e($student['initials'] ?? '') ?></span>
                    <span class="dash-user-details">
                        <strong><?= e($student['name'] ?? '') ?></strong>
                        <span>Student account</span>
                    </span>
                </div>
            </header>

            <section class="dash-stat-grid" aria-label="Account summary">
                <article class="dash-stat-card">
                    <h2 class="dash-card-label">Outstanding balance</h2>
                    <p class="dash-stat-value"><?= e(money((float)$dashboardSummary['outstanding_balance'])) ?></p>
                    <p class="dash-stat-note"><?php if ((float)$dashboardSummary['outstanding_balance'] > 0 && $dashboardSummary['earliest_due_date'] !== null): ?>Due date: <?= e(date('F j, Y', strtotime((string)$dashboardSummary['earliest_due_date']))) ?><?php else: ?>No balance due<?php endif; ?></p>
                </article>
                <article class="dash-stat-card">
                    <h2 class="dash-card-label">Last payment</h2>
                    <?php if ($dashboardSummary['last_payment_amount'] === null): ?>
                        <p class="dash-stat-value">No payments yet</p>
                        <p class="dash-stat-note"></p>
                    <?php else: ?>
                        <p class="dash-stat-value"><?= e(money((float)$dashboardSummary['last_payment_amount'])) ?></p>
                        <p class="dash-stat-note"><?= e(date('F j, Y', strtotime((string)$dashboardSummary['last_payment_date']))) ?></p>
                    <?php endif; ?>
                </article>
                <article class="dash-stat-card">
                    <h2 class="dash-card-label">Payment status</h2>
                    <p class="dash-payment-status"><span class="dash-status-dot" aria-hidden="true"></span><?= e((string)$dashboardSummary['payment_status']) ?></p>
                </article>
            </section>

            <section class="dash-detail-grid" aria-label="Transactions and quick actions">
                <article class="dash-panel dash-transactions" id="dash-transactions">
                    <h2 class="dash-panel-title">Recent transactions</h2>
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Reference</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentPayments === []): ?>
                                    <tr><td colspan="4">No payments yet</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentPayments as $payment): ?>
                                        <tr>
                                            <td><?= e(date('M d, Y', strtotime((string)$payment['recent_payment_date']))) ?></td>
                                            <td><?= e((string)$payment['recent_payment_reference']) ?></td>
                                            <td><?= e((string)$payment['recent_payment_fee']) ?></td>
                                            <td><?= e(money((float)$payment['recent_payment_amount'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <aside class="dash-panel dash-quick-actions">
                    <h2 class="dash-panel-title">Quick actions</h2>
                    <div class="dash-action-content">
                        <h3 class="dash-action-title">Make a payment</h3>
                        <p class="dash-action-description">Settle your outstanding school fees.</p>
                        <a class="dash-pay-button" href="../payment/payment.php">Pay now</a>
                    </div>
                    <div class="dash-receipt">
                        <p>Need a copy of your receipt?</p>
                        <a href="../transaction-history/transaction-history.php">View transaction history <span aria-hidden="true">→</span></a>
                    </div>
                </aside>
            </section>
        </div>
    </main>
</body>
</html>