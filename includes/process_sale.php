<?php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['id']) && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get user ID (support both session keys)
$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID not found']);
    exit();
}

// Get and validate input data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['items']) || empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    $total_amount = floatval($data['total_amount']);
    $payment_method = $conn->real_escape_string($data['payment_method']);
    $amount_paid = floatval($data['amount_paid']);
    $change_amount = floatval($data['change_amount']);
    $customer_name = isset($data['customer_name']) ? $conn->real_escape_string($data['customer_name']) : null;
    
    // Insert into sales table
    $stmt = $conn->prepare("INSERT INTO sales (user_id, customer_name, total_amount, payment_method, amount_paid, change_amount) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isdsdd", $user_id, $customer_name, $total_amount, $payment_method, $amount_paid, $change_amount);
    $stmt->execute();
    $sale_id = $conn->insert_id;
    $stmt->close();
    
    // Insert sale items and process FIFO deduction
    foreach ($data['items'] as $item) {
        $product_id = intval($item['product_id']);
        $quantity = intval($item['quantity']);
        $unit_price = floatval($item['price']);
        $subtotal = $unit_price * $quantity;
        
        // Check available stock first
        $stock_check = $conn->prepare("SELECT fn_get_available_stock(?) as available_stock");
        $stock_check->bind_param("i", $product_id);
        $stock_check->execute();
        $stock_result = $stock_check->get_result();
        $stock_row = $stock_result->fetch_assoc();
        $available_stock = $stock_row['available_stock'];
        $stock_check->close();
        
        if ($available_stock < $quantity) {
            throw new Exception("Insufficient stock for product ID: $product_id");
        }
        
        // Insert sale item
        $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiidd", $sale_id, $product_id, $quantity, $unit_price, $subtotal);
        $stmt->execute();
        $stmt->close();
        
        // Process FIFO deduction using stored procedure
        $stmt = $conn->prepare("CALL sp_process_sale_fifo(?, ?, ?, ?)");
        $stmt->bind_param("iiii", $sale_id, $product_id, $quantity, $user_id);
        $stmt->execute();
        $stmt->close();
        
        // Need to close and reopen connection for next stored procedure call
        $conn->next_result();
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Sale processed successfully',
        'sale_id' => $sale_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>