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

date_default_timezone_set('Asia/Manila');
$current_date = date('l, F j, Y');
$current_time = date('h:i A');

$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed");

// Get accurate inventory statistics using batch data
$total_products = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'];

$low_stock = $conn->query("SELECT COUNT(*) as count FROM (
    SELECT p.product_id,
        COALESCE(SUM(CASE 
            WHEN pb.status IN ('available', 'low_stock') 
            AND pb.expiry_date > CURDATE() 
            AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
            THEN pb.quantity ELSE 0 END), 0) AS total_stock
    FROM products p
    LEFT JOIN product_batches pb ON p.product_id = pb.product_id AND pb.quantity > 0
    GROUP BY p.product_id
    HAVING total_stock > 0 AND total_stock <= 50
) AS low_stock_products")->fetch_assoc()['count'];

$expiring_soon = $conn->query("SELECT COUNT(DISTINCT p.product_id) as count 
    FROM products p
    INNER JOIN product_batches pb ON p.product_id = pb.product_id 
    WHERE pb.quantity > 0 
    AND pb.expiry_date > CURDATE()
    AND DATEDIFF(pb.expiry_date, CURDATE()) <= 182")->fetch_assoc()['count'];

$out_of_stock = $conn->query("SELECT COUNT(*) as count FROM (
    SELECT p.product_id,
        COALESCE(SUM(CASE 
            WHEN pb.status IN ('available', 'low_stock') 
            AND pb.expiry_date > CURDATE() 
            AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
            THEN pb.quantity ELSE 0 END), 0) AS total_stock
    FROM products p
    LEFT JOIN product_batches pb ON p.product_id = pb.product_id AND pb.quantity > 0
    GROUP BY p.product_id
    HAVING total_stock = 0
) AS out_of_stock_products")->fetch_assoc()['count'];

// Get accurate inventory details using same logic as medicine_management
$inventory_query = "SELECT 
    p.product_id,
    p.product_name,
    p.category,
    p.price,
    p.reorder_level,
    COALESCE(SUM(CASE 
        WHEN pb.status IN ('available', 'low_stock') 
        AND pb.expiry_date > CURDATE() 
        AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
        THEN pb.quantity ELSE 0 END), 0) AS stock,
    MIN(CASE 
        WHEN pb.expiry_date > CURDATE() 
        THEN pb.expiry_date 
    END) as nearest_expiry,
    MIN(CASE 
        WHEN pb.expiry_date > CURDATE() 
        THEN DATEDIFF(pb.expiry_date, CURDATE()) 
    END) as days_left
FROM products p
LEFT JOIN product_batches pb ON p.product_id = pb.product_id AND pb.quantity > 0
GROUP BY p.product_id
ORDER BY stock ASC, days_left ASC";

$inventory_result = $conn->query($inventory_query);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Inventory Report</title>
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
            <a href="reports.php" class="report-tab">SALES REPORT</a>
            <a href="inventory_report.php" class="report-tab active">INVENTORY REPORT</a>
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

        <div class="summary-boxes">
            <div class="summary-box green" onclick="showInventoryDetails('all')">
                <div class="summary-box-label">Total Products in Inventory</div>
                <div class="summary-box-value"><?php echo $total_products; ?></div>
            </div>

            <div class="summary-box blue" onclick="showInventoryDetails('low')">
                <div class="summary-box-label">Medicine Low in Stock</div>
                <div class="summary-box-value"><?php echo $low_stock; ?></div>
            </div>

            <div class="summary-box orange" onclick="showInventoryDetails('expiring')">
                <div class="summary-box-label">Products Near Expiry</div>
                <div class="summary-box-value"><?php echo $expiring_soon; ?></div>
            </div>

            <div class="summary-box red" onclick="showInventoryDetails('out')">
                <div class="summary-box-label">Medicine Out of Stock</div>
                <div class="summary-box-value"><?php echo $out_of_stock; ?></div>
            </div>
        </div>

        <!-- Details Panel -->
        <div class="details-panel">
            <div id="inventory-all" class="details-content active">
                <h3>All Inventory Products</h3>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Price</th>
                            <th>Expiry Date</th>
                            <th>Days Left</th>
                            <th>Exp Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $inventory_result->data_seek(0);
                        while($item = $inventory_result->fetch_assoc()): 
                            $stock = $item['stock'];
                            $stock_status = $stock == 0 ? 'Out of Stock' : ($stock < 50 ? 'Low Stock' : 'In Stock');
                            $stock_class = $stock == 0 ? 'status-red' : ($stock < 50 ? 'status-orange' : 'status-green');
                            
                            $days_left = $item['days_left'] ?? 999;
                            $exp_status = $days_left < 0 ? 'Expired' : ($days_left <= 90 ? 'Expiring Soon' : 'Fresh');
                            $exp_class = $days_left < 0 ? 'status-red' : ($days_left <= 90 ? 'status-orange' : 'status-green');
                            $expiry_display = $item['nearest_expiry'] ? date('M d, Y', strtotime($item['nearest_expiry'])) : 'N/A';
                        ?>
                        <tr>
                            <td>PRD-<?php echo str_pad($item['product_id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td><?php echo $stock; ?></td>
                            <td><span class="status-badge <?php echo $stock_class; ?>"><?php echo $stock_status; ?></span></td>
                            <td>₱<?php echo number_format($item['price'], 2); ?></td>
                            <td><?php echo $expiry_display; ?></td>
                            <td><?php echo $days_left < 0 ? 'Expired' : $days_left . ' days'; ?></td>
                            <td><span class="status-badge <?php echo $exp_class; ?>"><?php echo $exp_status; ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div id="inventory-low" class="details-content">
                <h3>Low Stock Products</h3>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $inventory_result->data_seek(0);
                        while($item = $inventory_result->fetch_assoc()): 
                            if ($item['stock'] > 0 && $item['stock'] <= 50):
                        ?>
                        <tr>
                            <td>PRD-<?php echo str_pad($item['product_id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td><span class="status-badge status-orange"><?php echo $item['stock']; ?></span></td>
                            <td><?php echo $item['reorder_level']; ?></td>
                            <td>₱<?php echo number_format($item['price'], 2); ?></td>
                        </tr>
                        <?php 
                            endif;
                        endwhile; 
                        ?>
                    </tbody>
                </table>
            </div>

            <div id="inventory-expiring" class="details-content">
                <h3>Products to Dispose </h3>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Stock</th>
                            <th>Expiry Date</th>
                            <th>Days Left</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $inventory_result->data_seek(0);
                        while($item = $inventory_result->fetch_assoc()): 
                            $days_left = $item['days_left'] ?? 999;
                            if ($days_left >= 0 && $days_left <= 182):
                                $expiry_display = $item['nearest_expiry'] ? date('M d, Y', strtotime($item['nearest_expiry'])) : 'N/A';
                        ?>
                        <tr>
                            <td>PRD-<?php echo str_pad($item['product_id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo $item['stock']; ?></td>
                            <td><?php echo $expiry_display; ?></td>
                            <td><?php echo $days_left . ' days'; ?></td>
                            <td><span class="status-badge status-orange">Expiring Soon</span></td>
                        </tr>
                        <?php 
                            endif;
                        endwhile; 
                        ?>
                    </tbody>
                </table>
            </div>

            <div id="inventory-out" class="details-content">
                <h3>Out of Stock Products</h3>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $inventory_result->data_seek(0);
                        while($item = $inventory_result->fetch_assoc()): 
                            if ($item['stock'] == 0):
                        ?>
                        <tr>
                            <td>PRD-<?php echo str_pad($item['product_id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td>₱<?php echo number_format($item['price'], 2); ?></td>
                            <td><?php echo $item['stock']; ?></td>
                            <td><span class="status-badge status-red">Out of Stock</span></td>
                        </tr>
                        <?php 
                            endif;
                        endwhile; 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

        <script>
        // Show all inventory by default
        document.addEventListener('DOMContentLoaded', function() {
            showInventoryDetails('all');
        });

        function showInventoryDetails(type) {
            // Remove active from all boxes
            document.querySelectorAll('.summary-box').forEach(box => {
                box.classList.remove('active');
            });
            
            // Hide all details
            document.querySelectorAll('.details-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Show selected details
            const detailsMap = {
                'all': 'inventory-all',
                'low': 'inventory-low',
                'expiring': 'inventory-expiring',
                'out': 'inventory-out'
            };
            
            const detailsId = detailsMap[type];
            if (detailsId) {
                document.getElementById(detailsId).classList.add('active');
            }
            
            // Add active to clicked box
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            } else {
                const boxes = document.querySelectorAll('.summary-box');
                if (boxes[0]) boxes[0].classList.add('active');
            }

            function viewBatches(productId, productName) {
                alert('View batches for: ' + productName + ' (ID: ' + productId + ')');
                // You can implement the batch modal functionality here if needed
            }
        }
        </script>

        <script>
        function printReport() {
            const printWindow = window.open('', '', 'height=800,width=1000');
            
            // Get summary statistics
            const summaryBoxes = document.querySelectorAll('.summary-box');
            const totalProducts = summaryBoxes[0].querySelector('.summary-box-value').textContent;
            const lowStock = summaryBoxes[1].querySelector('.summary-box-value').textContent;
            const nearExpiry = summaryBoxes[2].querySelector('.summary-box-value').textContent;
            const outOfStock = summaryBoxes[3].querySelector('.summary-box-value').textContent;
            
            // Get all inventory tables
            const allInventoryTable = document.querySelector('#inventory-all table').outerHTML;
            const lowStockTable = document.querySelector('#inventory-low table').outerHTML;
            const expiringTable = document.querySelector('#inventory-expiring table').outerHTML;
            const outOfStockTable = document.querySelector('#inventory-out table').outerHTML;
            
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
                    <title>Inventory Report - Bethel Pharmacy</title>
                    <style>
                        * { margin: 0; padding: 0; box-sizing: border-box; }
                        body { 
                            font-family: 'Poppins', Arial, sans-serif; 
                            padding: 30px; 
                            color: #333;
                        }
                        
                        .header {
                            text-align: center;
                            margin-bottom: 15px;
                            padding-bottom: 15px;
                            border-bottom: 2px solid #C92126;
                        }
                        
                        .header h1 {
                            font-size: 24px;
                            color: #C92126;
                            margin-bottom: 5px;
                        }
                        
                        .header h2 {
                            font-size: 18px;
                            color: #1D242E;
                            margin-bottom: 8px;
                        }
                        
                        .date-range {
                            font-size: 13px;
                            color: #666;
                            margin-top: 8px;
                        }
                        
                        /* Summary Section */
                        .summary-section {
                            display: grid;
                            grid-template-columns: repeat(4, 1fr);
                            gap: 15px;
                            margin-bottom: 30px;
                            page-break-inside: avoid;
                        }
                        
                        .summary-item {
                            border: 1px solid #ddd;
                            border-radius: 6px;
                            padding: 15px;
                            text-align: center;
                        }
                        
                        .summary-label {
                            font-size: 12px;
                            color: #666;
                            margin-bottom: 8px;
                        }
                        
                        .summary-value {
                            font-size: 20px;
                            font-weight: 700;
                            color: #1D242E;
                        }
                        
                        /* Report Sections */
                        .report-section {
                            margin-bottom: 20px;
                            page-break-inside: avoid;
                        }
                        
                        .report-section h3 {
                            font-size: 16px;
                            color: #1D242E;
                            margin-bottom: 12px;
                            padding-bottom: 8px;
                            border-bottom: 2px solid #ddd;
                        }
                        
                        /* Tables */
                        table { 
                            width: 100%; 
                            border-collapse: collapse; 
                            margin-bottom: 15px;
                        }
                        
                        th, td { 
                            padding: 10px; 
                            text-align: left; 
                            border: 1px solid #ddd;
                            font-size: 12px;
                        }
                        
                        th { 
                            background: #f5f5f5; 
                            font-weight: 600;
                            color: #1D242E;
                        }
                        
                        tbody tr:nth-child(even) {
                            background: #fafafa;
                        }
                        
                        .status-badge { 
                            padding: 3px 10px; 
                            border-radius: 4px; 
                            font-size: 11px; 
                            font-weight: 600; 
                            display: inline-block;
                        }
                        
                        .status-red { 
                            background: rgba(244, 67, 54, 0.2); 
                            color: #F44336; 
                        }
                        
                        .status-orange { 
                            background: rgba(255, 152, 0, 0.2); 
                            color: #FF9800; 
                        }
                        
                        .status-green { 
                            background: rgba(1, 167, 104, 0.2); 
                            color: #01A768; 
                        }
                        
                        /* Footer */
                        .footer {
                            margin-top: 30px;
                            padding-top: 15px;
                            border-top: 2px solid #ddd;
                            text-align: center;
                            font-size: 11px;
                            color: #666;
                        }
                        
                        /* Print Specific */
                        @media print {
                            body { padding: 15px; }
                            .report-section { page-break-inside: avoid; }
                            @page { margin: 1cm; }
                        }
                    </style>
                </head>
                <body onload="window.print(); window.close();">
                    <div class="header">
                        <h1>Bethel Pharmacy</h1>
                        <h2>Inventory Report</h2>
                        <div class="date-range">Report Period: ${dateRange}</div>
                        <div class="date-range">Generated: ${new Date().toLocaleString('en-US', { 
                            month: 'short', 
                            day: 'numeric', 
                            year: 'numeric', 
                            hour: '2-digit', 
                            minute: '2-digit' 
                        })}</div>
                    </div>
                    
                    <div class="summary-section">
                        <div class="summary-item">
                            <div class="summary-label">Total Products</div>
                            <div class="summary-value">${totalProducts}</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Low Stock</div>
                            <div class="summary-value">${lowStock}</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Near Expiry</div>
                            <div class="summary-value">${nearExpiry}</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Out of Stock</div>
                            <div class="summary-value">${outOfStock}</div>
                        </div>
                    </div>
                    
                    <div class="report-section">
                        <h3>All Inventory Products</h3>
                        ${allInventoryTable}
                    </div>
                    
                    <div class="report-section">
                        <h3>Low Stock Products</h3>
                        ${lowStockTable}
                    </div>
                    
                    <div class="report-section">
                        <h3>Products Near Expiry (To Dispose)</h3>
                        ${expiringTable}
                    </div>
                    
                    <div class="report-section">
                        <h3>Out of Stock Products</h3>
                        ${outOfStockTable}
                    </div>
                    
                    <div class="footer">
                        <p>Bethel Pharmacy Management System © 2025-2026</p>
                    </div>
                </body>
                </html>
            `);
            
            printWindow.document.close();
        }
        </script>

</body>
</html>