<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
date_default_timezone_set('Asia/Manila');

const FEES = [
  0 => ['Tuition fee', 15000.00],
  1 => ['Laboratory fee', 2000.00],
  2 => ['Miscellaneous fee', 1500.00],
];

$title = 'School Fees & Payment';
$activePage = 'payments';
$student = current_student();
$fee = (int)($_POST['fee'] ?? $_GET['fee'] ?? 0);
$fee = isset(FEES[$fee]) ? $fee : 0;
$type = (string)($_POST['type'] ?? 'full');
$amount = (string)($_POST['amount'] ?? FEES[$fee][1]);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
    http_response_code(400);
    exit('Your session expired. Go back and try again.');
  }
  $amountValue = (float)preg_replace('/[^0-9.]/', '', $amount);
  $feeAmount = FEES[$fee][1];

  if ($type === 'full') {
    $amountValue = $feeAmount;
  } elseif ($type !== 'partial' || $amountValue < 1 || $amountValue > $feeAmount) {
    $error = 'Enter a partial payment between ₱ 1.00 and the fee amount.';
  }

  if ($error === '') {
    $_SESSION['payment_draft'] = [
      'fee' => FEES[$fee][0],
      'amount' => $amountValue,
    ];
    header('Location: review.php');
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= e($title) ?></title>
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

            <form class="acct-payment-layout" id="acct-payment-form" data-selected-fee="<?= $fee ?>" method="post" action="payment.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <section class="acct-details-card" aria-labelledby="acct-details-heading">
                    <h2 id="acct-details-heading">Payment details</h2>
                    <p class="acct-card-intro">Select the applicable school fee</p>
                    <fieldset class="acct-fee-fieldset">
                        <legend class="acct-visually-hidden">Select a school fee</legend>
                        <div class="acct-fee-list" id="acct-fee-list"></div>
                    </fieldset>
                    <div class="acct-fields-row">
                        <div class="acct-field-group">
                            <label for="acct-payment-amount">Payment amount</label>
                            <input id="acct-payment-amount" name="amount" type="text" inputmode="decimal" value="<?= e(money((float)$amount)) ?>" readonly autocomplete="off">
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
                            <div class="acct-summary-fee"><span class="acct-summary-label">Selected fee</span><strong id="acct-selected-fee"><?= e(FEES[$fee][0]) ?></strong></div>
                            <div class="acct-summary-total"><span class="acct-summary-label">Amount due</span><strong id="acct-amount-due" aria-live="polite"><?= e(peso(FEES[$fee][1])) ?></strong></div>
                            <?php if ($error !== ''): ?><p class="payment-error"><?= e($error) ?></p><?php endif; ?>
                            <button class="acct-continue-button" type="submit">Continue to payment</button>
                        </section>
                        <p class="acct-summary-note">You can review all details before confirming your payment.</p>
                    </div>
                    <div class="acct-overview-column">
                        <section class="acct-overview-card" aria-labelledby="acct-overview-heading">
                            <h2 id="acct-overview-heading">Account overview</h2>
                            <div class="acct-overview-total"><span class="acct-summary-label">Total fees due</span><strong id="acct-overview-total">₱ 18,500.00</strong></div>
                            <div class="acct-overview-details">
                                <div class="acct-overview-detail"><span>Fees listed</span><strong id="acct-overview-count">3</strong></div>
                                <div class="acct-overview-detail"><span>Next due date</span><strong>Nov 30, 2026</strong></div>
                            </div>
                        </section>
                    </div>
                    <section class="acct-payment-info-card" aria-labelledby="acct-payment-info-heading">
                        <h2 id="acct-payment-info-heading">Payment information</h2>
                        <div class="acct-payment-info-row"><span>Due date</span><strong>Nov 30, 2026</strong></div>
                        <div class="acct-payment-info-row"><span>Partial payment limit</span><strong>₱1.00 minimum, up to fee amount</strong></div>
                    </section>
                </div>
            </form>
        </div>
    </main>
</body>
</html>