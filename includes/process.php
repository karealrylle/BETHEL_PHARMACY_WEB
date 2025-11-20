<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
// Include database connection
require_once __DIR__ . '/../config/db_connect.php';

// Check which form was submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // REGISTER PROCESS
    if (isset($_POST['register'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate passwords match
        if ($password !== $confirm_password) {
            header("Location: ../register.php?error=password_mismatch");
            exit();
        }
        
        // Validate password strength (minimum 8 characters)
        if (strlen($password) < 8) {
            header("Location: ../register.php?error=weak_password");
            exit();
        }
        
        // Check if username already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            header("Location: ../register.php?error=exists");
            exit();
        }
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user (default role is 'staff')
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'staff')");
        $stmt->bind_param("sss", $username, $email, $hashed_password);
        
        if ($stmt->execute()) {
            header("Location: ../index.php?success=registered");
            exit();
        } else {
            header("Location: ../register.php?error=failed");
            exit();
        }
        
        $stmt->close();
        $check_stmt->close();
    }
    
    // LOGIN PROCESS
    elseif (isset($_POST['login'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);
        
        // Prepare statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT id, username, email, password, role, status FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                
                // Check if account is active
                if ($user['status'] !== 'active') {
                    header("Location: ../index.php?error=inactive");
                    exit();
                }
                
                // Set session variables
                $_SESSION['id'] = $user['id'];     
                $_SESSION['user_id'] = $user['id'];     
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;
                
                // Update last login
                $update_stmt = $conn->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();
                
                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header("Location: ../admin_dashboard.php");
                } else {
                    header("Location: ../staff_dashboard.php");
                }
                exit();
                
            } else {
                // Invalid password
                header("Location: ../index.php?error=invalid");
                exit();
            }
        } else {
            // User not found
            header("Location: ../index.php?error=invalid");
            exit();
        }
        
        $stmt->close();
    }
    
    // FORGOT PASSWORD PROCESS
    elseif (isset($_POST['forgot_password'])) {
        $email = trim($_POST['email']);
        
        $query = "SELECT id, username FROM users WHERE email = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in database
            $update = "UPDATE users SET reset_token = ?, reset_expiry = ? WHERE email = ?";
            $stmt2 = $conn->prepare($update);
            $stmt2->bind_param("sss", $token, $expiry, $email);
            
            if ($stmt2->execute()) {
                // Create reset link
                $reset_link = "http://localhost/bethel_pharmacy/reset_password.php?token=$token&email=" . urlencode($email);
                
                // For development: Store link in session to display on page
                $_SESSION['temp_reset_link'] = $reset_link;
                
                // Try to send email (will fail on XAMPP but that's okay)
                if (file_exists('../config/email_config.php')) {
                    require_once '../config/email_config.php';
                    if (function_exists('sendPasswordResetEmail')) {
                        sendPasswordResetEmail($email, $user['username'], $reset_link);
                    }
                }
                
                header("Location: ../forgot_password.php?success=1");
            } else {
                header("Location: ../forgot_password.php?error=failed");
            }
            $stmt2->close();
        } else {
            header("Location: ../forgot_password.php?error=not_found");
        }
        $stmt->close();
        exit();
    }
    
    // RESET PASSWORD PROCESS
    elseif (isset($_POST['reset_password'])) {
        $token = $_POST['token'];
        $email = $_POST['email'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify token from database
        $stmt = $conn->prepare("SELECT reset_token, reset_expiry FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            header("Location: ../index.php?error=invalid_token");
            exit();
        }
        
        $user = $result->fetch_assoc();
        
        // Check if token matches and hasn't expired
        if ($user['reset_token'] !== $token || strtotime($user['reset_expiry']) < time()) {
            header("Location: ../index.php?error=expired_token");
            exit();
        }
        
        // Validate passwords match
        if ($new_password !== $confirm_password) {
            header("Location: ../reset_password.php?token=$token&email=$email&error=password_mismatch");
            exit();
        }
        
        // Validate password strength
        if (strlen($new_password) < 8) {
            header("Location: ../reset_password.php?token=$token&email=$email&error=weak_password");
            exit();
        }
        
        // Hash new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password and clear reset token
        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE email = ?");
        $stmt->bind_param("ss", $hashed_password, $email);
        
        if ($stmt->execute()) {
            header("Location: ../index.php?success=password_reset");
            exit();
        } else {
            header("Location: ../reset_password.php?token=$token&email=$email&error=failed");
            exit();
        }
        
        $stmt->close();
    }
}

$conn->close();
?>