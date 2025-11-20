<?php
require_once '../config/db_connect.php';

header('Content-Type: application/json');

$sql = "SELECT 
    p.product_id,
    p.product_name,
    p.category,
    p.price,
    COALESCE(SUM(CASE 
        WHEN pb.status = 'available' 
        AND pb.expiry_date > CURDATE() 
        AND DATEDIFF(pb.expiry_date, CURDATE()) > 365
        THEN pb.quantity 
        ELSE 0 
    END), 0) as stock
FROM products p
LEFT JOIN product_batches pb ON p.product_id = pb.product_id
GROUP BY p.product_id
HAVING stock > 0
ORDER BY p.product_name ASC";

$result = $conn->query($sql);
$products = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

echo json_encode($products);
$conn->close();
?>