<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'staff')) {
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

// Database connection and data fetching
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed");

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

$sql = "SELECT p.product_id, p.product_name, p.category, p.reorder_level, p.reorder_quantity,
        COALESCE(SUM(CASE WHEN pb.status = 'available' AND pb.expiry_date > CURDATE() AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 THEN pb.quantity ELSE 0 END), 0) AS current_stock,
        (p.reorder_quantity - COALESCE(SUM(CASE WHEN pb.status = 'available' AND pb.expiry_date > CURDATE() AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 THEN pb.quantity ELSE 0 END), 0)) AS stocks_needed
        FROM products p 
        LEFT JOIN product_batches pb ON p.product_id = pb.product_id 
        WHERE 1=1";

if ($search) $sql .= " AND (p.product_name LIKE '%$search%' OR p.category LIKE '%$search%')";
$sql .= " GROUP BY p.product_id 
          HAVING current_stock <= p.reorder_level AND stocks_needed > 0
          ORDER BY stocks_needed DESC";

$result = $conn->query($sql);
$repurchase_list = [];
$total_items = 0;
$total_units_needed = 0;

while($row = $result->fetch_assoc()) {
    $repurchase_list[] = $row;
    $total_items++;
    $total_units_needed += $row['stocks_needed'];
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Repurchase List</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/management.css">
    <link rel="stylesheet" href="css/tables.css">
    <link rel="stylesheet" href="css/inventory.css">
    <link rel="stylesheet" href="css/reports.css">

</head>
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
                <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php else: ?>
                <a href="staff_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
                <a href="pos.php" class="nav-button"><img src="assets/dashboard.png" alt="">POS</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="shift_report.php" class="nav-button"><img src="assets/reports.png" alt="">Shift Report</a>
                <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php endif; ?>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>
    
    <div class="content">
        <div class="reports-header">
            <h2>Inventory >> Repurchase List</h2>
                <div class="download-dropdown">
                    <button class="download-btn" onclick="printTable()">Print Report</button>
                </div>
        </div>
        
        <div class="management-panel">
            <div class="panel-controls" style="display: flex; justify-content: space-between; align-items: center;">
                <form method="GET" class="search-form">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="search-input" placeholder="Search products...">
                    <button type="submit" style="background: none; border: none; padding: 0; cursor: pointer;">
                        <img src="assets/search.png" alt="Search" class="search-icon">
                    </button>
                </form>
                <!-- <button onclick="printTable()" style="background: #c2251d; color: white; border: none; padding: 10px 34px; border-radius: 6px; font-size: 14px; font-weight: 550; cursor: pointer; transition: background 0.3s; white-space: nowrap;    ">
                  PRINT
                </button> -->
            </div>
            
            <div class="products-table-container">
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Reorder Quantity</th>
                            <th>Stocks Needed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($repurchase_list)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px; color: #666;">
                                    No products need repurchasing at this time.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($repurchase_list as $row): 
                                $stock_class = $row['current_stock'] == 0 ? 'status-red' : 'status-orange';
                            ?>
                            <tr>
                                <td><?php echo $row['product_id']; ?></td>
                                <td><?php echo $row['product_name']; ?></td>
                                <td><?php echo $row['category']; ?></td>
                                <td><span class="status-badge <?php echo $stock_class; ?>"><?php echo $row['current_stock']; ?></span></td>
                                <td><?php echo $row['reorder_level']; ?></td>
                                <td><?php echo $row['reorder_quantity']; ?></td>
                                <td><span class="status-badge status-red"><?php echo $row['stocks_needed']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function printTable() {
        const printWindow = window.open('', '', 'height=600,width=800');
        const table = document.querySelector('.products-table').outerHTML;
        
        printWindow.document.write(`
            <html>
            <head>
                <title>Repurchase List - Bethel Pharmacy</title>
                <style>
                    body { font-family: 'Poppins', Arial, sans-serif; padding: 20px; }
                    h2 { margin-bottom: 20px; color: #333; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
                    th { background: #f5f5f5; font-weight: 600; }
                    .status-badge { padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 500; }
                    .status-red { background: rgba(240, 72, 62, 0.2); color: #c2251d; }
                    .status-orange { background: #fd8a1e44; color: #da781c; }
                    @media print {
                        body { padding: 0; }
                    }
                </style>
            </head>
            <body onload="window.print(); window.close();">
                <h2>Repurchase List - ${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</h2>
                ${table}
            </body>
            </html>
        `);
        
        printWindow.document.close();
    }
    </script>

</body>
</html>