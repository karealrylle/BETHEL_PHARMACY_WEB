<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header("Location: index.php");
    exit();
}

// Add user data fetch
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$user_id = $_SESSION['user_id'];
$query = $conn->prepare("SELECT * FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$user = $query->get_result()->fetch_assoc();

date_default_timezone_set('Asia/Manila');
$current_date = date('l, F j, Y');
$current_time = date('h:i A');

$hour = (int)date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = "Good Morning";
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}

// Database connection
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed");

// Get total sales today
$sales_today_query = "SELECT COALESCE(SUM(total_amount), 0) as total_sales 
                      FROM sales 
                      WHERE DATE(sale_date) = CURDATE()";
$sales_today_result = $conn->query($sales_today_query);
$sales_today_row = $sales_today_result->fetch_assoc();
$total_sales_today = '₱' . number_format($sales_today_row['total_sales'], 2);

// 1. Count products below reorder level
$reorder_query = "SELECT p.product_id, p.reorder_level,
                  COALESCE(SUM(CASE WHEN pb.status = 'available' 
                         AND pb.expiry_date > CURDATE() 
                         AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
                         THEN pb.quantity ELSE 0 END), 0) as current_stock
                  FROM products p 
                  LEFT JOIN product_batches pb ON p.product_id = pb.product_id 
                  GROUP BY p.product_id, p.reorder_level
                  HAVING current_stock < p.reorder_level";
$reorder_result = $conn->query($reorder_query);
$needs_reorder = $reorder_result->num_rows;

// 2. Count products that are completely out of stock
$out_of_stock_query = "SELECT p.product_id,
                       COALESCE(SUM(CASE WHEN pb.status = 'available' 
                              AND pb.expiry_date > CURDATE() 
                              AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
                              THEN pb.quantity ELSE 0 END), 0) as current_stock
                       FROM products p 
                       LEFT JOIN product_batches pb ON p.product_id = pb.product_id 
                       GROUP BY p.product_id
                       HAVING current_stock = 0";
$out_of_stock_result = $conn->query($out_of_stock_query);
$out_of_stock = $out_of_stock_result->num_rows;

    // 3. Count batches that are expired or near-expiry (< 12 months)
    $expired_query = "SELECT COUNT(*) as count 
                    FROM product_batches 
                    WHERE quantity > 0 
                    AND (expiry_date < CURDATE() 
                        OR DATEDIFF(expiry_date, CURDATE()) <= 365)";
    $expired_result = $conn->query($expired_query);
    $expired_row = $expired_result->fetch_assoc();
    $expired = $expired_row['count'];

// 4. Count products expiring soon (between 12-18 months) for warning level
$expiring_soon_query = "SELECT COUNT(DISTINCT pb.product_id) as count
                        FROM product_batches pb
                        WHERE pb.quantity > 0
                        AND DATEDIFF(pb.expiry_date, CURDATE()) BETWEEN 365 AND 545";
$expiring_soon_result = $conn->query($expiring_soon_query);
$expiring_soon_row = $expiring_soon_result->fetch_assoc();
$expiring_soon = $expiring_soon_row['count'];

// 5. Count products with low stock (1-50 units)
$low_stock_query = "SELECT p.product_id,
                    COALESCE(SUM(CASE WHEN pb.status = 'available' 
                           AND pb.expiry_date > CURDATE() 
                           AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
                           THEN pb.quantity ELSE 0 END), 0) as current_stock
                    FROM products p 
                    LEFT JOIN product_batches pb ON p.product_id = pb.product_id 
                    GROUP BY p.product_id
                    HAVING current_stock BETWEEN 1 AND 50";
$low_stock_result = $conn->query($low_stock_query);
$low_stock = $low_stock_result->num_rows;

// Determine overall inventory status
if ($expired > 0 || $out_of_stock > 0) {
    $inventory_status = "Critical";
    $inventory_color = "red";
} elseif ($expiring_soon > 0 || $low_stock > 0) {
    $inventory_status = "Warning";
    $inventory_color = "orange";
} else {
    $inventory_status = "Good";
    $inventory_color = "green";
}

// 6. Get recent activities - CONSOLIDATED QUERY
$recent_activities = [];

$activity_query = "SELECT 
    'batch' as activity_type,
    bm.movement_type,
    bm.quantity,
    bm.movement_date,
    bm.notes,
    p.product_name,
    p.category,
    pb.batch_number,
    u.username,
    NULL as sale_id,
    NULL as total_amount,
    NULL as items_count,
    NULL as payment_method
FROM batch_movements bm
JOIN product_batches pb ON bm.batch_id = pb.batch_id
JOIN products p ON pb.product_id = p.product_id
LEFT JOIN users u ON bm.performed_by = u.id
WHERE bm.movement_type IN ('restock', 'expiry', 'damage')

UNION ALL

SELECT 
    'sale' as activity_type,
    NULL as movement_type,
    NULL as quantity,
    s.sale_date as movement_date,
    NULL as notes,
    NULL as product_name,
    NULL as category,
    NULL as batch_number,
    u.username,
    s.sale_id,
    s.total_amount,
    COUNT(si.sale_item_id) as items_count,
    s.payment_method
FROM sales s
JOIN users u ON s.user_id = u.id
JOIN sale_items si ON s.sale_id = si.sale_id
GROUP BY s.sale_id, s.sale_date, s.total_amount, s.payment_method, u.username

ORDER BY movement_date DESC
LIMIT 6";

$result = $conn->query($activity_query);
while ($row = $result->fetch_assoc()) {
    $minutes = (time() - strtotime($row['movement_date'])) / 60;
    
    if ($minutes < 60) {
        $time_ago = floor($minutes) . ' mins ago';
    } elseif ($minutes < 1440) {
        $time_ago = floor($minutes / 60) . ' hrs ago';
    } else {
        $time_ago = floor($minutes / 1440) . ' days ago';
    }
    
    if ($row['activity_type'] == 'batch') {
        $action = $row['movement_type'] == 'restock' ? 'Updated Stock' : 
                 ($row['movement_type'] == 'expiry' ? 'Disposed Expired Batch' : 'Disposed Damaged Batch');
        
        $recent_activities[] = [
            'type' => 'batch',
            'user' => $row['username'] ?: $_SESSION['username'],
            'action' => $action,
            'time' => $time_ago,
            'product_name' => $row['product_name'],
            'category' => $row['category'],
            'quantity' => abs($row['quantity']),
            'batch_number' => $row['batch_number']
        ];
    } else {
        $recent_activities[] = [
            'type' => 'sale',
            'user' => $row['username'],
            'action' => 'Processed Sale',
            'time' => $time_ago,
            'sale_id' => $row['sale_id'],
            'total_amount' => number_format($row['total_amount'], 2),
            'items_count' => $row['items_count'],
            'payment_method' => ucfirst($row['payment_method'])
        ];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/management.css">
    <link rel="stylesheet" href="css/tables.css">
    <link rel="stylesheet" href="css/modals.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/inventory.css?v=<?php echo time(); ?>">
    <meta http-equiv="refresh" content="60">
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
            <a href="staff_dashboard.php" class="nav-button active"><img src="assets/dashboard.png" alt="">Dashboard</a>
            <a href="pos.php" class="nav-button"><img src="assets/dashboard.png" alt="">POS</a>
            <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
            <a href="shift_report.php" class="nav-button"><img src="assets/reports.png" alt="">Shift Report</a>
            <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
            <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
            <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
        </div>
        
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>

<div class="content">
    <div class="content-header">
        <h2><?php echo $greeting; ?>, <?php echo $_SESSION['username']; ?></h2>
        
        <div class="clock-buttons">
            <div id="clockStatus" class="clock-status" style="display: none;">
                <span class="clock-status-dot"></span>
                <span>On Shift</span>
            </div>
            <button id="clockInBtn" class="clock-btn clock-in-btn" onclick="clockIn()">Clock In</button>
            <button id="clockOutBtn" class="clock-btn clock-out-btn" onclick="clockOut()" style="display: none;">Clock Out</button>
        </div>
    </div>

    <div class="inventory-boxes">
        <div class="inventory-box yellow">
            <div class="inventory-box-inner">
                <img src="assets/money.png" alt="">
                <div class="inventory-box-number"><?php echo $total_sales_today; ?></div>
                <div class="inventory-box-label">Total Sales Today</div>
            </div>
            <a href="shift_report.php" class="inventory-box-view">View Details »</a>
        </div>

        <div class="inventory-box <?php echo $inventory_color; ?>">
            <div class="inventory-box-inner">
                <?php 
                if ($inventory_status == "Critical") {
                    echo '<img src="assets/critical.png" alt="">';
                } elseif ($inventory_status == "Warning") {
                    echo '<img src="assets/warning.png" alt="">';
                } else {
                    echo '<img src="assets/health.png" alt="">';
                }
                ?>
                <div class="inventory-box-number"><?php echo $inventory_status; ?></div>
                <div class="inventory-box-label">Inventory Status</div>
            </div>
            <a href="inventory.php" class="inventory-box-view">Resolve Now »</a>
        </div>
        
      <div class="inventory-box orange">
          <div class="inventory-box-inner">
              <img src="assets/warning.png" alt="">
              <div class="inventory-box-number"><?php echo $out_of_stock; ?></div>
              <div class="inventory-box-label">Out of Stock</div>
          </div>
          <a href="update_stock.php?stock_level=out" class="inventory-box-view">Resolve Now »</a>
      </div>
        
        <div class="inventory-box red">
            <div class="inventory-box-inner">
                <img src="assets/redbin.png" alt="">
                <div class="inventory-box-number"><?php echo $expired; ?></div>
                <div class="inventory-box-label">Items To Dispose</div>
            </div>
            <a href="update_stock.php?expiry_status=expiring" class="inventory-box-view">Resolve Now »</a>
        </div>
    </div>

    <div class="dashboard-container">
        <div class="dashboard-left">
            <div class="sales-history">
                <div class="sales-header">Sales History</div>
                <div class="sales-content">
                    <?php
                    // Reopen connection for sales query
                    $conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
                    $sales_query = "SELECT s.sale_id, s.sale_date, s.total_amount, s.payment_method,
                                   COUNT(si.sale_item_id) as item_count
                                   FROM sales s
                                   LEFT JOIN sale_items si ON s.sale_id = si.sale_id
                                   WHERE DATE(s.sale_date) = CURDATE()
                                   GROUP BY s.sale_id
                                   ORDER BY s.sale_date DESC
                                   LIMIT 10";
                    $sales_result = $conn->query($sales_query);
                    
                    if ($sales_result && $sales_result->num_rows > 0):
                    ?>
                        <table class="sales-table">
                            <thead>
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Time</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($sale = $sales_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>TXN-<?php echo str_pad($sale['sale_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo date('h:i A', strtotime($sale['sale_date'])); ?></td>
                                        <td><?php echo $sale['item_count']; ?></td>
                                        <td>₱<?php echo number_format($sale['total_amount'], 2); ?></td>
                                        <td><?php echo ucfirst($sale['payment_method']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="text-align: center; padding: 50px; color: #7f8c8d;">
                            <p style="font-size: 16px; margin: 0;">No sales today</p>
                        </div>
                    <?php 
                    endif;
                    $conn->close();
                    ?>
                </div>
            </div>
        </div>

        <div class="dashboard-right">
            
            <div class="recent-activity-section" >
                <div class="recent-activity-title">Activity Log</div>
                <div class="recent-activity-grid">
                    <?php if (empty($recent_activities)): ?>
                        <div class="activity-card">
                            <div class="activity-card-header">
                                <span class="activity-user">No recent activity</span>
                                <span class="activity-time">—</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-card">
                                <div class="activity-card-header">
                                    <span class="activity-user"><?php echo htmlspecialchars($activity['user']); ?> - <?php echo htmlspecialchars($activity['action']); ?></span>
                                    <span class="activity-time"><?php echo $activity['time']; ?></span>
                                </div>
                                <div class="activity-details">
                                    <?php if ($activity['type'] == 'batch'): ?>
                                        <div>
                                            <div class="activity-detail-label">Product</div>
                                            <div class="activity-detail-value"><?php echo htmlspecialchars($activity['product_name']); ?></div>
                                        </div>
                                        <div>
                                            <div class="activity-detail-label">Batch</div>
                                            <div class="activity-detail-value"><?php echo htmlspecialchars($activity['batch_number']); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <div>
                                            <div class="activity-detail-label">Transaction</div>
                                            <div class="activity-detail-value">Sale #<?php echo $activity['sale_id']; ?></div>
                                        </div>
                                        <div>
                                            <div class="activity-detail-label">Amount (<?php echo $activity['payment_method']; ?>)</div>
                                            <div class="activity-detail-value">₱<?php echo $activity['total_amount']; ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Check clock status on page load
window.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded, checking clock status...');
    checkClockStatus();
});

function checkClockStatus() {
    fetch('includes/clock_inout.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=check_status'
    })
    .then(res => res.json())
    .then(data => {
        console.log('Clock status:', data);
        
        const clockInBtn = document.getElementById('clockInBtn');
        const clockOutBtn = document.getElementById('clockOutBtn');
        const clockStatus = document.getElementById('clockStatus');
        
        if (data.clocked_in) {
            clockInBtn.style.display = 'none';
            clockOutBtn.style.display = 'block';
            clockStatus.style.display = 'flex';
            console.log('User is clocked in');
        } else {
            clockInBtn.style.display = 'block';
            clockOutBtn.style.display = 'none';
            clockStatus.style.display = 'none';
            console.log('User is NOT clocked in');
        }
    })
    .catch(error => {
        console.error('Error checking clock status:', error);
    });
}

function clockIn() {
    if (!confirm('Clock in now?')) return;
    
    const btn = document.getElementById('clockInBtn');
    btn.disabled = true;
    btn.textContent = 'Clocking in...';
    
    fetch('includes/clock_inout.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clock_in'
    })
    .then(res => res.json())
    .then(data => {
        console.log('Clock in response:', data);
        alert(data.message);
        
        if (data.success) {
            // Immediately update UI based on response
            document.getElementById('clockInBtn').style.display = 'none';
            document.getElementById('clockOutBtn').style.display = 'block';
            document.getElementById('clockStatus').style.display = 'flex';
        } else {
            btn.disabled = false;
            btn.textContent = 'Clock In';
        }
    })
    .catch(error => {
        console.error('Error clocking in:', error);
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Clock In';
    });
}
function clockOut() {
    if (!confirm('Clock out now? This will end your shift.')) return;
    
    const btn = document.getElementById('clockOutBtn');
    btn.disabled = true;
    btn.textContent = 'Clocking out...';
    
    fetch('includes/clock_inout.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clock_out'
    })
    .then(res => res.json())
    .then(data => {
        console.log('Clock out response:', data);
        alert(data.message);
        btn.disabled = false;
        btn.textContent = 'Clock Out';
        if (data.success) {
            checkClockStatus();
        }
    })
    .catch(error => {
        console.error('Error clocking out:', error);
        btn.disabled = false;
        btn.textContent = 'Clock Out';
    });
}
</script>

</body>
</html>