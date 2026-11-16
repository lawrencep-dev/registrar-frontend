<?php $activePage = 'transaction-history'; ?>
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
                    <span class="dash-avatar">LC</span>
                    <span class="dash-user-details">
                        <strong>Lei Justin Clemente</strong>
                        <span>Student account</span>
                    </span>
                </div>
            </header>

            <section class="dash-panel history-panel" aria-label="Transactions">
                <h2 class="dash-panel-title">Recent transactions</h2>
                <div class="dash-table-wrap">
                    <table class="dash-table">
                        <thead>
                            <tr><th scope="col">Date</th><th scope="col">Reference</th><th scope="col">Description</th><th scope="col">Amount</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Oct 18, 2026</td><td>TXN-20481</td><td>Tuition payment</td><td>₱5,000.00</td></tr>
                            <tr><td>Sep 12, 2026</td><td>TXN-19832</td><td>Laboratory fee</td><td>₱1,500.00</td></tr>
                            <tr><td>Aug 05, 2026</td><td>TXN-18310</td><td>Miscellaneous fee</td><td>₱2,000.00</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>