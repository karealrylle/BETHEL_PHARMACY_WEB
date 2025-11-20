<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$batch_id = isset($input['batch_id']) ? intval($input['batch_id']) : 0;
$reason = isset($input['reason']) ? $input['reason'] : 'expiry';

if ($batch_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid batch ID']);
    exit();
}

$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Get batch details first
    $batch_query = $conn->prepare("SELECT product_id, quantity FROM product_batches WHERE batch_id = ?");
    $batch_query->bind_param("i", $batch_id);
    $batch_query->execute();
    $batch_result = $batch_query->get_result();
    
    if ($batch_result->num_rows === 0) {
        throw new Exception("Batch not found");
    }
    
    $batch_data = $batch_result->fetch_assoc();
    $product_id = $batch_data['product_id'];
    $quantity = $batch_data['quantity'];
    
    // Record the movement
    $movement_query = $conn->prepare("INSERT INTO batch_movements (batch_id, movement_type, quantity, movement_date, performed_by, notes) VALUES (?, 'expiry', ?, NOW(), ?, ?)");
    $user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 1;
    $notes = "Batch disposed due to " . $reason;
    $movement_query->bind_param("iiis", $batch_id, $quantity, $user_id, $notes);
    $movement_query->execute();
    
    // Update batch status to disposed
    $update_query = $conn->prepare("UPDATE product_batches SET status = 'disposed', quantity = 0 WHERE batch_id = ?");
    $update_query->bind_param("i", $batch_id);
    $update_query->execute();
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Batch disposed successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>