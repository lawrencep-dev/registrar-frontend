<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$allowedPaymentMethods = ['GCash (demo)', 'Bank transfer (demo)'];
$draft = $_SESSION['payment_draft'] ?? null;
if (!is_array($draft)
    || !isset($draft['charge_id'], $draft['amount_cents'])
    || !is_int($draft['charge_id'])
    || !is_int($draft['amount_cents'])) {
    unset($_SESSION['payment_draft'], $_SESSION['payment_method']);
    header('Location: payment.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentMethodInput = $_POST['payment_method'] ?? null;
    if (!csrf_verify(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)
        || ($_POST['confirm'] ?? '') !== '1'
        || !is_string($paymentMethodInput)
        || !in_array($paymentMethodInput, $allowedPaymentMethods, true)) {
        http_response_code(400);
        exit('Unable to continue with payment.');
    }

    $_SESSION['payment_method'] = $paymentMethodInput;
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Unable to continue with payment.');
} elseif (!is_string($_SESSION['payment_method'] ?? null)
    || !in_array($_SESSION['payment_method'], $allowedPaymentMethods, true)) {
    header('Location: review.php');
    exit;
}

$paymentMethod = $_SESSION['payment_method'];
$studentId = (int)($_SESSION['student_id'] ?? 0);
try {
    $chargeStatement = db()->prepare(
        'SELECT sc.id, sc.amount, ft.name AS fee_name
         FROM student_charges sc
         INNER JOIN fee_types ft ON ft.id = sc.fee_type_id
         WHERE sc.id = :charge_id AND sc.student_id = :student_id
         LIMIT 1'
    );
    $chargeStatement->execute([
        'charge_id' => $draft['charge_id'],
        'student_id' => $studentId,
    ]);
    $charge = $chargeStatement->fetch();
    $chargeCents = $charge ? amount_to_cents((string)$charge['amount']) : null;
    $remainingCents = $charge ? amount_to_cents(remaining_balance((int)$charge['id'])) : null;
    $config = require __DIR__ . '/../includes/config.php';
    $convenienceFeeCents = amount_to_cents((string)($config['convenience_fee'] ?? ''));
} catch (Throwable $exception) {
    error_log('LCUPAY demo gateway failed to load payment details: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to load payment details.');
}

if (!$charge
    || $chargeCents === null
    || $remainingCents === null
    || $draft['amount_cents'] < 100
    || $draft['amount_cents'] > $remainingCents
    || $convenienceFeeCents === null) {
    unset($_SESSION['payment_draft'], $_SESSION['payment_method']);
    header('Location: payment.php');
    exit;
}

$totalCents = $draft['amount_cents'] + $convenienceFeeCents;
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8') ?> | LCUPAY</title>
    <link rel="stylesheet" href="gateway.css">
</head>
<body>
    <main class="gw-page">
        <section class="gw-card" aria-label="Demo payment provider">
            <header class="gw-header">
                <h1 class="gw-provider"><?= htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="gw-notice">Demo only: no real payment is made</p>
            </header>
            <div class="gw-content">
                <p class="gw-merchant-label">Paying merchant</p>
                <p class="gw-merchant">LCUPAY Student Portal</p>
                <dl class="gw-details">
                    <div class="gw-row">
                        <dt>Fee</dt>
                        <dd><?= htmlspecialchars((string)$charge['fee_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div class="gw-row">
                        <dt>Amount to pay</dt>
                        <dd><?= htmlspecialchars(peso(decimal_from_cents($totalCents)), ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                </dl>
                <form class="gw-authorize-form" id="gw-authorize-form" method="post" action="confirm.php">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="confirm" value="1">
                    <button class="gw-authorize-button" id="gw-authorize-button" type="submit">Authorize payment</button>
                    <p class="gw-processing" id="gw-processing" aria-live="polite"></p>
                </form>
                <a class="gw-cancel" href="review.php">Cancel</a>
            </div>
        </section>
    </main>
    <script>
        const authorizeForm = document.getElementById('gw-authorize-form');
        const authorizeButton = document.getElementById('gw-authorize-button');
        const processingMessage = document.getElementById('gw-processing');
        let authorizationStarted = false;

        authorizeForm.addEventListener('submit', (event) => {
            if (authorizationStarted) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            authorizationStarted = true;
            authorizeButton.disabled = true;
            authorizeButton.textContent = 'Processing payment...';
            processingMessage.textContent = 'Processing payment...';
            window.setTimeout(() => authorizeForm.submit(), 2000);
        });
    </script>
</body>
</html>
