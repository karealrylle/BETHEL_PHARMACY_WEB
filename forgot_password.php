<?php
session_start();
$message = '';
$message_class = '';
$show_reset_link = false;
$reset_link = '';

if (isset($_GET['error'])) {
    if ($_GET['error'] == 'not_found') {
        $message = 'Email not found. Please try again.';
    } elseif ($_GET['error'] == 'email_failed') {
        $message = 'Failed to send email. Please try again later.';
    } else {
        $message = 'An error occurred. Please try again.';
    }
    $message_class = 'error';
} elseif (isset($_GET['success'])) {
    $message = 'Email sent successfully!';
    $message_class = 'success';
    $show_reset_link = true;
    
    if (isset($_SESSION['temp_reset_link'])) {
        $reset_link = $_SESSION['temp_reset_link'];
        unset($_SESSION['temp_reset_link']); 
    }
}

$default_message = $message ? $message : 'Enter your email to reset password.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Forgot Password</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="assets/bethel_logo.png" alt="Bethel Pharmacy" class="logo">
        </div>
        
        <div class="form-container">
            <h1>FORGOT PASSWORD</h1>
            <p class="subtitle <?php echo $message_class; ?>"><?php echo $default_message; ?></p>
            
            <?php if ($show_reset_link && !empty($reset_link)): ?>
                <div style="background: #e1f3e2ff; padding: 15px; border-radius: 16px; margin: 10px 0; text-align: center;">
                    <p style="margin: 0 0 5px 0; font-weight: 500; color: #29313dff;"> Click the link below to reset your password.</p>
                    <a href="<?php echo htmlspecialchars($reset_link); ?>" 
                       style="color: #008f58ff; word-break: break-all; text-decoration: underline;">
                        Reset Password 
                    </a>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="includes/process.php">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your registered email" required>
                </div>
                
                <button name="forgot_password" type="submit" class="btn-submit">Send Reset Link</button>
                
                <p class="toggle-form"><a href="index.php">← Back to Login</a></p>
            </form>
        </div>
    </div>
</body>
</html>