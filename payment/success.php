<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$activePage = 'payments';
$student = current_student();
$reference = trim((string)($_GET['ref'] ?? ''));
if ($reference === '') {
    header('Location: ../home/home.php');
    exit;
}

$paymentStatement = db()->prepare(
    'SELECT p.reference_number, p.amount, p.status, p.payment_method, p.created_at, ft.name AS fee_name
     FROM payments p
     JOIN student_charges sc ON sc.id = p.student_charge_id
     JOIN fee_types ft ON ft.id = sc.fee_type_id
     WHERE p.reference_number = :reference AND sc.student_id = :student_id
     LIMIT 1'
);
$paymentStatement->execute([
    'reference' => $reference,
    'student_id' => (int)$student['id'],
]);
$payment = $paymentStatement->fetch();
if (!$payment) {
    header('Location: ../home/home.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful | LCUPAY</title>
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
                    <h1 class="dash-title">Payment <?= e(ucfirst((string)$payment['status'])) ?></h1>
                    <p class="dash-subtitle">Your payment details are shown below.</p>
                </div>
                <div class="dash-user-card">
                    <span class="dash-avatar"><?= e($student['initials'] ?? '') ?></span>
                    <span class="dash-user-details"><strong><?= e($student['name'] ?? '') ?></strong><span>Student account</span></span>
                </div>
            </header>
            <section class="dash-panel success-panel">
                <h2 class="dash-panel-title">Thank you, <?= e($student['name'] ?? '') ?></h2>
                <dl class="pay-list">
                    <div class="pay-row"><dt>Reference</dt><dd><?= e((string)$payment['reference_number']) ?></dd></div>
                    <div class="pay-row"><dt>Payment for</dt><dd><?= e((string)$payment['fee_name']) ?></dd></div>
                    <div class="pay-row"><dt>Amount</dt><dd><?= e(peso((string)$payment['amount'])) ?></dd></div>
                    <div class="pay-row"><dt>Payment method</dt><dd><?= e((string)$payment['payment_method']) ?></dd></div>
                    <div class="pay-row"><dt>Status</dt><dd><?= e(ucfirst((string)$payment['status'])) ?></dd></div>
                    <div class="pay-row"><dt>Date</dt><dd><?= e(date('M d, Y g:i A', strtotime((string)$payment['created_at']))) ?></dd></div>
                </dl>
                <a class="dash-pay-button success-link" href="../home/home.php">Return to dashboard</a>
                <a class="dash-pay-button success-link" href="../transaction-history/transaction-history.php">View transaction history</a>
            </section>
        </div>
    </main>
</body>
</html>