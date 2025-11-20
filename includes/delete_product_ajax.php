<?php
session_start();

header('Content-Type: application/json');

// Only admins can delete products
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../config/db_connect.php';

// Get product_id from POST data
$product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
    closeConnection();
    exit();
}

// First check if product exists
$check_sql = "SELECT product_id FROM products WHERE product_id = $product_id";
$result = $conn->query($check_sql);

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    closeConnection();
    exit();
}

// Delete the product
$sql = "DELETE FROM products WHERE product_id = $product_id";
if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
} else {
    error_log('DB error: ' . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}

closeConnection();
exit();
?>