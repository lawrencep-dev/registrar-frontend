<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
date_default_timezone_set('Asia/Manila');
$activePage = 'payments';
$student = current_student();
$statement = db()->prepare(
    'SELECT
            sc.id,
            ft.name AS fee_name,
            sc.amount AS charge_amount,
            sc.due_date,
            COALESCE(SUM(p.amount), 0) AS paid_amount,
            GREATEST(sc.amount - COALESCE(SUM(p.amount), 0), 0) AS remaining_balance
     FROM student_charges sc
     JOIN fee_types ft ON ft.id = sc.fee_type_id
     LEFT JOIN payments p ON p.student_charge_id = sc.id AND p.status = \'paid\'
     WHERE sc.student_id = :student_id
     GROUP BY sc.id, ft.name, sc.amount, sc.due_date
     HAVING sc.amount > COALESCE(SUM(p.amount), 0)
     ORDER BY sc.due_date, ft.name'
);
$statement->execute(['student_id' => (int)$student['id']]);
$charges = $statement->fetchAll();
$selectedChargeId = (int)($_POST['charge_id'] ?? ($charges[0]['id'] ?? 0));
$selectedCharge = null;
foreach ($charges as $charge) {
    if ((int)$charge['id'] === $selectedChargeId) {
        $selectedCharge = $charge;
        break;
    }
}
if (!$selectedCharge && $charges !== []) {
    $selectedCharge = $charges[0];
    $selectedChargeId = (int)$selectedCharge['id'];
}
$type = (string)($_POST['type'] ?? 'full');
$amount = (string)($_POST['amount'] ?? ($selectedCharge['remaining_balance'] ?? '0.00'));
$error = '';
$totalRemainingCents = 0;
foreach ($charges as $charge) {
    $totalRemainingCents += amount_to_cents((string)$charge['remaining_balance']) ?? 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
    http_response_code(400);
    exit('Your session expired. Go back and try again.');
  }

    $selectedCharge = null;
    foreach ($charges as $charge) {
        if ((int)$charge['id'] === $selectedChargeId) {
            $selectedCharge = $charge;
            break;
        }
  }

    $remainingCents = $selectedCharge ? amount_to_cents((string)$selectedCharge['remaining_balance']) : null;
    $amountCents = $type === 'full' ? $remainingCents : amount_to_cents($amount);
    if ($remainingCents === null || $remainingCents < 100 || !in_array($type, ['full', 'partial'], true)) {
        $error = 'Select a valid outstanding charge.';
    } elseif ($amountCents === null || $amountCents < 100 || $amountCents > $remainingCents) {
        $error = 'Enter a payment between ₱ 1.00 and the remaining balance.';
    }

    if ($error === '' && $selectedCharge !== null && $amountCents !== null) {
    $_SESSION['payment_draft'] = [
            'charge_id' => (int)$selectedCharge['id'],
            'amount_cents' => $amountCents,
    ];
    header('Location: review.php');
    exit;
  }
}
$selectedRemaining = $selectedCharge ? (string)$selectedCharge['remaining_balance'] : '0.00';
$selectedAmount = $type === 'partial' ? $amount : $selectedRemaining;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>School Fees &amp; Payment</title>
    <link rel="stylesheet" href="../home/home.css">
    <link rel="stylesheet" href="payment.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Plus+Jakarta+Sans:ital,wght@0,200..800;1,200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
    <script src="payment.js" defer></script>
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="dashboard dash-dashboard">
        <div class="dash-content">
            <header class="dash-header">
                <div>
                    <h1 class="dash-title">Select Amount</h1>
                    <p class="dash-subtitle">Choose the fee or amount you want to pay.</p>
                </div>
                <div class="dash-user-card">
                      <span class="dash-avatar"><?= e($student['initials'] ?? '') ?></span>
                      <span class="dash-user-details"><strong><?= e($student['name'] ?? '') ?></strong><span>Student account</span></span>
                </div>
            </header>

            <form class="acct-payment-layout" id="acct-payment-form" data-selected-charge="<?= $selectedChargeId ?>" method="post" action="payment.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <section class="acct-details-card" aria-labelledby="acct-details-heading">
                    <h2 id="acct-details-heading">Payment details</h2>
                    <p class="acct-card-intro">Select the applicable school fee</p>
                    <fieldset class="acct-fee-fieldset">
                        <legend class="acct-visually-hidden">Select a school fee</legend>
                        <div class="acct-fee-list" id="acct-fee-list">
                            <?php foreach ($charges as $charge): ?>
                                <label class="acct-fee-option<?= (int)$charge['id'] === $selectedChargeId ? ' acct-fee-selected' : '' ?>" for="acct-fee-<?= (int)$charge['id'] ?>" data-charge-id="<?= (int)$charge['id'] ?>" data-fee-name="<?= e((string)$charge['fee_name']) ?>" data-charge-amount="<?= e((string)$charge['charge_amount']) ?>" data-remaining-balance="<?= e((string)$charge['remaining_balance']) ?>" data-due-date="<?= e((string)$charge['due_date']) ?>">
                                    <span class="acct-fee-copy">
                                        <span class="acct-fee-name"><?= e((string)$charge['fee_name']) ?></span>
                                        <span class="acct-fee-due">Due <?= e(date('M d, Y', strtotime((string)$charge['due_date']))) ?></span>
                                    </span>
                                    <span class="acct-fee-choice">
                                        <span class="acct-fee-amount"><?= e(peso((string)$charge['remaining_balance'])) ?></span>
                                        <input class="acct-fee-radio" id="acct-fee-<?= (int)$charge['id'] ?>" type="radio" name="charge_id" value="<?= (int)$charge['id'] ?>"<?= (int)$charge['id'] === $selectedChargeId ? ' checked' : '' ?>>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <?php if ($charges === []): ?>
                                <p>No outstanding charges.</p>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                    <div class="acct-fields-row">
                        <div class="acct-field-group">
                            <label for="acct-payment-amount">Payment amount</label>
                            <input id="acct-payment-amount" name="amount" type="text" inputmode="decimal" value="<?= e(peso($selectedAmount)) ?>"<?= $type === 'full' ? ' readonly' : '' ?> autocomplete="off">
                        </div>
                        <div class="acct-field-group">
                            <label for="acct-payment-type">Payment type</label>
                            <select id="acct-payment-type" name="type">
                                <option value="full"<?= $type === 'full' ? ' selected' : '' ?>>Full payment</option>
                                <option value="partial"<?= $type === 'partial' ? ' selected' : '' ?>>Partial payment</option>
                            </select>
                        </div>
                    </div>
                </section>
                <div class="acct-right-panels">
                    <div class="acct-summary-column">
                        <section class="acct-summary-card" aria-labelledby="acct-summary-heading">
                            <h2 id="acct-summary-heading">Payment summary</h2>
                            <div class="acct-summary-fee"><span class="acct-summary-label">Selected fee</span><strong id="acct-selected-fee"><?= e((string)($selectedCharge['fee_name'] ?? '')) ?></strong></div>
                            <div class="acct-summary-total"><span class="acct-summary-label">Amount due</span><strong id="acct-amount-due" aria-live="polite"><?= e(peso($selectedAmount)) ?></strong></div>
                            <?php if ($error !== ''): ?><p class="payment-error"><?= e($error) ?></p><?php endif; ?>
                            <button class="acct-continue-button" type="submit">Continue to payment</button>
                        </section>
                        <p class="acct-summary-note">You can review all details before confirming your payment.</p>
                    </div>
                    <div class="acct-overview-column">
                        <section class="acct-overview-card" aria-labelledby="acct-overview-heading">
                            <h2 id="acct-overview-heading">Account overview</h2>
                            <div class="acct-overview-total"><span class="acct-summary-label">Total fees due</span><strong id="acct-overview-total"><?= e(peso(decimal_from_cents($totalRemainingCents))) ?></strong></div>
                            <div class="acct-overview-details">
                                <div class="acct-overview-detail"><span>Fees listed</span><strong id="acct-overview-count"><?= count($charges) ?></strong></div>
                                <div class="acct-overview-detail"><span>Next due date</span><strong><?= $charges === [] ? 'None' : e(date('M d, Y', strtotime((string)$charges[0]['due_date']))) ?></strong></div>
                            </div>
                        </section>
                    </div>
                    <section class="acct-payment-info-card" aria-labelledby="acct-payment-info-heading">
                        <h2 id="acct-payment-info-heading">Payment information</h2>
                        <div class="acct-payment-info-row"><span>Due date</span><strong id="acct-selected-due-date"><?= $selectedCharge ? e(date('M d, Y', strtotime((string)$selectedCharge['due_date']))) : 'None' ?></strong></div>
                        <div class="acct-payment-info-row"><span>Partial payment limit</span><strong>₱1.00 minimum, up to fee amount</strong></div>
                    </section>
                </div>
            </form>
        </div>
    </main>
</body>
</html>