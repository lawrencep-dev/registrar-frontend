<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$activePage = 'payments';
$student = current_student();
$csrfToken = csrf_token();
$config = require __DIR__ . '/../includes/config.php';
$convenienceFee = (float)$config['convenience_fee'];
$draft = $_SESSION['payment_draft'] ?? ['fee' => 'Tuition fee', 'amount' => 15000.00];
$amount = number_format((float)$draft['amount'], 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Payment | LCUPAY</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="../home/home.css">
    <link rel="stylesheet" href="review.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="dashboard dash-dashboard">
        <div class="dash-content">
            <header class="dash-header">
                <div>
                    <h1 class="dash-title">Review Payment</h1>
                    <p class="dash-subtitle">Check your payment information before proceeding.</p>
                </div>
                <div class="dash-user-card">
                    <span class="dash-avatar"><?= e($student['initials'] ?? '') ?></span>
                    <span class="dash-user-details"><strong><?= e($student['name'] ?? '') ?></strong><span>Student account</span></span>
                </div>
            </header>
            <form method="post" action="success.php" class="review-form">
                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="fee" value="<?= htmlspecialchars($draft['fee'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="amount" value="<?= htmlspecialchars((string)$draft['amount'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="payment_method" value="GCash (demo)">
                <div class="review-grid">
                    <section class="dash-panel pay-info" aria-label="Payment information">
                        <h2 class="dash-panel-title">Payment information</h2>
                        <dl class="pay-list">
                            <div class="pay-row"><dt>Student name</dt><dd><?= e($student['name'] ?? '') ?></dd></div>
                            <div class="pay-row"><dt>Student ID</dt><dd><?= e($student['student_number'] ?? '') ?></dd></div>
                            <div class="pay-row"><dt>Payment for</dt><dd><?= htmlspecialchars($draft['fee'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div class="pay-row"><dt>Payment method</dt><dd>GCash (demo)</dd></div>
                        </dl>
                    </section>
                    <aside class="review-side">
                        <section class="dash-panel pay-total" aria-label="Payment total">
                            <h2 class="dash-panel-title">Payment total</h2>
                            <p class="pay-label">Amount</p>
                            <p class="pay-amount"><?= e(money((float)$draft['amount'])) ?></p>
                            <p class="pay-label">Convenience fee</p>
                            <p class="pay-fee"><?= e(money($convenienceFee)) ?></p>
                            <p class="pay-label">Total to pay</p>
                            <p class="pay-grand"><?= e(money((float)$draft['amount'] + $convenienceFee)) ?></p>
                        </section>
                        <label class="pay-check"><input type="checkbox" name="confirm" value="1" required> I confirm that the payment details above are correct.</label>
                        <button class="dash-pay-button pay-confirm" type="submit">Confirm payment</button>
                        <p class="pay-terms">This demo marks confirmed payments as paid.</p>
                    </aside>
                </div>
            </form>
        </div>
    </main>
</body>
</html>