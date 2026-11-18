<?php
$activePage = $activePage ?? '';
?>
<aside class="admin-sidebar">
    <a class="admin-brand" href="index.php">
        <img src="../images/LCUP.png" alt="LCUP Logo">
        <span>
            <strong>LCUPAY</strong>
            <small>REGISTRAR PORTAL</small>
        </span>
    </a>
    <nav aria-label="Admin navigation">
        <ul class="admin-nav-list">
            <li class="<?= $activePage === 'dashboard' ? 'active' : '' ?>"><a href="index.php">Dashboard</a></li>
            <li class="<?= $activePage === 'students' ? 'active' : '' ?>"><a href="students.php">Students</a></li>
            <li class="<?= $activePage === 'fee-types' ? 'active' : '' ?>"><a href="fee-types.php">Fee types and charges</a></li>
            <li class="<?= $activePage === 'payments' ? 'active' : '' ?>"><a href="payments.php">Payments</a></li>
            <li><a href="logout.php">Log out</a></li>
        </ul>
    </nav>
</aside>
