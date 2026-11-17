<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$rawPage = $_GET['page'] ?? '1';
$page = filter_var($rawPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($page === false) {
    http_response_code(400);
    exit('Invalid request.');
}

$search = $_GET['q'] ?? '';
if (!is_string($search) || mb_strlen($search, 'UTF-8') > 100) {
    http_response_code(400);
    exit('Invalid request.');
}
$search = trim($search);
$likePattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
$whereSql = '';
$searchParameters = [];
if ($search !== '') {
    $whereSql = ' WHERE s.student_number LIKE :student_number ESCAPE \'!\'
                  OR CONCAT(s.first_name, \' \', s.last_name) LIKE :student_name ESCAPE \'!\'
                  OR s.email LIKE :email ESCAPE \'!\'';
    $searchParameters = [
        'student_number' => $likePattern,
        'student_name' => $likePattern,
        'email' => $likePattern,
    ];
}

$pageSize = 20;
$students = [];
$balances = [];
$totalStudents = 0;
$totalPages = 1;
$pageError = '';

try {
    $countStatement = db()->prepare('SELECT COUNT(*) FROM students s' . $whereSql);
    $countStatement->execute($searchParameters);
    $totalStudents = (int)$countStatement->fetchColumn();
    $totalPages = max(1, (int)ceil($totalStudents / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;

    $studentStatement = db()->prepare(
        'SELECT s.id, s.student_number, s.email, s.first_name, s.last_name, s.is_active
         FROM students s' . $whereSql . '
         ORDER BY s.last_name, s.first_name, s.student_number
         LIMIT :page_size OFFSET :page_offset'
    );
    foreach ($searchParameters as $name => $value) {
        $studentStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }
    $studentStatement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
    $studentStatement->bindValue(':page_offset', $offset, PDO::PARAM_INT);
    $studentStatement->execute();
    $students = $studentStatement->fetchAll();

    if ($students !== []) {
        $studentIds = array_column($students, 'id');
        $idPlaceholders = [];
        $balanceStatementParameters = [];
        foreach ($studentIds as $index => $studentId) {
            $placeholder = ':student_id_' . $index;
            $idPlaceholders[] = $placeholder;
            $balanceStatementParameters[$placeholder] = (int)$studentId;
        }

        $balanceStatement = db()->prepare(
            'SELECT sc.student_id,
                    COALESCE(SUM(GREATEST(sc.amount - COALESCE(p.paid_amount, 0), 0)), 0) AS outstanding_balance
             FROM student_charges sc
             LEFT JOIN (
                 SELECT student_charge_id, SUM(amount) AS paid_amount
                 FROM payments
                 WHERE status = \'paid\'
                 GROUP BY student_charge_id
             ) p ON p.student_charge_id = sc.id
             WHERE sc.student_id IN (' . implode(', ', $idPlaceholders) . ')
             GROUP BY sc.student_id'
        );
        foreach ($balanceStatementParameters as $placeholder => $studentId) {
            $balanceStatement->bindValue($placeholder, $studentId, PDO::PARAM_INT);
        }
        $balanceStatement->execute();
        foreach ($balanceStatement->fetchAll() as $balance) {
            $balances[(string)$balance['student_id']] = (string)$balance['outstanding_balance'];
        }
    }
} catch (Throwable $exception) {
    error_log('LCUPAY admin student list failed.');
    $pageError = 'Unable to load the student list right now.';
}

$activePage = 'students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students | LCUPAY Registrar</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-content">
                <header class="admin-page-header">
                    <div>
                        <h1 class="admin-page-title">Students</h1>
                        <p class="admin-page-subtitle">Search and manage student accounts.</p>
                    </div>
                    <a class="admin-button" href="student-add.php">Add student</a>
                </header>
                <?php if ($pageError !== ''): ?>
                    <p class="admin-alert" role="alert"><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <section class="admin-panel">
                    <form class="admin-search-form" method="get">
                        <label for="student-search">Search by student number, name, or email</label>
                        <div class="admin-search-controls">
                            <input id="student-search" name="q" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                            <button class="admin-button" type="submit">Search</button>
                            <?php if ($search !== ''): ?>
                                <a class="admin-button admin-button-secondary" href="students.php">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Student number</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Outstanding balance</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($pageError !== ''): ?>
                                    <tr><td colspan="6">Student results are unavailable.</td></tr>
                                <?php elseif ($students === []): ?>
                                    <tr><td colspan="6">No students found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($students as $student): ?>
                                        <?php $studentId = (int)$student['id']; ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$student['student_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(trim((string)$student['first_name'] . ' ' . (string)$student['last_name']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$student['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars(peso($balances[(string)$studentId] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="admin-status <?= (int)$student['is_active'] === 1 ? 'admin-status-active' : 'admin-status-inactive' ?>"><?= (int)$student['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                                            <td><a href="student-view.php?id=<?= htmlspecialchars((string)$studentId, ENT_QUOTES, 'UTF-8') ?>">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="admin-pagination" aria-label="Student pages">
                            <span>Page <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars((string)$totalPages, ENT_QUOTES, 'UTF-8') ?></span>
                            <div>
                                <?php if ($page > 1): ?>
                                    <?php $previousQuery = ['page' => $page - 1, 'q' => $search]; ?>
                                    <a class="admin-button admin-button-secondary" href="students.php?<?= htmlspecialchars(http_build_query($previousQuery), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                                <?php endif; ?>
                                <?php if ($page < $totalPages): ?>
                                    <?php $nextQuery = ['page' => $page + 1, 'q' => $search]; ?>
                                    <a class="admin-button admin-button-secondary" href="students.php?<?= htmlspecialchars(http_build_query($nextQuery), ENT_QUOTES, 'UTF-8') ?>">Next</a>
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
