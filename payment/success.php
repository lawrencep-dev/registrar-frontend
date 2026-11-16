<?php $activePage = 'payments'; ?>
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
                    <h1 class="dash-title">Payment successful</h1>
                    <p class="dash-subtitle">Your demo payment has been confirmed.</p>
                </div>
            </header>
            <section class="dash-panel success-panel">
                <h2 class="dash-panel-title">Thank you, Lei Justin Clemente</h2>
                <p class="pay-terms">A GCash (demo) payment was recorded successfully.</p>
                <a class="dash-pay-button success-link" href="../transaction-history/transaction-history.php">View transaction history</a>
            </section>
        </div>
    </main>
</body>
</html>