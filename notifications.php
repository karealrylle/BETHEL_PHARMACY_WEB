<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

// Add this to pages like admin_dashboard.php, staff_dashboard.php, inventory.php, etc.
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$user_id = $_SESSION['user_id'];
$query = $conn->prepare("SELECT * FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$user = $query->get_result()->fetch_assoc();

$conn->close();

date_default_timezone_set('Asia/Manila');
$current_date = date('l, F j, Y');
$current_time = date('h:i A');

$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Get filter and search parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Build notifications array from database
$all_notifications = [];

// 1. EXPIRED BATCHES (Critical)
$expired_query = "SELECT 
    pb.batch_id,
    pb.batch_number,
    p.product_id,
    p.product_name,
    p.category,
    pb.quantity,
    pb.expiry_date,
    DATEDIFF(pb.expiry_date, CURDATE()) as days_until_expiry,
    'critical' as priority,
    pb.supplier_name,
    pb.received_date,
    'expired' as type
FROM product_batches pb
JOIN products p ON pb.product_id = p.product_id
WHERE pb.quantity > 0 
    AND pb.status = 'available'
    AND pb.expiry_date < CURDATE()";

if ($search) {
    $expired_query .= " AND (p.product_name LIKE '%$search%' OR pb.batch_number LIKE '%$search%')";
}

$expired_result = $conn->query($expired_query);
if ($expired_result) {
    while ($row = $expired_result->fetch_assoc()) {
        $all_notifications[] = $row;
    }
}

// 2. CRITICAL EXPIRY (≤7 days - Critical)
$critical_expiry_query = "SELECT 
    pb.batch_id,
    pb.batch_number,
    p.product_id,
    p.product_name,
    p.category,
    pb.quantity,
    pb.expiry_date,
    DATEDIFF(pb.expiry_date, CURDATE()) as days_until_expiry,
    'critical' as priority,
    pb.supplier_name,
    pb.received_date,
    'critical_expiry' as type
FROM product_batches pb
JOIN products p ON pb.product_id = p.product_id
WHERE pb.quantity > 0 
    AND pb.status = 'available'
    AND pb.expiry_date >= CURDATE()
    AND DATEDIFF(pb.expiry_date, CURDATE()) <= 7";

if ($search) {
    $critical_expiry_query .= " AND (p.product_name LIKE '%$search%' OR pb.batch_number LIKE '%$search%')";
}

$critical_expiry_result = $conn->query($critical_expiry_query);
if ($critical_expiry_result) {
    while ($row = $critical_expiry_result->fetch_assoc()) {
        $all_notifications[] = $row;
    }
}

// 3. OUT OF STOCK PRODUCTS (Critical)
$out_stock_query = "SELECT 
    NULL as batch_id,
    NULL as batch_number,
    p.product_id,
    p.product_name,
    p.category,
    0 as quantity,
    NULL as expiry_date,
    NULL as days_until_expiry,
    'critical' as priority,
    NULL as supplier_name,
    NULL as received_date,
    'out-of-stock' as type,
    p.reorder_level
FROM products p
WHERE p.product_id NOT IN (
    SELECT DISTINCT product_id 
    FROM product_batches 
    WHERE status = 'available' 
    AND quantity > 0
    AND expiry_date > CURDATE()
)";

if ($search) {
    $out_stock_query .= " AND p.product_name LIKE '%$search%'";
}

$out_stock_result = $conn->query($out_stock_query);
if ($out_stock_result) {
    while ($row = $out_stock_result->fetch_assoc()) {
        $all_notifications[] = $row;
    }
}

// 4. URGENT EXPIRY (8-30 days - Urgent)
$urgent_expiry_query = "SELECT 
    pb.batch_id,
    pb.batch_number,
    p.product_id,
    p.product_name,
    p.category,
    pb.quantity,
    pb.expiry_date,
    DATEDIFF(pb.expiry_date, CURDATE()) as days_until_expiry,
    'urgent' as priority,
    pb.supplier_name,
    pb.received_date,
    'urgent_expiry' as type
FROM product_batches pb
JOIN products p ON pb.product_id = p.product_id
WHERE pb.quantity > 0 
    AND pb.status = 'available'
    AND pb.expiry_date >= CURDATE()
    AND DATEDIFF(pb.expiry_date, CURDATE()) BETWEEN 8 AND 30";

if ($search) {
    $urgent_expiry_query .= " AND (p.product_name LIKE '%$search%' OR pb.batch_number LIKE '%$search%')";
}

$urgent_expiry_result = $conn->query($urgent_expiry_query);
if ($urgent_expiry_result) {
    while ($row = $urgent_expiry_result->fetch_assoc()) {
        $all_notifications[] = $row;
    }
}

// 5. TO DISPOSE BATCHES (31-365 days - Urgent)
$dispose_query = "SELECT 
    pb.batch_id,
    pb.batch_number,
    p.product_id,
    p.product_name,
    p.category,
    pb.quantity,
    pb.expiry_date,
    DATEDIFF(pb.expiry_date, CURDATE()) as days_until_expiry,
    'urgent' as priority,
    pb.supplier_name,
    pb.received_date,
    'to_dispose' as type
FROM product_batches pb
JOIN products p ON pb.product_id = p.product_id
WHERE pb.quantity > 0 
    AND pb.status = 'available'
    AND pb.expiry_date >= CURDATE()
    AND DATEDIFF(pb.expiry_date, CURDATE()) BETWEEN 31 AND 365";

if ($search) {
    $dispose_query .= " AND (p.product_name LIKE '%$search%' OR pb.batch_number LIKE '%$search%')";
}

$dispose_result = $conn->query($dispose_query);
if ($dispose_result) {
    while ($row = $dispose_result->fetch_assoc()) {
        $all_notifications[] = $row;
    }
}

// 6. LOW STOCK PRODUCTS (Warning)
$low_stock_query = "SELECT 
    p.product_id,
    p.product_name,
    p.category,
    COALESCE(SUM(pb.quantity), 0) as total_stock,
    p.reorder_level,
    'warning' as priority,
    'low-stock' as type
FROM products p
LEFT JOIN product_batches pb ON p.product_id = pb.product_id 
    AND pb.status = 'available' 
    AND pb.expiry_date > CURDATE()
    AND pb.quantity > 0
GROUP BY p.product_id, p.product_name, p.category, p.reorder_level
HAVING total_stock > 0 AND total_stock <= p.reorder_level";

if ($search) {
    $low_stock_query .= " AND p.product_name LIKE '%$search%'";
}

$low_stock_result = $conn->query($low_stock_query);
if ($low_stock_result) {
    while ($row = $low_stock_result->fetch_assoc()) {
        // Format for consistency with other notifications
        $formatted_row = [
            'batch_id' => null,
            'batch_number' => null,
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'category' => $row['category'],
            'quantity' => $row['total_stock'],
            'expiry_date' => null,
            'days_until_expiry' => null,
            'priority' => $row['priority'],
            'supplier_name' => null,
            'received_date' => null,
            'type' => $row['type'],
            'reorder_level' => $row['reorder_level']
        ];
        $all_notifications[] = $formatted_row;
    }
}

// Filter notifications if not 'all'
if ($filter != 'all') {
    $all_notifications = array_filter($all_notifications, function($notif) use ($filter) {
        return $notif['priority'] == $filter;
    });
}

// Sort by priority and urgency
usort($all_notifications, function($a, $b) {
    $priority_order = ['critical' => 0, 'urgent' => 1, 'warning' => 2];
    $a_priority = $priority_order[$a['priority']] ?? 999;
    $b_priority = $priority_order[$b['priority']] ?? 999;
    
    if ($a_priority == $b_priority) {
        // For same priority, sort by days until expiry (soonest first)
        if (isset($a['days_until_expiry']) && isset($b['days_until_expiry'])) {
            return $a['days_until_expiry'] - $b['days_until_expiry'];
        }
        return 0;
    }
    return $a_priority - $b_priority;
});

// Count notifications by priority
$counts = ['critical' => 0, 'urgent' => 0, 'warning' => 0];
foreach ($all_notifications as $notif) {
    if (isset($counts[$notif['priority']])) {
        $counts[$notif['priority']]++;
    }
}
$total_count = count($all_notifications);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Notifications</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/notifications.css">
</head>
<body>
    <header class="header">
        <img src="assets/bethel_logo.png" alt="Bethel Pharmacy" class="logo">
        <div class="datetime">
            <div><?php echo $current_date; ?></div>
            <div><?php echo $current_time; ?></div>
        </div>
    </header>

    <nav class="sidebar">
        <div class="profile-container">
            <div class="profile-image">
                <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                    <img src="<?php echo $user['profile_picture']; ?>" alt="Profile">
                <?php else: ?>
                    <img src="assets/user.png" alt="User Profile">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <div class="profile-username"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                <div class="profile-role"><?php echo ucfirst($_SESSION['role']); ?></div>
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
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="admin_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="medicine_management.php" class="nav-button"><img src="assets/management.png" alt="">Medicine Management</a>
                <a href="reports.php" class="nav-button"><img src="assets/reports.png" alt="">Reports</a>
                <a href="staff_management.php" class="nav-button"><img src="assets/staff.png" alt="">Staff Management</a>
                <a href="notifications.php" class="nav-button active"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php else: ?>
                <a href="staff_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
                <a href="pos.php" class="nav-button"><img src="assets/dashboard.png" alt="">POS</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="shift_report.php" class="nav-button"><img src="assets/reports.png" alt="">Shift Report</a>
                <a href="notifications.php" class="nav-button active"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php endif; ?>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>

    <div class="notifications-container">
        <div class="notifications-header">
            <h2>Notifications</h2>
            <div class="header-actions">
                <form method="GET" class="search-box">
                    <input type="hidden" name="filter" value="<?php echo $filter; ?>">
                    <input type="text" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">
                        <img src="assets/search.png" alt="Search">
                    </button>
                </form>
            </div>
        </div>

        <div class="notification-tabs">
            <button class="notif-tab all <?php echo $filter == 'all' ? 'active' : ''; ?>" 
                    onclick="filterNotifications('all')">
                All (<?php echo $total_count; ?>)
            </button>
            <button class="notif-tab critical <?php echo $filter == 'critical' ? 'active' : ''; ?>" 
                    onclick="filterNotifications('critical')">
                Critical (<?php echo $counts['critical']; ?>)
            </button>
            <button class="notif-tab urgent <?php echo $filter == 'urgent' ? 'active' : ''; ?>" 
                    onclick="filterNotifications('urgent')">
                Urgent (<?php echo $counts['urgent']; ?>)
            </button>
            <button class="notif-tab warning <?php echo $filter == 'warning' ? 'active' : ''; ?>" 
                    onclick="filterNotifications('warning')">
                Low Stock (<?php echo $counts['warning']; ?>)
            </button>
        </div>

        <div class="notifications-list">
            <?php if (count($all_notifications) > 0): ?>
                <?php foreach($all_notifications as $notif): 
                    $priority = $notif['priority'];
                    $type = $notif['type'];
                    $days = $notif['days_until_expiry'] ?? null;
                    
                    // Generate notification details based on type and priority
                    if ($type == 'expired') {
                        $title = "⚠️ EXPIRED PRODUCT";
                        $message = $notif['product_name'] . " batch " . $notif['batch_number'] . " has EXPIRED. Dispose immediately!";
                        $badge_class = "critical";
                    } elseif ($type == 'critical_expiry') {
                        $title = "🔴 CRITICAL EXPIRY";
                        $message = $notif['product_name'] . " batch " . $notif['batch_number'] . " expires in " . $days . " days";
                        $badge_class = "critical";
                    } elseif ($type == 'out-of-stock') {
                        $title = "❌ OUT OF STOCK";
                        $message = $notif['product_name'] . " is completely out of stock";
                        $badge_class = "critical";
                    } elseif ($type == 'urgent_expiry') {
                        $title = "🟠 URGENT EXPIRY";
                        $message = $notif['product_name'] . " batch " . $notif['batch_number'] . " expires in " . $days . " days";
                        $badge_class = "urgent";
                    } elseif ($type == 'to_dispose') {
                        $title = "🟠 TO DISPOSE SOON";
                        $message = $notif['product_name'] . " batch " . $notif['batch_number'] . " expires in " . $days . " days";
                        $badge_class = "urgent";
                    } elseif ($type == 'low-stock') {
                        $title = "🟡 LOW STOCK";
                        $message = $notif['product_name'] . " has only " . $notif['quantity'] . " units left (reorder level: " . $notif['reorder_level'] . ")";
                        $badge_class = "warning";
                    } else {
                        continue;
                    }
                ?>
                <div class="notification-card">
                    <div class="notif-card-header">
                        <div class="notif-title-row">
                            <h3 class="notif-title"><?php echo $title; ?></h3>
                            <span class="notif-badge <?php echo $badge_class; ?>">
                                <?php echo strtoupper($priority); ?>
                            </span>
                        </div>
                    </div>
                    
                    <p class="notif-message"><?php echo $message; ?></p>
                    
                    <div class="notif-details">
                        <?php if ($notif['quantity'] !== null && $notif['quantity'] > 0): ?>
                        <div class="notif-detail-item">
                            <span class="notif-detail-label">Stock:</span>
                            <span class="notif-detail-value"><?php echo $notif['quantity']; ?> units</span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($notif['batch_number']): ?>
                        <div class="notif-detail-item">
                            <span class="notif-detail-label">Batch:</span>
                            <span class="notif-detail-value"><?php echo $notif['batch_number']; ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($notif['expiry_date']): ?>
                        <div class="notif-detail-item">
                            <span class="notif-detail-label">Expiry:</span>
                            <span class="notif-detail-value"><?php echo date('M d, Y', strtotime($notif['expiry_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (isset($notif['reorder_level'])): ?>
                        <div class="notif-detail-item">
                            <span class="notif-detail-label">Reorder Level:</span>
                            <span class="notif-detail-value"><?php echo $notif['reorder_level']; ?> units</span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($notif['category']): ?>
                        <div class="notif-detail-item">
                            <span class="notif-detail-label">Category:</span>
                            <span class="notif-detail-value"><?php echo $notif['category']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notif-actions">
                        <button class="notif-action-btn primary" onclick="viewProduct(<?php echo $notif['product_id']; ?>)">
                            View Product
                        </button>
                        <?php if (in_array($type, ['expired', 'critical_expiry', 'to_dispose'])): ?>
                        <button class="notif-action-btn secondary" onclick="handleExpired(<?php echo $notif['batch_id'] ?? $notif['product_id']; ?>)">
                            Dispose Item
                        </button>
                        <?php elseif ($type == 'out-of-stock' || $type == 'low-stock'): ?>
                        <button class="notif-action-btn secondary" onclick="reorderProduct(<?php echo $notif['product_id']; ?>)">
                            Reorder
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-notifications">
                    <p>✅ No notifications found. All products are in good condition!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function filterNotifications(filter) {
        const urlParams = new URLSearchParams(window.location.search);
        const search = urlParams.get('search') || '';
        window.location.href = `notifications.php?filter=${filter}${search ? '&search=' + encodeURIComponent(search) : ''}`;
    }

    function viewProduct(productId) {
        window.location.href = `medicine_management.php?product_id=${productId}`;
    }

    function handleExpired(batchId) {
        if (confirm('Mark this batch as disposed?')) {
            // AJAX call to mark as disposed would go here
            alert('Batch marked as disposed. This would update the database in a real implementation.');
        }
    }

    function reorderProduct(productId) {
        window.location.href = `inventory.php?reorder_product=${productId}`;
    }
    </script>
</body>
</html>