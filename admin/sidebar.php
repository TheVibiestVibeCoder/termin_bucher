<?php
// Determine current page for active state
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<aside class="admin-sidebar">
    <div class="sidebar-logo">
        <a href="dashboard.php">Disinfo Consulting</a>
        <small>Workshop-Admin</small>
    </div>
    <ul class="admin-nav">
        <li>
            <a href="dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
        </li>
        <li>
            <a href="workshops.php" class="<?= $currentPage === 'workshops' || $currentPage === 'workshop-edit' ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                Workshops
            </a>
        </li>
        <li>
            <a href="bookings.php" class="<?= $currentPage === 'bookings' ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Buchungen
            </a>
        </li>
        <li style="margin-top:2rem;">
            <a href="../index.php" style="font-size:0.8rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                Website ansehen
            </a>
        </li>
        <li>
            <form method="POST" action="index.php" style="display:none;">
                <?= csrf_field() ?>
                <input type="hidden" name="logout" value="1">
            </form>
            <a href="#" style="font-size:0.8rem;color:#e74c3c;" onclick="this.previousElementSibling.submit(); return false;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Abmelden
            </a>
        </li>
    </ul>
</aside>
