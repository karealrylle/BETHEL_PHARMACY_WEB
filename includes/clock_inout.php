<?php
session_start();

// Database connection
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';
$user_id = $_SESSION['id'];

if ($action === 'clock_in') {
    // Check if already clocked in today
    $check_query = "SELECT shift_id FROM staff_shifts 
                    WHERE user_id = ? 
                    AND status = 'active' 
                    AND clock_out IS NULL 
                    ORDER BY clock_in DESC 
                    LIMIT 1";
    $check = $conn->prepare($check_query);
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'You are already clocked in']);
        exit();
    }
    
    // Clock in - Insert new shift
    $insert_query = "INSERT INTO staff_shifts 
                     (user_id, shift_date, clock_in, expected_clock_out, status) 
                     VALUES (?, CURDATE(), NOW(), DATE_ADD(NOW(), INTERVAL 8 HOUR), 'active')";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Clocked in successfully', 'clocked_in' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to clock in: ' . $stmt->error]);
    }
    $stmt->close();
    
} elseif ($action === 'clock_out') {
    // Get active shift
    $select_query = "SELECT shift_id FROM staff_shifts 
                     WHERE user_id = ? 
                     AND status = 'active' 
                     AND clock_out IS NULL 
                     ORDER BY clock_in DESC 
                     LIMIT 1";
    $stmt = $conn->prepare($select_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No active shift found. Please clock in first.']);
        exit();
    }
    
    $shift = $result->fetch_assoc();
    $shift_id = $shift['shift_id'];
    
    // Clock out - Update shift
    $update_query = "UPDATE staff_shifts 
                     SET clock_out = NOW(), 
                         status = 'completed',
                         shift_duration = TIMESTAMPDIFF(MINUTE, clock_in, NOW()) - break_duration
                     WHERE shift_id = ?";
    $update = $conn->prepare($update_query);
    $update->bind_param("i", $shift_id);
    
    if ($update->execute()) {
        // Update shift stats using stored procedure
        $conn->query("CALL sp_update_shift_stats($shift_id)");
        echo json_encode(['success' => true, 'message' => 'Clocked out successfully', 'clocked_in' => false]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to clock out: ' . $update->error]);
    }
    $update->close();
    
} elseif ($action === 'check_status') {
    // Check if user has active shift
    $check_query = "SELECT shift_id, clock_in, clock_out 
                    FROM staff_shifts 
                    WHERE user_id = ? 
                    AND status = 'active' 
                    AND clock_out IS NULL 
                    ORDER BY clock_in DESC 
                    LIMIT 1";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $shift = $result->fetch_assoc();
        echo json_encode([
            'success' => true, 
            'clocked_in' => true, 
            'clock_in_time' => $shift['clock_in']
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'clocked_in' => false
        ]);
    }
    $stmt->close();
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>