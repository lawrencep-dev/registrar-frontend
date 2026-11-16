<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$activePage = 'my-account';
$student = current_student();
$balanceSummary = student_balance_summary((int)($student['id'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account | LCUPAY</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="../home/home.css">
    <link rel="stylesheet" href="my-account.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="dashboard dash-dashboard">
        <div class="dash-content">
            <header class="dash-header">
                <div>
                    <h1 class="dash-title">My Account</h1>
                    <p class="dash-subtitle">Your student profile and account balance.</p>
                </div>
                <div class="dash-user-card">
                    <span class="dash-avatar"><?= e($student['initials'] ?? '') ?></span>
                    <span class="dash-user-details"><strong><?= e($student['name'] ?? '') ?></strong><span>Student account</span></span>
                </div>
            </header>
            <section class="account-grid" aria-label="Student account details">
                <article class="dash-panel account-panel">
                    <h2 class="dash-panel-title">Profile</h2>
                    <dl class="account-details">
                        <div><dt>Student name</dt><dd><?= e($student['name'] ?? '') ?></dd></div>
                        <div><dt>Student number</dt><dd><?= e($student['student_number'] ?? '') ?></dd></div>
                        <div><dt>Email</dt><dd><?= e($student['email'] ?? '') ?></dd></div>
                    </dl>
                </article>
                <article class="dash-panel account-panel">
                    <h2 class="dash-panel-title">Balance summary</h2>
                    <p class="dash-card-label account-balance-label">Outstanding balance</p>
                    <p class="dash-stat-value"><?= e(money($balanceSummary['balance'])) ?></p>
                    <p class="dash-stat-note"><?php if ($balanceSummary['balance'] > 0 && $balanceSummary['next_due_date'] !== null): ?>Due date: <?= e(date('F j, Y', strtotime((string)$balanceSummary['next_due_date']))) ?><?php else: ?>No balance due<?php endif; ?></p>
                </article>
            </section>
        </div>
    </main>
</body>
</html>
