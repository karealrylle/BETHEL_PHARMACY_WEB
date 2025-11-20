<?php
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'staff')) {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../update_stock.php?error=invalid_request");
    exit();
}

$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get user ID
$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 1;

// Get form data
$product_id = intval($_POST['product_id']);
$batch_number = $conn->real_escape_string(trim($_POST['batch_number']));
$quantity = intval($_POST['quantity']);
$manufactured_date = $_POST['manufactured_date'] ?? null;
$expiry_date = $_POST['expiry_date'] ?? null;
$supplier_name = !empty($_POST['supplier_name']) ? $conn->real_escape_string(trim($_POST['supplier_name'])) : null;
$purchase_price = !empty($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : null;

// Debug: Log received values
error_log("Product ID: $product_id");
error_log("Batch Number: $batch_number");
error_log("Quantity: $quantity");
error_log("Manufactured Date: " . ($manufactured_date ?? 'NULL'));
error_log("Expiry Date: " . ($expiry_date ?? 'NULL'));

// Validation
if ($product_id <= 0 || $quantity <= 0 || empty($batch_number)) {
    header("Location: ../update_stock.php?error=invalid_data");
    exit();
}

if (empty($expiry_date)) {
    header("Location: ../update_stock.php?error=missing_expiry");
    exit();
}

// Check for duplicate batch
$check = $conn->prepare("SELECT batch_id FROM product_batches WHERE product_id = ? AND batch_number = ?");
$check->bind_param("is", $product_id, $batch_number);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close();
    $conn->close();
    header("Location: ../update_stock.php?error=duplicate_batch");
    exit();
}
$check->close();

// Insert batch directly (bypass stored procedure for now)
$insert_sql = "INSERT INTO product_batches (
    product_id, 
    batch_number, 
    quantity, 
    original_quantity, 
    manufactured_date, 
    expiry_date, 
    supplier_name, 
    purchase_price, 
    received_date, 
    status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'available')";

$stmt = $conn->prepare($insert_sql);

// Handle NULL dates properly
if (empty($manufactured_date)) {
    $manufactured_date = null;
}

$stmt->bind_param("isiissds", 
    $product_id,
    $batch_number,
    $quantity,
    $quantity,
    $manufactured_date,
    $expiry_date,
    $supplier_name,
    $purchase_price
);

if ($stmt->execute()) {
    $batch_id = $conn->insert_id;
    
    // Record movement
    $movement_sql = "INSERT INTO batch_movements (
        batch_id, 
        movement_type, 
        quantity, 
        remaining_quantity, 
        performed_by, 
        notes
    ) VALUES (?, 'restock', ?, ?, ?, ?)";
    
    $notes = "New batch added: $batch_number";
    $movement_stmt = $conn->prepare($movement_sql);
    $movement_stmt->bind_param("iiiis", $batch_id, $quantity, $quantity, $user_id, $notes);
    $movement_stmt->execute();
    $movement_stmt->close();
    
    // Update product stock
    $update_sql = "UPDATE products SET current_stock = current_stock + ? WHERE product_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $quantity, $product_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    $stmt->close();
    $conn->close();
    
    header("Location: ../update_stock.php?success=batch_added");
    exit();
} else {
    $error = $conn->error;
    $stmt->close();
    $conn->close();
    header("Location: ../update_stock.php?error=failed&details=" . urlencode($error));
    exit();
}
?>