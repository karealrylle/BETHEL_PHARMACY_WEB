<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

// Fetch ALL batches regardless of status
$sql = "SELECT batch_id, batch_number, quantity, expiry_date, status, manufactured_date, supplier_name
        FROM product_batches 
        WHERE product_id = ? AND quantity > 0
        ORDER BY expiry_date ASC, batch_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

$batches = [];
$total_stock = 0;
$active_count = 0;

$today = strtotime(date('Y-m-d'));

while($row = $result->fetch_assoc()) {
    $expiry_timestamp = strtotime($row['expiry_date']);
    $days_left = ($expiry_timestamp - $today) / (60 * 60 * 24);
    $months_left = $days_left / 30;
    
    // Only count as active if: status is 'available' AND not expired AND more than 12 months left
    if ($row['status'] == 'available' && $expiry_timestamp > $today && $months_left >= 12) {
        $total_stock += $row['quantity'];
        $active_count++;
    }
    
    // Add ALL batches to the array (they will all be displayed)
    $batches[] = $row;
}

echo json_encode([
    'success' => true,
    'batches' => $batches,
    'total_stock' => $total_stock,
    'active_count' => $active_count
]);

$stmt->close();
$conn->close();