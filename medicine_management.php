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
    <title>Bethel Pharmacy - Medicine Management</title>
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
                <a href="medicine_management.php" class="nav-button active"><img src="assets/management.png" alt="">Medicine Management</a>
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
        <h2>Medicine Management</h2>
        <button class="add-product-btn">+ Add Product</button>
        
        <div class="management-panel">
            <div class="panel-controls">
                <div class="search-form">
                    <input type="text" id="searchInput" value="<?php echo htmlspecialchars($search); ?>" class="search-input" placeholder="Search products..." autocomplete="off">
                    <div class="search-icon" id="searchIcon">
                        <img src="assets/search.png" alt="Search" class="search-icon">
                    </div>
                    <input type="hidden" id="categoryFilter" value="<?php echo htmlspecialchars($category); ?>">
                    <input type="hidden" id="stockLevelFilter" value="<?php echo htmlspecialchars($stock_level); ?>">
                    <input type="hidden" id="expiryStatusFilter" value="<?php echo htmlspecialchars($expiry_status); ?>">
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
                        <option value="good" <?php echo ($stock_level == 'good') ? 'selected' : ''; ?>>Good Stock</option>
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
                    <a href="medicine_management.php" class="reset-filter-btn">Reset Filters</a>
                </form>
            </div>
            
            <div class="products-table-container">
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Batches</th>
                            <th>Edit</th>
                            <th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($filtered_results as $row): 
                            // Stock calculation and styling
                            $stock = $row['total_stock'];
                            $stock_class = 'status-red';
                            if ($stock > $row['reorder_level']) {
                                $stock_class = 'status-green';
                            } else if ($stock > 0) {
                                $stock_class = 'status-orange';
                            }

                            // Batch count and expiry display
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

                            // Get total batches count
                            $all_batches_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM product_batches WHERE product_id = {$row['product_id']} AND quantity > 0");
                            $all_batches = mysqli_fetch_assoc($all_batches_query)['total'];
                        ?>
                        <tr>
                            <td><?php echo $row['product_id']; ?></td>
                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['category']); ?></td>
                            <td>₱<?php echo number_format($row['price'], 2); ?></td>
                            <td>
                                <span class="status-badge <?php echo $stock_class; ?>">
                                    <?php echo $stock; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($all_batches > 0): ?>
                                    <button class="view-batches-btn <?php echo $batch_class; ?>" 
                                            onclick="viewBatches(<?php echo $row['product_id']; ?>, '<?php echo htmlspecialchars($row['product_name']); ?>')">
                                        <?php echo $all_batches . ' Batch' . ($all_batches > 1 ? 'es' : ''); ?>
                                    </button>
                                <?php else: ?>
                                    <span class="no-batches-text">No Batches</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-cell">
                                <img src="assets/pencil.png" alt="Edit" class="edit-icon" 
                                    onclick="editProduct(
                                        <?php echo $row['product_id']; ?>,
                                        '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($row['category'], ENT_QUOTES); ?>',
                                        <?php echo $row['price']; ?>,
                                        '<?php echo htmlspecialchars($row['how_to_use'] ?? '', ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($row['side_effects'] ?? '', ENT_QUOTES); ?>'
                                    )">
                            </td>
                            <td class="action-cell">
                                <img src="assets/redbin.png" alt="Delete" class="delete-icon" 
                                    onclick="deleteProduct(<?php echo $row['product_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['product_name'])); ?>')">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php $conn->close(); ?>
    
    <!-- Add/Edit Product Modal -->
    <div class="modal-overlay" id="modalOverlay"></div>
    <div class="add-product-modal" id="productModal">
        <div class="modal-header">
            <span id="modalTitle">Add New Product</span>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" id="productForm" class="modal-form">
            <input type="hidden" name="product_id" id="productId">
            <input type="hidden" name="current_stock" id="productCurrentStock">
            <div class="form-group">
                <label>PRODUCT NAME</label>
                <input type="text" name="product_name" id="productName" required>
            </div>
            <div class="form-group">
                <label>PRODUCT ID</label>
                <input type="text" id="displayProductId" readonly>
            </div>
            <div class="form-group">
                <label>CATEGORY</label>
                <select name="category" id="productCategory" required>
                    <option value="">Select Category</option>
                    <option value="Prescription Medicines">Prescription Medicines</option>
                    <option value="Over-the-Counter (OTC) Products">Over-the-Counter (OTC) Products</option>
                    <option value="Health & Personal Care">Health & Personal Care</option>
                    <option value="Medical Supplies & Equipment">Medical Supplies & Equipment</option>
                </select>
            </div>
            <div class="form-group">
                <label>PRICE (₱)</label>
                <input type="number" step="0.01" name="price" id="productPrice" required>
            </div>
            <div class="form-group full-width">
                <label>How to Use</label>
                <textarea name="how_to_use" id="productHowToUse"></textarea>
            </div>
            <div class="form-group full-width">
                <label>Side Effects</label>
                <textarea name="side_effects" id="productSideEffects"></textarea>
            </div>
            <div class="modal-buttons">
                <button type="button" class="modal-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="modal-save">Save Details</button>
            </div>
        </form>
    </div>

    <!-- View Batches Modal -->
    <div class="modal-overlay" id="batchModalOverlay"></div>
    <div class="batch-modal" id="batchModal">
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

<script>
// Modal functions
function closeModal() {
    document.getElementById('productModal').classList.remove('show');
    document.getElementById('modalOverlay').classList.remove('show');
    document.getElementById('productForm').reset();
    document.getElementById('productForm').action = 'includes/add_product.php';
    document.getElementById('modalTitle').textContent = 'Add New Product';
    document.getElementById('productId').value = '';
}

// Add product button
document.querySelector('.add-product-btn').onclick = function() {
    document.getElementById('productForm').action = 'includes/add_product.php';
    document.getElementById('modalTitle').textContent = 'Add New Product';
    document.getElementById('displayProductId').value = 'PRD-' + String(Math.floor(Math.random() * 900) + 100).padStart(3, '0');
    document.getElementById('productModal').classList.add('show');
    document.getElementById('modalOverlay').classList.add('show');
};

// Edit product function - FIXED: Opens modal to edit product info
function editProduct(productId, productName, category, price, howToUse, sideEffects) {
    console.log('Editing product:', productId, productName, category, price);
    
    document.getElementById('productForm').action = 'includes/update_product.php';
    document.getElementById('modalTitle').textContent = 'Edit Product';
    document.getElementById('productId').value = productId;
    document.getElementById('displayProductId').value = 'PRD-' + String(productId).padStart(3, '0');
    document.getElementById('productName').value = productName;
    document.getElementById('productCategory').value = category;
    document.getElementById('productPrice').value = parseFloat(price).toFixed(2);
    document.getElementById('productHowToUse').value = howToUse || '';
    document.getElementById('productSideEffects').value = sideEffects || '';
    document.getElementById('productModal').classList.add('show');
    document.getElementById('modalOverlay').classList.add('show');
}

// Delete product function
function deleteProduct(productId, productName) {
    if (confirm(`Are you sure you want to delete "${productName}"? This action cannot be undone.`)) {
        // Create form data
        const formData = new FormData();
        formData.append('product_id', productId);
        
        fetch('includes/delete_product_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Product deleted successfully');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to delete product'));
            }
        })
        .catch(error => {
            alert('Error deleting product');
            console.error('Error:', error);
        });
    }
}

// Form submission handler for product modal
document.getElementById('productForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const action = this.action;
    
    fetch(action, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.redirected) {
            window.location.href = response.url;
            return;
        }
        return response.text();
    })
    .then(data => {
        // If it's not a redirect, try to parse as JSON
        try {
            const result = JSON.parse(data);
            if (result.success) {
                alert('Product saved successfully');
                closeModal();
                location.reload();
            } else {
                alert('Error: ' + (result.message || 'Failed to save product'));
            }
        } catch (e) {
            // If it's HTML or plain text, assume success and reload
            alert('Product saved successfully');
            closeModal();
            location.reload();
        }
    })
    .catch(error => {
        alert('Error saving product');
        console.error('Error:', error);
    });
});

// Batch modal functions
function viewBatches(productId, productName) {
    document.getElementById('batchModalTitle').textContent = productName;
    document.getElementById('batchModal').classList.add('show');
    document.getElementById('batchModalOverlay').classList.add('show');
    
    const batchList = document.getElementById('batchList');
    batchList.innerHTML = '<div class="loading-spinner">Loading batches...</div>';
    
    fetch('includes/get_batches.php?product_id=' + productId)
        .then(response => response.json())
        .then(data => {
            if (data.success) displayBatches(data.batches, data.total_stock, data.active_count);
            else batchList.innerHTML = '<div class="no-batches">No batches found</div>';
        })
        .catch(error => batchList.innerHTML = '<div class="error-message">Error loading batches</div>');
}

function closeBatchModal() {
    document.getElementById('batchModal').classList.remove('show');
    document.getElementById('batchModalOverlay').classList.remove('show');
}

function displayBatches(batches, totalStock, activeCount) {
    let stockStatus = totalStock > 200 ? 'Good Stock' : (totalStock >= 50 ? 'Low in Stock' : 'Critical Stock');
    let stockClass = totalStock > 200 ? 'status-good' : (totalStock >= 50 ? 'status-low' : 'status-critical');
    
    document.getElementById('batchSummaryContainer').innerHTML = `
        <div class="batch-summary-item ${stockClass}">
            <span class="batch-summary-label ${stockClass}">${stockStatus}</span>
            <span class="batch-summary-value">${totalStock}</span>
        </div>
        <div class="batch-summary-item status-available">
            <span class="batch-summary-label status-available">Total Batches</span>
            <span class="batch-summary-value">${activeCount}</span>
        </div>`;
    
    const batchList = document.getElementById('batchList');
    if (batches.length === 0) {
        batchList.innerHTML = '<div class="no-batches">No batches available</div>';
        return;
    }
    
    // Find the closest-to-expiry FRESH batch for "In Use"
    let inUseBatchId = null;
    let closestDays = Infinity;
    
    batches.forEach((batch) => {
        const today = new Date();
        const expiryDate = new Date(batch.expiry_date);
        const diffTime = expiryDate - today;
        const daysLeft = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        const monthsLeft = daysLeft / 30;
        
        // Only consider fresh batches for "In Use"
        if (daysLeft > 0 && monthsLeft >= 12 && daysLeft < closestDays) {
            closestDays = daysLeft;
            inUseBatchId = batch.batch_id;
        }
    });
    
    let html = '';
    batches.forEach((batch) => {
        const today = new Date();
        const expiryDate = new Date(batch.expiry_date);
        const diffTime = expiryDate - today;
        const daysLeft = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        const monthsLeft = daysLeft / 30;
        
        const cardClass = daysLeft < 0 ? 'expired' : (monthsLeft < 12 ? 'near-expiry' : 'fresh');
        let batchStatus = 'Available';
        let statusClass = 'status-available';

        if (daysLeft < 0) {
            batchStatus = 'Expired';
            statusClass = 'status-expired';
        } else if (monthsLeft < 12) {
            batchStatus = 'Near Expiry';
            statusClass = 'status-near-expiry';
        } else if (batch.batch_id === inUseBatchId) {
            batchStatus = 'In Use';
            statusClass = 'status-in-use';
        }
        
        let disposeBtn = '';
        if (daysLeft < 0 || monthsLeft < 12) {
            disposeBtn = `<button class="dispose-btn" onclick="disposeBatch(${batch.batch_id}, '${batch.batch_number}')">
                <img src="assets/bin.png" alt="Dispose">
            </button>`;
        }
        
        html += `
            <div class="batch-card ${cardClass}">
                <div class="batch-card-header">
                    <span class="batch-number">${batch.batch_number}</span>
                    <div class="batch-status-container">
                        <span class="batch-status ${statusClass}">${batchStatus}</span>
                        ${disposeBtn}
                    </div>
                </div>
                <div class="batch-card-body">
                    <div class="batch-info-item">
                        <span class="batch-label">Quantity:</span>
                        <span class="batch-value">${batch.quantity} units</span>
                    </div>
                    <div class="batch-info-item">
                        <span class="batch-label">Expiry:</span>
                        <span class="batch-value">${new Date(batch.expiry_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</span>
                    </div>
                    <div class="batch-info-item">
                        <span class="batch-label">Days Left:</span>
                        <span class="batch-value">${daysLeft} days</span>
                    </div>
                </div>
            </div>`;
    });
    batchList.innerHTML = html;
}

function disposeBatch(batchId, batchNumber) {
    if (confirm(`Are you sure you want to dispose of batch ${batchNumber}? This action cannot be undone.`)) {
        fetch('includes/dispose_batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch_id: batchId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Batch disposed successfully');
                closeBatchModal();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to dispose batch'));
            }
        })
        .catch(error => alert('Error disposing batch'));
    }
}

// Event listeners
document.getElementById('modalOverlay').onclick = closeModal;
document.getElementById('batchModalOverlay').onclick = closeBatchModal;

// Real-time search functionality
function setupRealTimeSearch() {
    const searchInput = document.getElementById('searchInput');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const table = document.querySelector('.products-table tbody');
        
        if (!table) return;

        const rows = table.getElementsByTagName('tr');
        let hasVisibleRows = false;

        for (let row of rows) {
            let found = false;
            const cells = row.getElementsByTagName('td');
            
            if (cells.length === 0) continue;

            for (let i = 0; i < cells.length - 1; i++) {
                const cell = cells[i];
                if (cell.textContent.toLowerCase().includes(searchTerm)) {
                    found = true;
                    break;
                }
            }

            row.style.display = found ? '' : 'none';
            if (found) hasVisibleRows = true;
        }

        const noResults = document.getElementById('noResultsMessage');
        if (!noResults && !hasVisibleRows && rows.length > 0) {
            const noResultsRow = document.createElement('tr');
            noResultsRow.id = 'noResultsMessage';
            noResultsRow.innerHTML = `<td colspan="8" style="text-align: center; padding: 1rem;">No products found matching "${searchTerm}"</td>`;
            table.appendChild(noResultsRow);
        } else if (noResults) {
            if (hasVisibleRows) {
                noResults.remove();
            } else {
                noResults.querySelector('td').textContent = `No products found matching "${searchTerm}"`;
            }
        }
    });
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    setupRealTimeSearch();
    
    const searchIcon = document.getElementById('searchIcon');
    if (searchIcon) {
        searchIcon.onclick = function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.focus();
            }
        };
    }
});
</script>
</body>
</html>