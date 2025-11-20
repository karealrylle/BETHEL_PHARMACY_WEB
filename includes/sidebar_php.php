<?php
// Determine which navigation items to show based on role
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// Define navigation items
$admin_nav = [
    ['href' => 'admin_dashboard.php', 'icon' => 'dashboard.png', 'label' => 'Dashboard'],
    ['href' => 'inventory.php', 'icon' => 'inventory.png', 'label' => 'Inventory'],
    ['href' => 'medicine_management.php', 'icon' => 'management.png', 'label' => 'Medicine Management'],
    ['href' => 'reports.php', 'icon' => 'reports.png', 'label' => 'Reports'],
    ['href' => 'staff_management.php', 'icon' => 'staff.png', 'label' => 'Staff Management'],
    ['href' => 'notifications.php', 'icon' => 'notifs.png', 'label' => 'Notifications'],
    ['href' => 'settings.php', 'icon' => 'settings.png', 'label' => 'Settings'],
    ['href' => 'help.php', 'icon' => 'help.png', 'label' => 'Get Technical Help']
];

$staff_nav = [
    ['href' => 'staff_dashboard.php', 'icon' => 'dashboard.png', 'label' => 'Dashboard'],
    ['href' => 'pos.php', 'icon' => 'dashboard.png', 'label' => 'POS'],
    ['href' => 'inventory.php', 'icon' => 'inventory.png', 'label' => 'Inventory'],
    ['href' => 'shift_report.php', 'icon' => 'reports.png', 'label' => 'Shift Report'],
    ['href' => 'notifications.php', 'icon' => 'notifs.png', 'label' => 'Notifications'],
    ['href' => 'settings.php', 'icon' => 'settings.png', 'label' => 'Settings'],
    ['href' => 'help.php', 'icon' => 'help.png', 'label' => 'Get Technical Help']
];

$nav_items = $is_admin ? $admin_nav : $staff_nav;

// Get current page filename to set active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="sidebar">
    <div class="profile-container">
        <div class="profile-image"></div>
        <div class="profile-info">
            <div class="profile-username"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <div class="profile-role"><?php echo ucfirst(htmlspecialchars($_SESSION['role'])); ?></div>
        </div>

        <div class="profile-menu" onclick="this.nextElementSibling.classList.toggle('show')">&vellip;</div>
        <div class="profile-dropdown">
            <button class="dropdown-button">View Profile</button>
            <a href="index.php" style="text-decoration: none; display: contents;">
                <button class="dropdown-button">Log out</button>
            </a>
        </div>
    </div>

    <div class="nav-buttons">
        <?php foreach ($nav_items as $item): ?>
            <a href="<?php echo $item['href']; ?>" 
               class="nav-button <?php echo ($current_page === $item['href']) ? 'active' : ''; ?>">
                <img src="assets/<?php echo $item['icon']; ?>" alt="">
                <?php echo $item['label']; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
</nav>
