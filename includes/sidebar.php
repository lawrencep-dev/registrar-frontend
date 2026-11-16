<div class="side-nav">
    <div class="logo-side-nav-container">
        <img src="../images/LCUP.png" alt="LCUP Logo" class="nav-logo-image">
        <div class="logo-header-text-container">
            <h3 class="lcup-text-nav-header">LCUPAY</h3>
            <p class="lcup-text-nav-supporting-text">STUDENT PORTAL</p>
        </div>
    </div>
    <div class="container-ul-footer">
        <ul class="list-nav">
            <li class="nav-name<?= htmlspecialchars($activePage, ENT_QUOTES, 'UTF-8') === 'dashboard' ? ' active' : '' ?>"><a href="../home/home.php"><i class="ti ti-category-2"></i> Dashboard</a></li>
            <li class="nav-name<?= htmlspecialchars($activePage, ENT_QUOTES, 'UTF-8') === 'my-account' ? ' active' : '' ?>"><a href="../my-account/my-account.php"><i class="ti ti-calculator"></i> My Account</a></li>
            <li class="nav-name<?= htmlspecialchars($activePage, ENT_QUOTES, 'UTF-8') === 'payments' ? ' active' : '' ?>"><a href="../payment/payment.php"><i class="ti ti-cash-coin"></i> Payments</a></li>
            <li class="nav-name<?= htmlspecialchars($activePage, ENT_QUOTES, 'UTF-8') === 'transaction-history' ? ' active' : '' ?>"><a href="../transaction-history/transaction-history.php"><i class="ti ti-receipt"></i> Transaction History</a></li>
            <li class="nav-name"><a href="../my-account/my-account.php"><i class="ti ti-settings"></i> Settings</a></li>
            <li class="nav-name"><a href="../logout.php"><i class="ti ti-logout"></i> Log out</a></li>
        </ul>
        <footer class="nav-footer">
            <p class="help-support-text">HELP & SUPPORT</p>
            <p class="contact-text">Need assistance? Contact us</p>
        </footer>
    </div>
</div>