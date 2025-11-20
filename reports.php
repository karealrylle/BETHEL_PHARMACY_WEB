<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'admin') {
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

// Database connection
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed");

// Get date range from filters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$quick_filter = isset($_GET['quick_filter']) ? $_GET['quick_filter'] : 'today';

// Apply quick filters
if ($quick_filter == 'today') {
    $date_from = $date_to = date('Y-m-d');
} elseif ($quick_filter == 'this_week') {
    $date_from = date('Y-m-d', strtotime('monday this week'));
    $date_to = date('Y-m-d');
} elseif ($quick_filter == 'this_month') {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-d');
}

// Calculate previous period
$days_diff = (strtotime($date_to) - strtotime($date_from)) / 86400 + 1;
$prev_date_from = date('Y-m-d', strtotime($date_from . " -$days_diff days"));
$prev_date_to = date('Y-m-d', strtotime($date_from . " -1 day"));

// Get current period stats
$current_query = "SELECT 
    COUNT(DISTINCT s.sale_id) as total_transactions,
    COALESCE(SUM(s.total_amount), 0) as total_revenue,
    COALESCE(SUM(si.quantity), 0) as items_sold
FROM sales s
LEFT JOIN sale_items si ON s.sale_id = si.sale_id
WHERE DATE(s.sale_date) BETWEEN '$date_from' AND '$date_to'";
$current_stats = $conn->query($current_query)->fetch_assoc();

// Get previous period stats
$prev_query = "SELECT 
    COUNT(DISTINCT s.sale_id) as total_transactions,
    COALESCE(SUM(s.total_amount), 0) as total_revenue,
    COALESCE(SUM(si.quantity), 0) as items_sold
FROM sales s
LEFT JOIN sale_items si ON s.sale_id = si.sale_id
WHERE DATE(s.sale_date) BETWEEN '$prev_date_from' AND '$prev_date_to'";
$prev_stats = $conn->query($prev_query)->fetch_assoc();

// Calculate changes
function calc_change($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return (($current - $previous) / $previous) * 100;
}

$avg_trans = $current_stats['total_transactions'] > 0 ? $current_stats['total_revenue'] / $current_stats['total_transactions'] : 0;
$prev_avg = $prev_stats['total_transactions'] > 0 ? $prev_stats['total_revenue'] / $prev_stats['total_transactions'] : 0;

$avg_change = calc_change($avg_trans, $prev_avg);
$revenue_change = calc_change($current_stats['total_revenue'], $prev_stats['total_revenue']);
$items_change = calc_change($current_stats['items_sold'], $prev_stats['items_sold']);
$trans_change = calc_change($current_stats['total_transactions'], $prev_stats['total_transactions']);

// Get detailed transaction list for panel
$transactions = $conn->query("SELECT s.sale_id, s.sale_date, s.total_amount, s.payment_method, s.customer_name,
    COUNT(si.sale_item_id) as items_count,
    u.username as staff_name
FROM sales s
LEFT JOIN sale_items si ON s.sale_id = si.sale_id
LEFT JOIN users u ON s.user_id = u.id
WHERE DATE(s.sale_date) BETWEEN '$date_from' AND '$date_to'
GROUP BY s.sale_id
ORDER BY s.sale_date DESC");

// Get items sold details
$items_details = $conn->query("SELECT p.product_name, p.category, SUM(si.quantity) as total_qty, 
    SUM(si.subtotal) as total_sales
FROM sale_items si
JOIN products p ON si.product_id = p.product_id
JOIN sales s ON si.sale_id = s.sale_id
WHERE DATE(s.sale_date) BETWEEN '$date_from' AND '$date_to'
GROUP BY si.product_id
ORDER BY total_qty DESC
LIMIT 20");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Sales Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/tables.css">
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
            <a href="admin_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
            <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
            <a href="medicine_management.php" class="nav-button"><img src="assets/management.png" alt="">Medicine Management</a>
            <a href="reports.php" class="nav-button active"><img src="assets/reports.png" alt="">Reports</a>
            <a href="staff_management.php" class="nav-button"><img src="assets/staff.png" alt="">Staff Management</a>
            <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
            <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
            <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>

    <div class="content">
        <div class="reports-header">
            <h2>Reports & Analytics</h2>
                <div class="download-dropdown">
                    <button class="download-btn" onclick="printReport()">Print Report</button>
                </div>
        </div>

        <div class="report-tabs">
            <a href="reports.php" class="report-tab active">SALES REPORT</a>
            <a href="inventory_report.php" class="report-tab">INVENTORY REPORT</a>
            <a href="staff_report.php" class="report-tab">STAFF REPORTS</a>
        </div>

        <!-- Date Range Filter -->
        <div class="filter-bar">
            <form method="GET" class="date-filter-form">
                <div class="date-inputs">
                    <label>Date Range:</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" max="<?php echo date('Y-m-d'); ?>">
                    <span>to</span>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="quick-filters">
                    <label>Quick Dates Preset:</label>
                    <button type="submit" name="quick_filter" value="today" class="quick-btn <?php echo $quick_filter == 'today' ? 'active' : ''; ?>">Today</button>
                    <button type="submit" name="quick_filter" value="this_week" class="quick-btn <?php echo $quick_filter == 'this_week' ? 'active' : ''; ?>">This Week</button>
                    <button type="submit" name="quick_filter" value="this_month" class="quick-btn <?php echo $quick_filter == 'this_month' ? 'active' : ''; ?>">This Month</button>
                </div>
            </form>
        </div>

        <!-- Summary Boxes -->
        <div class="summary-boxes">
            <div class="summary-box blue" onclick="showDetails('avg')">
                <div class="summary-box-label">Average Transaction</div>
                <div class="summary-box-value">₱<?php echo number_format($avg_trans, 0); ?></div>
                <div class="summary-box-change <?php echo $avg_change >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo ($avg_change >= 0 ? '▲' : '▼'); ?> <?php echo number_format(abs($avg_change), 1); ?>% vs last period
                </div>
            </div>

            <div class="summary-box green" onclick="showDetails('revenue')">
                <div class="summary-box-label">Total Revenue</div>
                <div class="summary-box-value">₱<?php echo number_format($current_stats['total_revenue'], 2); ?></div>
                <div class="summary-box-change <?php echo $revenue_change >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo ($revenue_change >= 0 ? '▲' : '▼'); ?> <?php echo number_format(abs($revenue_change), 1); ?>% vs last period
                </div>
            </div>

            <div class="summary-box orange" onclick="showDetails('items')">
                <div class="summary-box-label">Items Sold</div>
                <div class="summary-box-value"><?php echo number_format($current_stats['items_sold']); ?></div>
                <div class="summary-box-change <?php echo $items_change >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo ($items_change >= 0 ? '▲' : '▼'); ?> <?php echo number_format(abs($items_change), 1); ?>% vs last period
                </div>
            </div>

            <div class="summary-box red" onclick="showDetails('transactions')">
                <div class="summary-box-label">Total Transaction</div>
                <div class="summary-box-value"><?php echo number_format($current_stats['total_transactions']); ?></div>
                <div class="summary-box-change <?php echo $trans_change >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo ($trans_change >= 0 ? '▲' : '▼'); ?> <?php echo number_format(abs($trans_change), 1); ?>% vs last period
                </div>
            </div>
        </div>

        <!-- Details Panel -->
        <div class="details-panel">
            <!-- Transaction Details -->
            <div id="details-transactions" class="details-content active">
                <h3>Transaction Details</h3>
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>Date/Time</th>
                            <th>Staff</th>
                            <th>Items</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($transactions->num_rows > 0): ?>
                            <?php while($trans = $transactions->fetch_assoc()): ?>
                            <tr>
                                <td>TXN-<?php echo str_pad($trans['sale_id'], 3, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($trans['sale_date'])); ?></td>
                                <td><?php echo htmlspecialchars($trans['staff_name'] ?? 'N/A'); ?></td>
                                <td><?php echo $trans['items_count']; ?></td>
                                <td>₱<?php echo number_format($trans['total_amount'], 2); ?></td>
                                <td><?php echo ucfirst($trans['payment_method']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty-state">No transactions found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Revenue Details -->
            <div id="details-revenue" class="details-content">
                <h3>Revenue Breakdown</h3>
                <div class="details-stats">
                    <div class="stat-card green">
                        <div class="stat-card-label">Total Revenue</div>
                        <div class="stat-card-value">₱<?php echo number_format($current_stats['total_revenue'], 2); ?></div>
                    </div>
                    <div class="stat-card blue">
                        <div class="stat-card-label">Average per Transaction</div>
                        <div class="stat-card-value">₱<?php echo number_format($avg_trans, 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label">Total Transactions</div>
                        <div class="stat-card-value"><?php echo number_format($current_stats['total_transactions']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Items Details -->
            <div id="details-items" class="details-content">
                <h3>Top Selling Items</h3>
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Quantity Sold</th>
                            <th>Total Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $items_details->data_seek(0);
                        if ($items_details->num_rows > 0): 
                        ?>
                            <?php while($item = $items_details->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['category']); ?></td>
                                <td><?php echo number_format($item['total_qty']); ?></td>
                                <td>₱<?php echo number_format($item['total_sales'], 2); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="empty-state">No items sold</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Average Transaction Details -->
            <div id="details-avg" class="details-content">
                <h3>Average Transaction Analysis</h3>
                <div class="details-stats">
                    <div class="stat-card blue">
                        <div class="stat-card-label">Average Amount</div>
                        <div class="stat-card-value">₱<?php echo number_format($avg_trans, 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-label">Total Transactions</div>
                        <div class="stat-card-value"><?php echo number_format($current_stats['total_transactions']); ?></div>
                    </div>
                    <div class="stat-card yellow">
                        <div class="stat-card-label">Avg Items per Transaction</div>
                        <div class="stat-card-value"><?php echo $current_stats['total_transactions'] > 0 ? number_format($current_stats['items_sold'] / $current_stats['total_transactions'], 1) : 0; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Show transaction details by default on page load
    document.addEventListener('DOMContentLoaded', function() {
        showDetails('transactions');
    });

    function showDetails(type) {
        // Remove active class from all boxes
        document.querySelectorAll('.summary-box').forEach(box => {
            box.classList.remove('active');
        });
        
        // Hide all details
        document.querySelectorAll('.details-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Show selected details
        const detailsMap = {
            'avg': 'details-avg',
            'revenue': 'details-revenue',
            'items': 'details-items',
            'transactions': 'details-transactions'
        };
        
        const detailsId = detailsMap[type];
        if (detailsId) {
            document.getElementById(detailsId).classList.add('active');
        }
        
        // Add active to clicked box
        if (event && event.currentTarget) {
            event.currentTarget.classList.add('active');
        } else {
            // Default to transactions box if no event
            const boxes = document.querySelectorAll('.summary-box');
            if (boxes[3]) boxes[3].classList.add('active');
        }
    }
    </script>

<script>
function printReport() {
    const printWindow = window.open('', '', 'height=800,width=1000');
    
    // Get all summary data 
    const summaryBoxes = document.querySelectorAll('.summary-box');
    let summaryHTML = '<div class="summary-section">';
    summaryBoxes.forEach(box => {
        const label = box.querySelector('.summary-box-label').textContent;
        const value = box.querySelector('.summary-box-value').textContent;
        const change = box.querySelector('.summary-box-change').textContent;
        const changeClass = box.querySelector('.summary-box-change').classList.contains('positive') ? 'positive' : 'negative';
        
        summaryHTML += `
            <div class="summary-item">
                <div class="summary-label">${label}</div>
                <div class="summary-value">${value}</div>
                <div class="summary-change ${changeClass}">${change}</div>
            </div>
        `;
    });
    summaryHTML += '</div>';
    
    // Get all detail sections
    let detailsHTML = '';
    
    // Transaction Details
    const transTable = document.querySelector('#details-transactions table');
    if (transTable) {
        detailsHTML += `
            <div class="report-section">
                <h3>Transaction History</h3>
                ${transTable.outerHTML}
            </div>
        `;
    }
    
    // Revenue Breakdown
    const revenueStats = document.querySelector('#details-revenue .details-stats');
    if (revenueStats) {
        const statCards = revenueStats.querySelectorAll('.stat-card');
        let revenueHTML = '<div class="stats-grid">';
        statCards.forEach(card => {
            const label = card.querySelector('.stat-card-label').textContent;
            const value = card.querySelector('.stat-card-value').textContent;
            revenueHTML += `
                <div class="stat-item">
                    <div class="stat-label">${label}</div>
                    <div class="stat-value">${value}</div>
                </div>
            `;
        });
        revenueHTML += '</div>';
        
        detailsHTML += `
            <div class="report-section">
                <h3>Revenue Breakdown</h3>
                ${revenueHTML}
            </div>
        `;
    }
    
    // Top Selling Items
    const itemsTable = document.querySelector('#details-items table');
    if (itemsTable) {
        detailsHTML += `
            <div class="report-section">
                <h3>Top Selling Items</h3>
                ${itemsTable.outerHTML}
            </div>
        `;
    }
    
    // Average Transaction Analysis
    const avgStats = document.querySelector('#details-avg .details-stats');
    if (avgStats) {
        const statCards = avgStats.querySelectorAll('.stat-card');
        let avgHTML = '<div class="stats-grid">';
        statCards.forEach(card => {
            const label = card.querySelector('.stat-card-label').textContent;
            const value = card.querySelector('.stat-card-value').textContent;
            avgHTML += `
                <div class="stat-item">
                    <div class="stat-label">${label}</div>
                    <div class="stat-value">${value}</div>
                </div>
            `;
        });
        avgHTML += '</div>';
        
        detailsHTML += `
            <div class="report-section">
                <h3>Average Transaction Analysis</h3>
                ${avgHTML}
            </div>
        `;
    }
    
    // Get date range
    const dateFrom = document.querySelector('input[name="date_from"]').value;
    const dateTo = document.querySelector('input[name="date_to"]').value;
    const dateRange = new Date(dateFrom).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + 
                     ' - ' + 
                     new Date(dateTo).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Sales Report - Bethel Pharmacy</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: 'Poppins', Arial, sans-serif; 
                    padding: 40px; 
                    color: #333;
                }
                
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    padding-bottom: 20px;
                    border-bottom: 3px solid #C92126;
                }
                
                .header h1 {
                    font-size: 28px;
                    color: #C92126;
                    margin-bottom: 5px;
                }
                
                .header h2 {
                    font-size: 22px;
                    color: #1D242E;
                    margin-bottom: 10px;
                }
                
                .date-range {
                    font-size: 14px;
                    color: #666;
                    margin-top: 10px;
                }
                
                .print-date {
                    font-size: 12px;
                    color: #999;
                    margin-top: 5px;
                }
                
                /* Summary Section */
                .summary-section {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 20px;
                    margin-bottom: 40px;
                    page-break-inside: avoid;
                }
                
                .summary-item {
                    border: 2px solid #ddd;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                }
                
                .summary-label {
                    font-size: 13px;
                    color: #666;
                    margin-bottom: 10px;
                }
                
                .summary-value {
                    font-size: 24px;
                    font-weight: 700;
                    color: #1D242E;
                    margin-bottom: 8px;
                }
                
                .summary-change {
                    font-size: 12px;
                    font-weight: 600;
                }
                
                .summary-change.positive {
                    color: #01A768;
                }
                
                .summary-change.positive::before {
                    content: '▲ ';
                }
                
                .summary-change.negative {
                    color: #F44336;
                }
                
                .summary-change.negative::before {
                    content: '▼ ';
                }
                
                /* Report Sections */
                .report-section {
                    margin-bottom: 40px;
                    page-break-inside: avoid;
                }
                
                .report-section h3 {
                    font-size: 18px;
                    color: #1D242E;
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 2px solid #ddd;
                }
                
                /* Stats Grid */
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 20px;
                    margin-bottom: 20px;
                }
                
                .stat-item {
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                    background: #f9f9f9;
                }
                
                .stat-label {
                    font-size: 13px;
                    color: #666;
                    margin-bottom: 10px;
                }
                
                .stat-value {
                    font-size: 22px;
                    font-weight: 700;
                    color: #1D242E;
                }
                
                /* Tables */
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    margin-bottom: 20px;
                }
                
                th, td { 
                    padding: 12px; 
                    text-align: left; 
                    border: 1px solid #ddd;
                    font-size: 13px;
                }
                
                th { 
                    background: #f5f5f5; 
                    font-weight: 600;
                    color: #1D242E;
                }
                
                tbody tr:nth-child(even) {
                    background: #fafafa;
                }
                
                .empty-state {
                    text-align: center;
                    color: #999;
                    font-style: italic;
                    padding: 20px;
                }
                
                /* Footer */
                .footer {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 2px solid #ddd;
                    text-align: center;
                    font-size: 12px;
                    color: #666;
                }
                
                /* Print Specific */
                @media print {
                    body { padding: 20px; }
                    .report-section { page-break-inside: avoid; }
                    @page { margin: 1.5cm; }
                }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            <div class="header">
                <h1>Bethel Pharmacy</h1>
                <h2>Sales Report & Analytics</h2>
                <div class="date-range">Period: ${dateRange}</div>
                <div class="print-date">Generated: ${new Date().toLocaleString('en-US', { 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric', 
                    hour: '2-digit', 
                    minute: '2-digit' 
                })}</div>
            </div>
            
            ${summaryHTML}
            ${detailsHTML}
            
            <div class="footer">
                <p>Bethel Pharmacy Management System © 2025-2026</p>
                <p>This report is confidential and intended for authorized personnel only</p>
            </div>
        </body>
        </html>
    `);
    
    printWindow.document.close();
}
</script>

</body>
</html>