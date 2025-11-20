<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
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
if ($conn->connect_error) die("Connection failed");

$user_id = $_SESSION['user_id'];

// Get date filter
$selected_date = isset($_GET['shift_date']) ? $_GET['shift_date'] : date('Y-m-d');

// Get shift for selected date
$shift_query = $conn->prepare("
    SELECT 
        ss.*,
        u.username
    FROM staff_shifts ss
    JOIN users u ON ss.user_id = u.id
    WHERE ss.user_id = ? AND ss.shift_date = ?
    ORDER BY ss.clock_in DESC
    LIMIT 1
");
$shift_query->bind_param("is", $user_id, $selected_date);
$shift_query->execute();
$shift_result = $shift_query->get_result();
$shift = $shift_result->fetch_assoc();

// If shift exists, calculate real-time stats
if ($shift) {
    $shift_id = $shift['shift_id'];
    $is_ongoing = ($shift['status'] == 'active');
    
    // Get real-time sales data
    $sales_query = $conn->prepare("
        SELECT 
            COALESCE(SUM(s.total_amount), 0) as total_sales,
            COUNT(s.sale_id) as transaction_count,
            COALESCE(SUM(CASE WHEN s.payment_method = 'cash' THEN s.total_amount ELSE 0 END), 0) as cash_sales,
            COALESCE(SUM(CASE WHEN s.payment_method = 'gcash' THEN s.total_amount ELSE 0 END), 0) as gcash_sales,
            COALESCE(SUM(si.quantity), 0) as items_sold
        FROM sales s
        LEFT JOIN sale_items si ON s.sale_id = si.sale_id
        WHERE s.user_id = ?
        AND s.sale_date BETWEEN ? AND ?
    ");
    
    $clock_in = $shift['clock_in'];
    $clock_out = $shift['clock_out'] ?? date('Y-m-d H:i:s');
    
    $sales_query->bind_param("iss", $user_id, $clock_in, $clock_out);
    $sales_query->execute();
    $sales_data = $sales_query->get_result()->fetch_assoc();
    
    // Calculate average transaction
    $avg_transaction = $sales_data['transaction_count'] > 0 
        ? $sales_data['total_sales'] / $sales_data['transaction_count'] 
        : 0;
    
    // Determine performance
    $performance_text = '';
    $performance_class = '';

    if ($sales_data['total_sales'] >= 3000) {
        $performance_text = 'Excellent : Above Target';
        $performance_class = 'excellent';
    } elseif ($sales_data['total_sales'] < 1000 && $sales_data['total_sales'] > 0) {
        $performance_text = 'Low : Below Target';
        $performance_class = 'low';
    } else {
        // This covers sales from 1000 to 2999 and 0 sales
        $performance_text = 'Normal : On Target';
        $performance_class = 'normal';
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - My Shift Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/reports.css">
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
            <a href="staff_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
            <a href="pos.php" class="nav-button"><img src="assets/inventory.png" alt="">POS</a>
            <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
            <a href="shift_report.php" class="nav-button active"><img src="assets/reports.png" alt="">Shift Report</a>
            <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
            <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
            <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>

    <div class="content">
        <div class="reports-header">
            <h2>Shift Report</h2>
            <div class="download-dropdown">
                <button class="download-btn" onclick="printReport()">Print Report</button>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="filter-bar">
            <form method="GET" class="date-filter-form">
                <div class="date-inputs">
                    <label>Select Date:</label>
                    <input type="date" name="shift_date" value="<?php echo $selected_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="quick-filters">
                    <label>Quick Dates Preset:</label>
                    <button type="submit" name="shift_date" value="<?php echo date('Y-m-d'); ?>" class="quick-btn">Today</button>
                    <button type="submit" name="shift_date" value="<?php echo date('Y-m-d', strtotime('yesterday')); ?>" class="quick-btn">Yesterday</button>
                    <button type="submit" name="shift_date" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>" class="quick-btn">Last Week</button>
                </div>
            </form>
        </div>

        <!-- Scrollable Shift Section -->
        <div class="staff-shifts-container">
            <?php if ($shift): ?>
                <!-- Shift Status Card -->
                <div class="shift-status-card <?php echo $is_ongoing ? 'ongoing' : 'completed'; ?>">
                    <div class="shift-status-header">
                        <div class="shift-staff-info">
                            <span class="shift-label">Staff name:</span>
                            <span class="shift-staff-name"><?php echo htmlspecialchars($shift['username']); ?></span>
                        </div>
                        <div class="shift-time-info">
                            <span class="shift-label">Shift Time:</span>
                            <span class="shift-time">
                                <?php echo date('g:i a', strtotime($shift['clock_in'])); ?> - 
                                <?php echo $shift['clock_out'] ? date('g:i a', strtotime($shift['clock_out'])) : 'N/A'; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="shift-performance">
                        <span class="shift-label">Current Performance:</span>
                        <span class="performance-status <?php echo $performance_class; ?>"><?php echo $performance_text; ?></span>
                    </div>

                    <?php if ($is_ongoing): ?>
                        <?php
                            $now = time();
                            $expected_end = strtotime($shift['clock_in']) + (8 * 3600); // 8 hours shift
                            $diff = $expected_end - $now;
                            $remaining_hours = max(0, floor($diff / 3600));
                            $remaining_minutes = max(0, floor(($diff % 3600) / 60));
                        ?>
                        <div class="ongoing-banner">
                            Shift Ongoing - <?php echo $remaining_hours; ?> hours <?php echo $remaining_minutes; ?> minutes remaining
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Shift Summary Cards -->
                <div class="summary-cards">
                    <div class="summary-card blue">
                        <div class="card-label">Average Transaction</div>
                        <div class="card-value">₱ <?php echo number_format($avg_transaction, 2); ?></div>
                    </div>
                    <div class="summary-card green">
                        <div class="card-label">Item Sold</div>
                        <div class="card-value"><?php echo $sales_data['items_sold']; ?> units</div>
                    </div>
                    <div class="summary-card orange">
                        <div class="card-label">Total Revenue</div>
                        <div class="card-value">₱<?php echo number_format($sales_data['total_sales'], 2); ?></div>
                    </div>
                    <div class="summary-card red">
                        <div class="card-label">Total Transactions</div>
                        <div class="card-value"><?php echo $sales_data['transaction_count']; ?></div>
                    </div>
                </div>

                <!-- Payment Details Container -->
                <div class="payment-details-container">
                    <div class="payment-row">
                        <div class="payment-label">Cash Sales (<?php echo $sales_data['transaction_count']; ?>):</div>
                        <div class="payment-value">₱ <?php echo number_format($sales_data['cash_sales'], 2); ?></div>
                    </div>
                    <div class="payment-row">
                        <div class="payment-label">Gcash Sales (<?php echo $sales_data['transaction_count']; ?>):</div>
                        <div class="payment-value">₱ <?php echo number_format($sales_data['gcash_sales'], 2); ?></div>
                    </div>
                    <div class="payment-row total">
                        <div class="payment-label">Total Revenue:</div>
                        <div class="payment-value">₱ <?php echo number_format($sales_data['total_sales'], 2); ?></div>
                    </div>
                </div>

            <?php else: ?>
                <div class="no-data">
                    <p>No shift found for <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
                </div>
            <?php endif; ?>
        </div>

    <?php if ($shift && $is_ongoing): ?>
    <script>
        // Auto-refresh every 60 seconds for ongoing shifts
        setTimeout(function() {
            window.location.reload();
        }, 60000);
    </script>
    <?php endif; ?>

<script>
function printReport() {
    window.print();
}
</script>

</body>
</html>