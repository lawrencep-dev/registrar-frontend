<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function payments_csv_safe_text(string $value): string {
    return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
}

$isExportRequest = $_SERVER['REQUEST_METHOD'] === 'POST';
$page = admin_positive_int($_GET['page'] ?? '1');
if ($page === null) {
    http_response_code(400);
    exit('Invalid request.');
}

$filterSource = $isExportRequest ? $_POST : $_GET;
if ($isExportRequest) {
    $action = $filterSource['action'] ?? null;
    $csrfToken = $filterSource['csrf_token'] ?? null;
    if ($action !== 'export' || !admin_csrf_verify(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(400);
        exit('Invalid request.');
    }
}

$fromDate = $filterSource['from_date'] ?? '';
$toDate = $filterSource['to_date'] ?? '';
$statusFilter = $filterSource['status'] ?? '';
$search = $filterSource['q'] ?? '';
if (!is_string($fromDate) || ($fromDate !== '' && !admin_is_valid_date($fromDate))) {
    http_response_code(400);
    exit('Invalid date filter.');
}
if (!is_string($toDate) || ($toDate !== '' && !admin_is_valid_date($toDate))) {
    http_response_code(400);
    exit('Invalid date filter.');
}
if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
    http_response_code(400);
    exit('Invalid date range.');
}
if (!is_string($statusFilter) || !in_array($statusFilter, ['', 'paid', 'failed'], true)) {
    http_response_code(400);
    exit('Invalid status filter.');
}
if (!is_string($search) || mb_strlen($search, 'UTF-8') > 100) {
    http_response_code(400);
    exit('Invalid search filter.');
}
$search = trim($search);

$conditions = [];
$parameters = [];
if ($fromDate !== '') {
    $conditions[] = 'COALESCE(p.paid_at, p.created_at) >= :from_date';
    $parameters['from_date'] = $fromDate;
}
if ($toDate !== '') {
    $conditions[] = 'COALESCE(p.paid_at, p.created_at) < DATE_ADD(:to_date, INTERVAL 1 DAY)';
    $parameters['to_date'] = $toDate;
}
if ($statusFilter !== '') {
    $conditions[] = 'p.status = :status_filter';
    $parameters['status_filter'] = $statusFilter;
}
if ($search !== '') {
    $likePattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $conditions[] = '(p.reference_number LIKE :reference_pattern ESCAPE \'!\'
                     OR s.student_number LIKE :student_number_pattern ESCAPE \'!\'
                     OR CONCAT(s.first_name, \' \', s.last_name) LIKE :student_name_pattern ESCAPE \'!\'
                     OR s.email LIKE :student_email_pattern ESCAPE \'!\')';
    $parameters['reference_pattern'] = $likePattern;
    $parameters['student_number_pattern'] = $likePattern;
    $parameters['student_name_pattern'] = $likePattern;
    $parameters['student_email_pattern'] = $likePattern;
}
$whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$joins = ' FROM payments p
           INNER JOIN student_charges sc ON sc.id = p.student_charge_id
           INNER JOIN students s ON s.id = sc.student_id';

if ($isExportRequest) {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        error_log('LCUPAY admin payment export stream failed.');
        http_response_code(500);
        exit('Unable to export payments right now.');
    }

    try {
        $exportStatement = db()->prepare(
            'SELECT COALESCE(p.paid_at, p.created_at) AS payment_date,
                    CONCAT(s.first_name, \' \', s.last_name) AS student_name,
                    p.reference_number, p.payment_method, p.status, p.amount, p.convenience_fee
             ' . $joins . $whereSql . '
             ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
             LIMIT 10000'
        );
        $exportStatement->execute($parameters);
        $exportRows = $exportStatement->fetchAll();

        $csvRows = [
            ['Payment date', 'Student name', 'Reference', 'Payment method', 'Status', 'Amount', 'Convenience fee'],
        ];
        foreach ($exportRows as $row) {
            $csvRows[] = [
                payments_csv_safe_text((string)$row['payment_date']),
                payments_csv_safe_text((string)$row['student_name']),
                payments_csv_safe_text((string)$row['reference_number']),
                payments_csv_safe_text((string)$row['payment_method']),
                payments_csv_safe_text((string)$row['status']),
                (string)$row['amount'],
                (string)$row['convenience_fee'],
            ];
        }
        foreach ($csvRows as $csvRow) {
            if (fputcsv($stream, $csvRow, ',', '"', '') === false) {
                throw new RuntimeException('CSV generation failed.');
            }
        }
        rewind($stream);
        admin_audit_log(
            (int)$_SESSION[ADMIN_SESSION_ID_KEY],
            'payments_csv_export',
            'payment_export',
            null,
            [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'status' => $statusFilter,
                'search_applied' => $search === '' ? 0 : 1,
                'search_length' => mb_strlen($search, 'UTF-8'),
            ]
        );
    } catch (Throwable $exception) {
        fclose($stream);
        error_log('LCUPAY admin payment export failed.');
        http_response_code(500);
        exit('Unable to export payments right now.');
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lcupay-payments-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    fpassthru($stream);
    fclose($stream);
    exit;
}

$pageSize = 25;
$payments = [];
$filteredCount = 0;
$filteredPaidTotal = '0.00';
$pageError = '';
try {
    $summaryStatement = db()->prepare(
        'SELECT COUNT(*) AS filtered_count,
                COALESCE(SUM(CASE WHEN p.status = \'paid\' THEN p.amount ELSE 0 END), 0) AS filtered_paid_total
         ' . $joins . $whereSql
    );
    $summaryStatement->execute($parameters);
    $summary = $summaryStatement->fetch();
    $filteredCount = (int)$summary['filtered_count'];
    $filteredPaidTotal = (string)$summary['filtered_paid_total'];
    $totalPages = max(1, (int)ceil($filteredCount / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;

    $paymentsStatement = db()->prepare(
        'SELECT p.id, sc.id AS charge_id,
                COALESCE(p.paid_at, p.created_at) AS payment_date,
                CONCAT(s.first_name, \' \', s.last_name) AS student_name,
                s.student_number, p.reference_number, p.payment_method, p.status, p.amount
         ' . $joins . $whereSql . '
         ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
         LIMIT :page_size OFFSET :page_offset'
    );
    foreach ($parameters as $name => $value) {
        $paymentsStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }
    $paymentsStatement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
    $paymentsStatement->bindValue(':page_offset', $offset, PDO::PARAM_INT);
    $paymentsStatement->execute();
    $payments = $paymentsStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('LCUPAY admin payment list failed.');
    $pageError = 'Unable to load payments right now.';
}

$activePage = 'payments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <header class="admin-page-header">
                    <div>
                        <h1 class="admin-page-title">Payments</h1>
                        <p class="admin-page-subtitle">Review payment activity and record cashier payments.</p>
                    </div>
                    <a class="admin-button" href="record-payment.php">Record cash payment</a>
                </header>
                <?php if ($pageError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <section class="admin-panel">
                    <form class="admin-payment-filter-form" method="get">
                        <div class="admin-form-field">
                            <label for="payment-from-date">From</label>
                            <input id="payment-from-date" name="from_date" type="date" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="admin-form-field">
                            <label for="payment-to-date">To</label>
                            <input id="payment-to-date" name="to_date" type="date" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="admin-form-field">
                            <label for="payment-status">Status</label>
                            <select id="payment-status" name="status">
                                <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
                                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                            </select>
                        </div>
                        <div class="admin-form-field">
                            <label for="payment-search">Reference or student</label>
                            <input id="payment-search" name="q" type="search" maxlength="100" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="admin-filter-actions">
                            <button class="admin-button" type="submit">Apply filters</button>
                            <a class="admin-button admin-button-secondary" href="payments.php">Clear</a>
                        </div>
                    </form>
                    <div class="admin-payment-summary">
                        <?php if ($pageError !== ''): ?>
                            <p>Filtered paid total is unavailable.</p>
                        <?php else: ?>
                            <p>Filtered paid total: <strong><?= htmlspecialchars(peso($filteredPaidTotal), ENT_QUOTES, 'UTF-8') ?></strong></p>
                            <form method="post" action="payments.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="export">
                                <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                <button class="admin-button admin-button-secondary" type="submit">Export filtered CSV</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Date</th><th>Student</th><th>Reference</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead>
                            <tbody>
                                <?php if ($pageError !== ''): ?>
                                    <tr><td colspan="6">Payment results are unavailable.</td></tr>
                                <?php elseif ($payments === []): ?>
                                    <tr><td colspan="6">No payments found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$payment['payment_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$payment['student_name'] . ' (' . (string)$payment['student_number'] . ')', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$payment['reference_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$payment['payment_method'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(ucfirst((string)$payment['status']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(peso((string)$payment['amount']), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($pageError === '' && $totalPages > 1): ?>
                        <nav class="admin-pagination" aria-label="Payment pages">
                            <span>Page <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars((string)$totalPages, ENT_QUOTES, 'UTF-8') ?></span>
                            <div>
                                <?php if ($page > 1): ?>
                                    <?php $previousQuery = ['page' => $page - 1, 'from_date' => $fromDate, 'to_date' => $toDate, 'status' => $statusFilter, 'q' => $search]; ?>
                                    <a class="admin-button admin-button-secondary" href="payments.php?<?= htmlspecialchars(http_build_query($previousQuery), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                                <?php endif; ?>
                                <?php if ($page < $totalPages): ?>
                                    <?php $nextQuery = ['page' => $page + 1, 'from_date' => $fromDate, 'to_date' => $toDate, 'status' => $statusFilter, 'q' => $search]; ?>
                                    <a class="admin-button admin-button-secondary" href="payments.php?<?= htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        </nav>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
