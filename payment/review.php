<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$activePage = 'payments';
$student = current_student();
$csrfToken = csrf_token();
$config = require __DIR__ . '/../includes/config.php';
$convenienceFeeCents = amount_to_cents((string)$config['convenience_fee']);
$draft = $_SESSION['payment_draft'] ?? null;
if (!is_array($draft) || !isset($draft['charge_id'], $draft['amount_cents']) || !is_int($draft['charge_id']) || !is_int($draft['amount_cents'])) {
    unset($_SESSION['payment_draft']);
    header('Location: payment.php');
    exit;
}

$chargeStatement = db()->prepare(
    'SELECT
        sc.id,
        ft.name AS fee_name,
        sc.amount,
          sc.due_date
     FROM student_charges sc
     JOIN fee_types ft ON ft.id = sc.fee_type_id
     WHERE sc.id = :charge_id AND sc.student_id = :student_id
      LIMIT 1'
);
$chargeStatement->execute([
    'charge_id' => $draft['charge_id'],
    'student_id' => (int)$student['id'],
]);
$charge = $chargeStatement->fetch();
$remainingCents = $charge ? amount_to_cents(remaining_balance((int)$charge['id'])) : null;
if (!$charge || $remainingCents === null || $draft['amount_cents'] < 100 || $draft['amount_cents'] > $remainingCents || $convenienceFeeCents === null) {
    unset($_SESSION['payment_draft']);
    header('Location: payment.php');
    exit;
}
$error = isset($_SESSION['payment_error']) ? 'Payment could not be completed. Please review and try again.' : '';
unset($_SESSION['payment_error']);
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
            <form method="post" action="confirm.php" class="review-form">
                <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                <div class="review-grid">
                    <section class="dash-panel pay-info" aria-label="Payment information">
                        <h2 class="dash-panel-title">Payment information</h2>
                        <dl class="pay-list">
                            <div class="pay-row"><dt>Student name</dt><dd><?= e($student['name'] ?? '') ?></dd></div>
                            <div class="pay-row"><dt>Student ID</dt><dd><?= e($student['student_number'] ?? '') ?></dd></div>
                            <div class="pay-row"><dt>Payment for</dt><dd><?= e((string)$charge['fee_name']) ?></dd></div>
                            <div class="pay-row"><dt>Payment method</dt><dd>GCash (demo)</dd></div>
                            <div class="pay-row"><dt>Due date</dt><dd><?= e(date('M d, Y', strtotime((string)$charge['due_date']))) ?></dd></div>
                        </dl>
                    </section>
                    <aside class="review-side">
                        <section class="dash-panel pay-total" aria-label="Payment total">
                            <h2 class="dash-panel-title">Payment total</h2>
                            <p class="pay-label">Amount</p>
                            <p class="pay-amount"><?= e(peso(decimal_from_cents($draft['amount_cents']))) ?></p>
                            <p class="pay-label">Convenience fee</p>
                            <p class="pay-fee"><?= e(peso(decimal_from_cents($convenienceFeeCents))) ?></p>
                            <p class="pay-label">Total to pay</p>
                            <p class="pay-grand"><?= e(peso(decimal_from_cents($draft['amount_cents'] + $convenienceFeeCents))) ?></p>
                        </section>
                        <?php if ($error !== ''): ?><p class="pay-terms" role="alert"><?= e($error) ?></p><?php endif; ?>
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