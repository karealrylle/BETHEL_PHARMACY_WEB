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

// Get staff shift reports
$staff_shifts = $conn->query("
    SELECT 
        ss.shift_id,
        ss.shift_date,
        ss.clock_in,
        ss.clock_out,
        ss.shift_duration,
        ss.total_sales,
        ss.transactions_count,
        ss.items_sold,
        ss.gcash_sales,
        ss.cash_sales,
        u.username,
        u.id as user_id
    FROM staff_shifts ss
    JOIN users u ON ss.user_id = u.id
    WHERE ss.shift_date BETWEEN '$date_from' AND '$date_to'
        AND ss.status = 'completed'
    ORDER BY ss.shift_date DESC, ss.clock_in DESC
");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Staff Reports</title>
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

        <!-- Report Type Tabs -->
        <div class="report-tabs">
            <a href="reports.php" class="report-tab">SALES REPORT</a>
            <a href="inventory_report.php" class="report-tab">INVENTORY REPORT</a>
            <a href="staff_report.php" class="report-tab active">STAFF REPORTS</a>
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

        <!-- Staff Shift Cards -->
        <div class="staff-shifts-container">
            <?php if ($staff_shifts->num_rows > 0): ?>
                <?php while ($shift = $staff_shifts->fetch_assoc()): 
                    $shift_start = date('ga', strtotime($shift['clock_in']));
                    $shift_end = $shift['clock_out'] ? date('ga', strtotime($shift['clock_out'])) : 'Ongoing';
                    $shift_hours = $shift['shift_duration'] ? floor($shift['shift_duration'] / 60) : 0;
                    $shift_minutes = $shift['shift_duration'] ? $shift['shift_duration'] % 60 : 0;
                    
                    // Determine performance
                    $performance_class = '';
                    $performance_text = '';
                    $card_class = ''; // NEW: Card styling class

                    if ($shift['total_sales'] >= 3000) {
                        $performance_class = 'excellent';
                        $performance_text = 'Excellent : Above Target';
                        $card_class = 'excellent'; // NEW
                    } elseif ($shift['total_sales'] < 1000 && $shift['total_sales'] > 0) {
                        $performance_class = 'low';
                        $performance_text = 'Low : Below Target';
                        $card_class = 'low'; // NEW
                    } else {
                        // This covers sales from 1000 to 2999
                        $performance_class = 'normal';
                        $performance_text = 'Normal : On Target';
                        $card_class = 'normal'; 
                    }
                ?>
                <div class="staff-shift-card <?php echo $card_class; ?>">
                    <div class="staff-card-header">
                        <div class="staff-info">
                            <h3><?php echo htmlspecialchars($shift['username']); ?></h3>
                            <span class="shift-date"><?php echo date('M d, Y', strtotime($shift['shift_date'])); ?></span>
                            <span class="shift-time">Shift Time: <?php echo $shift_start; ?> - <?php echo $shift_end; ?></span>
                            <span class="shift-duration">Shift Duration: <?php echo $shift_hours; ?> hours<?php echo $shift_minutes > 0 ? " $shift_minutes mins" : ''; ?></span>
                        </div>
                    </div>
                    
                    <div class="staff-card-stats">
                        <div class="stat-item">
                            <span class="stat-label">Item Sold :</span>
                            <span class="stat-value"><?php echo $shift['items_sold']; ?> units</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Transactions Processed :</span>
                            <span class="stat-value"><?php echo $shift['transactions_count']; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Total Sales Amount :</span>
                            <span class="stat-value">₱<?php echo number_format($shift['total_sales'], 2); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Gcash Sales :</span>
                            <span class="stat-value">₱<?php echo number_format($shift['gcash_sales'], 2); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Cash Sales :</span>
                            <span class="stat-value">₱<?php echo number_format($shift['cash_sales'], 2); ?></span>
                        </div>
                        <?php if ($performance_text): ?>
                        <div class="stat-item performance">
                            <span class="performance-badge <?php echo $performance_class; ?>"><?php echo $performance_text; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-data">
                    <p>No staff shifts found for the selected date range.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function printReport() {
        const printWindow = window.open('', '', 'height=800,width=1000');
        
        // Get all staff shift cards
        const staffCards = document.querySelectorAll('.staff-shift-card');
        let shiftsHTML = '';
        let totalShifts = 0;
        let totalSales = 0;
        let totalTransactions = 0;
        let totalItems = 0;
        let excellentCount = 0;
        let lowCount = 0;
        
        staffCards.forEach((card, index) => {
            // Extract data from card
            const staffName = card.querySelector('.staff-info h3').textContent;
            const shiftDate = card.querySelector('.shift-date').textContent;
            const shiftTime = card.querySelector('.shift-time').textContent.replace('Shift Time: ', '');
            const shiftDuration = card.querySelector('.shift-duration').textContent.replace('Shift Duration: ', '');
            
            const statItems = card.querySelectorAll('.stat-item');
            let itemsSold = '';
            let transactions = '';
            let totalSalesAmount = '';
            let gcashSales = '';
            let cashSales = '';
            let performance = '';
            let performanceClass = '';
            
            statItems.forEach(item => {
                const label = item.querySelector('.stat-label')?.textContent || '';
                const value = item.querySelector('.stat-value')?.textContent || '';
                const badge = item.querySelector('.performance-badge');
                
                if (label.includes('Item Sold')) itemsSold = value;
                else if (label.includes('Transactions')) transactions = value;
                else if (label.includes('Total Sales')) totalSalesAmount = value;
                else if (label.includes('Gcash')) gcashSales = value;
                else if (label.includes('Cash')) cashSales = value;
                
                if (badge) {
                    performance = badge.textContent;
                    performanceClass = badge.classList.contains('excellent') ? 'excellent' : 'low';
                    if (performanceClass === 'excellent') excellentCount++;
                    if (performanceClass === 'low') lowCount++;
                }
            });
            
            // Calculate totals (remove currency symbols and parse)
            const salesNum = parseFloat(totalSalesAmount.replace(/[₱,]/g, '')) || 0;
            const transNum = parseInt(transactions) || 0;
            const itemsNum = parseInt(itemsSold.replace(' units', '')) || 0;
            
            totalShifts++;
            totalSales += salesNum;
            totalTransactions += transNum;
            totalItems += itemsNum;
            
            // Determine card styling
            const cardClass = card.classList.contains('excellent') ? 'excellent' : 
                            card.classList.contains('low') ? 'low' : '';
            
            shiftsHTML += `
                <div class="shift-card ${cardClass}">
                    <div class="shift-header">
                        <div class="shift-main-info">
                            <h3>${staffName}</h3>
                            <span class="shift-meta">${shiftDate} • ${shiftTime} • ${shiftDuration}</span>
                        </div>
                        ${performance ? `<span class="performance-badge ${performanceClass}">${performance}</span>` : ''}
                    </div>
                    <div class="shift-stats">
                        <div class="stat-group">
                            <div class="stat">
                                <span class="stat-label">Items Sold</span>
                                <span class="stat-value">${itemsSold}</span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Transactions</span>
                                <span class="stat-value">${transactions}</span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Total Sales</span>
                                <span class="stat-value">${totalSalesAmount}</span>
                            </div>
                        </div>
                        <div class="stat-group">
                            <div class="stat">
                                <span class="stat-label">GCash Sales</span>
                                <span class="stat-value">${gcashSales}</span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Cash Sales</span>
                                <span class="stat-value">${cashSales}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        // Get date range
        const dateFrom = document.querySelector('input[name="date_from"]').value;
        const dateTo = document.querySelector('input[name="date_to"]').value;
        const dateRange = new Date(dateFrom).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + 
                        ' - ' + 
                        new Date(dateTo).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        
        // Summary section
        const summaryHTML = `
            <div class="summary-section">
                <div class="summary-card">
                    <div class="summary-label">Total Shifts</div>
                    <div class="summary-value">${totalShifts}</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Total Sales</div>
                    <div class="summary-value">₱${totalSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Total Transactions</div>
                    <div class="summary-value">${totalTransactions}</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Total Items Sold</div>
                    <div class="summary-value">${totalItems} units</div>
                </div>
            </div>
            
            <div class="performance-summary">
                <div class="perf-item excellent">
                    <span class="perf-label">Excellent Performance</span>
                    <span class="perf-count">${excellentCount} shift${excellentCount !== 1 ? 's' : ''}</span>
                </div>
                <div class="perf-item low">
                    <span class="perf-label">Low Performance</span>
                    <span class="perf-count">${lowCount} shift${lowCount !== 1 ? 's' : ''}</span>
                </div>
                <div class="perf-item normal">
                    <span class="perf-label">Normal Performance</span>
                    <span class="perf-count">${totalShifts - excellentCount - lowCount} shift${(totalShifts - excellentCount - lowCount) !== 1 ? 's' : ''}</span>
                </div>
            </div>
        `;
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Staff Report - Bethel Pharmacy</title>
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
                        gap: 15px;
                        margin-bottom: 30px;
                        page-break-inside: avoid;
                    }
                    
                    .summary-card {
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
                        font-size: 22px;
                        font-weight: 700;
                        color: #1D242E;
                    }
                    
                    /* Performance Summary */
                    .performance-summary {
                        display: grid;
                        grid-template-columns: repeat(3, 1fr);
                        gap: 15px;
                        margin-bottom: 30px;
                        page-break-inside: avoid;
                    }
                    
                    .perf-item {
                        border: 2px solid;
                        border-radius: 8px;
                        padding: 15px;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    }
                    
                    .perf-item.excellent {
                        border-color: #01A768;
                        background: rgba(1, 167, 104, 0.08);
                    }
                    
                    .perf-item.low {
                        border-color: #FF9800;
                        background: rgba(255, 152, 0, 0.08);
                    }
                    
                    .perf-item.normal {
                        border-color: #ddd;
                        background: #f9f9f9;
                    }
                    
                    .perf-label {
                        font-size: 13px;
                        font-weight: 600;
                        color: #666;
                    }
                    
                    .perf-count {
                        font-size: 18px;
                        font-weight: 700;
                        color: #1D242E;
                    }
                    
                    /* Shift Cards */
                    .shift-card {
                        border: 2px solid #e0e0e0;
                        border-radius: 10px;
                        padding: 20px;
                        margin-bottom: 20px;
                        page-break-inside: avoid;
                        background: white;
                    }
                    
                    .shift-card.excellent {
                        border-color: #01A768;
                        background: linear-gradient(135deg, rgba(1, 167, 104, 0.05) 0%, white 100%);
                    }
                    
                    .shift-card.low {
                        border-color: #FF9800;
                        background: linear-gradient(135deg, rgba(255, 152, 0, 0.05) 0%, white 100%);
                    }
                    
                    .shift-header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 15px;
                        padding-bottom: 15px;
                        border-bottom: 2px solid #f0f0f0;
                    }
                    
                    .shift-main-info h3 {
                        font-size: 18px;
                        color: #1D242E;
                        margin-bottom: 5px;
                    }
                    
                    .shift-meta {
                        font-size: 13px;
                        color: #666;
                    }
                    
                    .performance-badge {
                        padding: 8px 16px;
                        border-radius: 6px;
                        font-size: 12px;
                        font-weight: 600;
                    }
                    
                    .performance-badge.excellent {
                        background: rgba(1, 167, 104, 0.15);
                        color: #01A768;
                        border: 2px solid #01A768;
                    }
                    
                    .performance-badge.low {
                        background: rgba(255, 152, 0, 0.15);
                        color: #FF9800;
                        border: 2px solid #FF9800;
                    }
                    
                    /* Shift Stats */
                    .shift-stats {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 20px;
                    }
                    
                    .stat-group {
                        display: flex;
                        flex-direction: column;
                        gap: 10px;
                    }
                    
                    .stat {
                        display: flex;
                        justify-content: space-between;
                        padding: 8px 0;
                        border-bottom: 1px solid #f0f0f0;
                    }
                    
                    .stat:last-child {
                        border-bottom: none;
                    }
                    
                    .stat-label {
                        font-size: 13px;
                        color: #888;
                        font-weight: 500;
                    }
                    
                    .stat-value {
                        font-size: 14px;
                        font-weight: 600;
                        color: #1D242E;
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
                        .shift-card { page-break-inside: avoid; }
                        @page { margin: 1.5cm; }
                    }
                </style>
            </head>
            <body onload="window.print(); window.close();">
                <div class="header">
                    <h1>Bethel Pharmacy</h1>
                    <h2>Staff Shift Report</h2>
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
                
                <h3 style="margin: 30px 0 20px 0; font-size: 18px; color: #1D242E; border-bottom: 2px solid #ddd; padding-bottom: 10px;">Shift Details</h3>
                
                ${shiftsHTML}
                
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