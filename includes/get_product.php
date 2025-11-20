<?php
session_start();

header('Content-Type: application/json');

// Only allow admin users to fetch product details for editing
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../config/db_connect.php';

if (!isset($_GET['product_id']) || empty($_GET['product_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing product_id']);
    closeConnection();
    exit();
}

$product_id = intval($_GET['product_id']);
if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
    closeConnection();
    exit();
}

$sql = "SELECT product_id, product_name, category, price, current_stock, manufactured_date, expiry_date, how_to_use, side_effects FROM products WHERE product_id = $product_id LIMIT 1";
$res = $conn->query($sql);
if (!$res) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    closeConnection();
    exit();
}

if ($res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Not found']);
    closeConnection();
    exit();
}

$row = $res->fetch_assoc();

echo json_encode(['success' => true, 'product' => $row]);
closeConnection();
exit();
?>
