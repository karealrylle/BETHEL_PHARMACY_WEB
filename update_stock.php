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

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_batch_count') {
    $conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
    $product_id = intval($_GET['product_id']);
    $result = $conn->query("SELECT COUNT(*) as count FROM product_batches WHERE product_id = $product_id");
    $row = $result->fetch_assoc();
    echo json_encode(['count' => $row['count']]);
    $conn->close();
    exit();
}

$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed");

$today = new DateTime();
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : '';
$stock_level = isset($_GET['stock_level']) ? $_GET['stock_level'] : '';
$expiry_status = isset($_GET['expiry_status']) ? $_GET['expiry_status'] : '';

$sql = "SELECT 
    p.product_id, 
    p.product_name, 
    p.category, 
    p.current_stock, 
    p.price, 
    p.how_to_use, 
    p.side_effects, 
    p.reorder_level, 
    COALESCE(SUM(CASE 
        WHEN pb.status IN ('available', 'low_stock') 
        AND pb.expiry_date > CURDATE() 
        AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
        THEN pb.quantity ELSE 0 END), 0) AS total_stock, 
    COUNT(CASE 
        WHEN pb.status IN ('available', 'low_stock') 
        AND DATEDIFF(pb.expiry_date, CURDATE()) > 365 
        THEN pb.batch_id END) AS batch_count, 
    MIN(pb.expiry_date) AS nearest_expiry,
    MIN(DATEDIFF(pb.expiry_date, CURDATE())) AS days_to_expiry
FROM products p 
LEFT JOIN product_batches pb ON p.product_id = pb.product_id AND pb.quantity > 0
WHERE 1=1";

if ($search) $sql .= " AND (p.product_name LIKE '%$search%' OR p.category LIKE '%$search%')";
if ($category) $sql .= " AND p.category = '$category'";
$sql .= " GROUP BY p.product_id";

if ($stock_level == 'good') $sql .= " HAVING total_stock > 50";
if ($stock_level == 'low') $sql .= " HAVING total_stock BETWEEN 1 AND 50";
if ($stock_level == 'out') $sql .= " HAVING total_stock = 0";

// Add expiry filter at SQL level
if ($expiry_status == 'fresh') {
    $sql .= ($stock_level ? " AND" : " HAVING") . " days_to_expiry > 180";
} elseif ($expiry_status == 'expiring') {
    $sql .= ($stock_level ? " AND" : " HAVING") . " days_to_expiry BETWEEN 0 AND 180";
} elseif ($expiry_status == 'expired') {
    $sql .= ($stock_level ? " AND" : " HAVING") . " days_to_expiry < 0";
}

$result = $conn->query($sql);
$filtered_results = [];

while($row = $result->fetch_assoc()) {
    $filtered_results[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Add Batch</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/management.css">
    <link rel="stylesheet" href="css/tables.css">
    <link rel="stylesheet" href="css/modals.css">
    <link rel="stylesheet" href="css/batch_modal.css">
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
        <h2>Inventory >> Update Stock</h2>
        
        <div class="management-panel">
            <div class="panel-controls">
                <div class="search-form">
                    <input type="text" id="searchInput" value="<?php echo htmlspecialchars($search); ?>" class="search-input" placeholder="Search products..." autocomplete="off">
                    <div class="search-icon" id="searchIcon">
                        <img src="assets/search.png" alt="Search" class="search-icon">
                    </div>
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
                    <input type="hidden" name="stock_level" value="<?php echo htmlspecialchars($stock_level); ?>">
                    <input type="hidden" name="expiry_status" value="<?php echo htmlspecialchars($expiry_status); ?>">
                </div>

                <form method="GET" class="filter-form">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <select name="category" class="filter-dropdown">
                        <option value="">All Categories</option>
                        <option value="Prescription Medicines" <?php echo ($category == 'Prescription Medicines') ? 'selected' : ''; ?>>Prescription Medicines</option>
                        <option value="Over-the-Counter (OTC) Products" <?php echo ($category == 'Over-the-Counter (OTC) Products') ? 'selected' : ''; ?>>OTC Products</option>
                        <option value="Health & Personal Care" <?php echo ($category == 'Health & Personal Care') ? 'selected' : ''; ?>>Health & Personal Care</option>
                        <option value="Medical Supplies & Equipment" <?php echo ($category == 'Medical Supplies & Equipment') ? 'selected' : ''; ?>>Medical Supplies</option>
                    </select>
                    <select name="stock_level" class="filter-dropdown">
                        <option value="">All Stock Levels</option>
                        <option value="high" <?php echo ($stock_level == 'high') ? 'selected' : ''; ?>>High Stock</option>
                        <option value="medium" <?php echo ($stock_level == 'medium') ? 'selected' : ''; ?>>Medium Stock</option>
                        <option value="low" <?php echo ($stock_level == 'low') ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="out" <?php echo ($stock_level == 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                    <select name="expiry_status" class="filter-dropdown">
                        <option value="">All Expiry Status</option>
                        <option value="fresh" <?php echo ($expiry_status == 'fresh') ? 'selected' : ''; ?>>Fresh</option>
                        <option value="expiring" <?php echo ($expiry_status == 'expiring') ? 'selected' : ''; ?>>Expiring Soon</option>
                        <option value="expired" <?php echo ($expiry_status == 'expired') ? 'selected' : ''; ?>>Expired</option>
                    </select>
                    <button type="submit" class="apply-filter-btn">Apply Filters</button>
                    <a href="update_stock.php" class="reset-filter-btn">Reset Filters</a>
                </form>
                
            </div>
            
            <div class="products-table-container">
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Total Stock</th>
                            <th>View Batches</th>
                            <th>Next Expiry</th>
                            <th>Add Batch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($filtered_results as $row): 
                            $stock = $row['total_stock'];
                            $stock_class = 'status-red';
                            if ($stock > $row['reorder_level']) {
                                $stock_class = 'status-green';
                            } else if ($stock > 0) {
                                $stock_class = 'status-orange';
                            }

                            $batch_count = $row['batch_count'];
                            $expiry_display = $row['nearest_expiry'] ? date('M d, Y', strtotime($row['nearest_expiry'])) : 'N/A';

                            // Determine batch class based on days_to_expiry
                            $days_to_expiry = $row['days_to_expiry'] ?? 999;
                            $batch_class = 'fresh';

                            if ($days_to_expiry < 0) {
                                $batch_class = 'expired';
                            } elseif ($days_to_expiry <= 365) {
                                $batch_class = 'near-expiry';
                            }
                        ?>
                        <tr>
                            <td><?php echo $row['product_id']; ?></td>
                            <td><?php echo $row['product_name']; ?></td>
                            <td><?php echo $row['category']; ?></td>
                            <td><span class="status-badge <?php echo $stock_class; ?>"><?php echo $stock; ?></span></td>
                            <td>
                                <?php 
                                $all_batches_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM product_batches WHERE product_id = {$row['product_id']} AND quantity > 0");
                                $all_batches = mysqli_fetch_assoc($all_batches_query)['total'];
                                
                                if ($all_batches > 0): ?>
                                    <button class="view-batches-btn <?php echo $batch_class; ?>" onclick="viewBatches(<?php echo $row['product_id']; ?>, '<?php echo htmlspecialchars($row['product_name']); ?>')">
                                        <?php echo $all_batches . ' Batch' . ($all_batches > 1 ? 'es' : ''); ?>
                                    </button>
                                <?php else: ?>
                                    <span class="no-batches-text">No Batches</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $expiry_display; ?></td>
                            <td>
                                <img src="assets/pencil.png" alt="Add Batch" class="edit-icon" onclick="openAddBatchModal(<?php echo $row['product_id']; ?>, '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>')">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php $conn->close(); ?>
    
    <!-- View Batches Modal -->
    <div class="modal-overlay" id="batchModalOverlay"></div>
    <div class="batch-modal" id="batchViewModal">
        <div class="modal-header">
            <span id="batchModalTitle">Product Batches</span>
            <button class="modal-close" onclick="closeBatchModal()">&times;</button>
        </div>
        <div class="batch-modal-content">
            <div class="batch-summary" id="batchSummaryContainer"></div>
            <div class="batch-list" id="batchList">
                <div class="loading-spinner">Loading batches...</div>
            </div>
        </div>
    </div>

    <!-- Add Batch Modal -->
    <div class="modal-overlay" id="addBatchModalOverlay"></div>
    <div class="add-batch-modal" id="addBatchModal">
        <div class="modal-header">
            Add New Batch
            <button class="modal-close" onclick="closeAddBatchModal()">&times;</button>
        </div>
        <form class="modal-form" method="POST" action="includes/add_batch.php" id="addBatchForm" enctype="multipart/form-data">
            <input type="hidden" name="product_id" id="batch_product_id">
            <div class="form-group">
                <label>Product Name</label>
                <input type="text" id="batch_product_name" readonly>
            </div>
            <div class="form-group">
                <label>Product ID</label>
                <input type="text" id="batch_display_product_id" readonly>
            </div>
            <div class="form-group">
                <label>Batch Number</label>
                <input type="text" name="batch_number" id="batch_number" readonly>
            </div>
            <div class="form-group">
                <label>Batch Quantity</label>
                <input type="number" name="quantity" id="batch_quantity" required min="1">
            </div>
            <div class="form-group">
                <label>Manufactured Date</label>
                <input type="date" name="manufactured_date" id="batch_manufactured" class="form-input" max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label>Expiry Date *</label>
                <input type="date" name="expiry_date" id="batch_expiry" required min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label>Supplier Name</label>
                <input type="text" name="supplier_name" id="supplier_name" class="form-input" placeholder="Optional">
            </div>
            <div class="form-group">
                <label>Purchase Price</label>
                <input type="number" name="purchase_price" id="purchase_price" class="form-input" step="0.01" min="0" placeholder="Optional">
            </div>
            <div class="modal-buttons">
                <button type="submit" class="modal-save" id="saveBatchBtn">Save</button>
                <button type="button" class="modal-cancel" onclick="closeAddBatchModal()">Cancel</button>
            </div>
        </form>
    </div>

    <script src="js/update_stock.js"></script>
</body>
</html>