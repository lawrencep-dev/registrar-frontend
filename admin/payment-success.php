<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/functions.php';

$payment = $_SESSION['admin_payment_success'] ?? null;
unset($_SESSION['admin_payment_success']);
$referenceNumber = is_array($payment) && isset($payment['reference_number'])
    ? (string)$payment['reference_number']
    : '';
$amount = is_array($payment) && isset($payment['amount'])
    ? (string)$payment['amount']
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment recorded | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php $activePage = 'payments'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <section class="admin-panel admin-payment-success">
                    <?php if ($referenceNumber !== ''): ?>
                        <h1 class="admin-page-title">Payment recorded</h1>
                        <p>The cash payment was recorded successfully.</p>
                        <dl>
                            <div><dt>Reference number</dt><dd><?= htmlspecialchars($referenceNumber, ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>Amount</dt><dd><?= htmlspecialchars(peso($amount), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        </dl>
                    <?php else: ?>
                        <h1 class="admin-page-title">No recent payment</h1>
                        <p>No payment receipt is available in this session.</p>
                    <?php endif; ?>
                    <a class="admin-button" href="payments.php">Back to payments</a>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
