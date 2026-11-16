<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$activePage = 'dashboard';
$student = current_student();
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
                    <p class="dash-stat-value">₱18,500.00</p>
                    <p class="dash-stat-note">Due date: November 30, 2026</p>
                </article>
                <article class="dash-stat-card">
                    <h2 class="dash-card-label">Last payment</h2>
                    <p class="dash-stat-value">₱5,000.00</p>
                    <p class="dash-stat-note">October 18, 2026</p>
                </article>
                <article class="dash-stat-card">
                    <h2 class="dash-card-label">Payment status</h2>
                    <p class="dash-payment-status"><span class="dash-status-dot" aria-hidden="true"></span>Partially paid</p>
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
                                <tr>
                                    <td>Oct 18, 2026</td>
                                    <td>TXN-20481</td>
                                    <td>Tuition payment</td>
                                    <td>₱5,000</td>
                                </tr>
                                <tr>
                                    <td>Sep 12, 2026</td>
                                    <td>TXN-19832</td>
                                    <td>Laboratory fee</td>
                                    <td>₱1,500</td>
                                </tr>
                                <tr>
                                    <td>Aug 05, 2026</td>
                                    <td>TXN-18310</td>
                                    <td>Enrollment fee</td>
                                    <td>₱2,000</td>
                                </tr>
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