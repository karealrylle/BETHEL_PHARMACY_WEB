<?php
// includes/add_batch.php
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

// Get user ID from session with fallback
$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 1;

// Get and validate inputs
$product_id = intval($_POST['product_id']);
$batch_number = $conn->real_escape_string(trim($_POST['batch_number']));
$quantity = intval($_POST['quantity']);

// DEBUG: Log received data
error_log("Received batch data - Product ID: $product_id, Batch: $batch_number, Quantity: $quantity");
error_log("Manufactured Date: " . ($_POST['manufactured_date'] ?? 'NOT SET'));
error_log("Expiry Date: " . ($_POST['expiry_date'] ?? 'NOT SET'));

// Handle dates properly - don't convert to NULL
$manufactured_date = (!empty($_POST['manufactured_date'])) 
    ? $_POST['manufactured_date'] 
    : '0000-00-00';
    
$expiry_date = (!empty($_POST['expiry_date'])) 
    ? $_POST['expiry_date'] 
    : '0000-00-00';

$supplier_name = (!empty($_POST['supplier_name'])) 
    ? $conn->real_escape_string(trim($_POST['supplier_name'])) 
    : NULL;
    
$purchase_price = (!empty($_POST['purchase_price'])) 
    ? floatval($_POST['purchase_price']) 
    : NULL;

// Validation
if ($product_id <= 0 || $quantity <= 0 || empty($batch_number)) {
    header("Location: ../update_stock.php?error=invalid_data");
    exit();
}

// Check if expiry date is provided
if (empty($expiry_date) || $expiry_date === '0000-00-00') {
    header("Location: ../update_stock.php?error=missing_expiry");
    exit();
}

// Check if batch number already exists for this product
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

// Start transaction for atomic operation
$conn->begin_transaction();

try {
    // DEBUG: Log before insertion
    error_log("Inserting batch with dates - Manufactured: $manufactured_date, Expiry: $expiry_date");
    
    // Use direct insertion instead of stored procedure to ensure dates are saved
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
    
    // Bind parameters - use strings for dates to ensure proper formatting
    $stmt->bind_param("isssssdd", 
        $product_id, 
        $batch_number, 
        $quantity, 
        $quantity,  // original_quantity same as quantity
        $manufactured_date, 
        $expiry_date, 
        $supplier_name, 
        $purchase_price
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to insert batch: " . $stmt->error);
    }
    
    $batch_id = $conn->insert_id;
    $stmt->close();

    // DEBUG: Log successful insertion
    error_log("Batch inserted successfully with ID: $batch_id");

    // Record the movement
    $movement_sql = "INSERT INTO batch_movements (
        batch_id,
        movement_type,
        quantity,
        remaining_quantity,
        performed_by,
        notes
    ) VALUES (?, 'restock', ?, ?, ?, CONCAT('New batch added: ', ?))";

    $movement_stmt = $conn->prepare($movement_sql);
    $movement_stmt->bind_param("isiis", 
        $batch_id, 
        $quantity, 
        $quantity, 
        $user_id, 
        $batch_number
    );
    
    if (!$movement_stmt->execute()) {
        throw new Exception("Failed to record movement: " . $movement_stmt->error);
    }
    $movement_stmt->close();

    // Update legacy current_stock for backward compatibility
    $update_sql = "UPDATE products SET current_stock = current_stock + ? WHERE product_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $quantity, $product_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update product stock: " . $update_stmt->error);
    }
    $update_stmt->close();
    
    // Commit transaction
    $conn->commit();
    $conn->close();
    
    header("Location: ../update_stock.php?success=batch_added");
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $error = $e->getMessage();
    error_log("Batch addition error: " . $error);
    $conn->close();
    header("Location: ../update_stock.php?error=failed&details=" . urlencode($error));
    exit();
}
?>