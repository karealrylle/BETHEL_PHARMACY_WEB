<?php
session_start();

// Check authentication and authorization
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db_connect.php';

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../medicine_management.php?error=invalid_request");
    exit();
}

// Sanitize and validate inputs
$product_id = intval($_POST['product_id']);
$product_name = $conn->real_escape_string(trim($_POST['product_name']));
$category = $conn->real_escape_string(trim($_POST['category']));
$price = floatval($_POST['price']);
$how_to_use = $conn->real_escape_string(trim($_POST['how_to_use']));
$side_effects = $conn->real_escape_string(trim($_POST['side_effects']));

// Validate required fields
if ($product_id <= 0 || empty($product_name) || empty($category) || $price <= 0) {
    header("Location: ../medicine_management.php?error=invalid_data");
    exit();
}

// Prepare SQL statement - only update fields that exist in the form
$sql = "UPDATE products SET 
            product_name = '$product_name', 
            category = '$category', 
            price = $price, 
            how_to_use = '$how_to_use', 
            side_effects = '$side_effects' 
        WHERE product_id = $product_id";

// Execute query
if ($conn->query($sql)) {
    header("Location: ../medicine_management.php?success=updated");
} else {
    error_log("Database error: " . $conn->error);
    header("Location: ../medicine_management.php?error=failed");
}

// Close connection
closeConnection();
?>