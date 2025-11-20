<?php
session_start();

// Check authentication and authorization
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db_connect.php';

// Validate GET request
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: ../medicine_management.php?error=invalid_request");
    exit();
}

// Sanitize product ID
$product_id = intval($_GET['id']);

// Validate product ID
if ($product_id <= 0) {
    header("Location: ../medicine_management.php?error=invalid_id");
    exit();
}

// Prepare SQL statement
$sql = "DELETE FROM products WHERE product_id = $product_id";

// Execute query
if ($conn->query($sql)) {
    // Check if any row was actually deleted
    if ($conn->affected_rows > 0) {
        header("Location: ../medicine_management.php?success=deleted");
    } else {
        header("Location: ../medicine_management.php?error=not_found");
    }
} else {
    error_log("Database error: " . $conn->error);
    header("Location: ../medicine_management.php?error=failed");
}

// Close connection
closeConnection();
?>
