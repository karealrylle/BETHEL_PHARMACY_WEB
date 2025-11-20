<?php
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
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

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Point of Sale</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/tables.css">
    <link rel="stylesheet" href="css/pos.css">
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
                <a href="pos.php" class="nav-button active"><img src="assets/dashboard.png" alt="">POS</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="shift_report.php" class="nav-button"><img src="assets/reports.png" alt="">Shift Report</a>
                <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button"><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>
    
    <div class="content">
        <h2>Point of Sale >> Process Order</h2>
        
        <div class="pos-container">
            <!-- Left Side - Products -->
            <div class="products-section">
                <div class="search-form">
                    <input type="text" id="searchProduct" class="search-input" placeholder="Search products...">
                    <img src="assets/search.png" alt="Search" class="search-icon">
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
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <!-- Products loaded via JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Right Side - Receipt -->
            <div class="receipt-section">
                <div class="receipt-header">
                    <img src="assets/bethel_logo.png" alt="Bethel Pharmacy" class="receipt-logo">
                    <h3>ORDER SUMMARY</h3>
                </div>
                
                <div class="orders-panel" id="ordersPanel">
                    <div class="empty-cart">
                        <p>No items added</p>
                    </div>
                </div>
                
                <div class="totals-section">
                    <div class="discount-row">
                        <label>Discount (%):</label>
                        <input type="number" id="discountPercent" min="0" max="100" value="0" class="discount-input">
                    </div>
                    
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span id="subtotalAmount">₱0.00</span>
                    </div>
                    
                    <div class="total-row">
                        <span>Discount:</span>
                        <span id="discountAmount">₱0.00</span>
                    </div>
                    
                    <div class="total-row grand-total">
                        <span>Total:</span>
                        <span id="totalAmount">₱0.00</span>
                    </div>
                    
                    <div class="payment-row">
                        <label>Amount Paid:</label>
                        <input type="number" id="amountPaid" min="0" step="0.01" class="payment-input" placeholder="0.00">
                    </div>
                    
                    <div class="total-row change-row">
                        <span>Change:</span>
                        <span id="changeAmount">₱0.00</span>
                    </div>
                </div>
                
                <div class="payment-section">
                    <label>Payment Method:</label>
                    <select id="paymentMethod" class="payment-dropdown">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                    </select>
                </div>
                
                <div class="action-buttons">
                    <button class="clear-cart-btn" onclick="clearCart()">Clear Cart</button>
                    <button class="process-payment-btn" onclick="processPayment()">Process Payment</button>
                </div>
            </div>
        </div>
    </div>

    <script src="js/pos.js"></script>
</body>
</html>