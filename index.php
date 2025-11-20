<?php
session_start();
$message = '';
$message_class = '';

if (isset($_GET['error'])) {
    $message = 'Incorrect details, please try again.';
    $message_class = 'error';
} elseif (isset($_GET['success']) && $_GET['success'] === 'registered') {
    $message = 'Registered successfully! Please log in.';
    $message_class = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] === 'password_reset') {
    $message = 'Password reset successfully! Please log in.';
    $message_class = 'success';
}

$default_message = $message ? $message : 'Please enter your details.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Login</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="assets/bethel_logo.png" alt="Bethel Pharmacy" class="logo">
        </div>
        
        <div class="form-container" id="loginForm">
            <h1>WELCOME BACK</h1>
            <p class="subtitle <?php echo $message_class; ?>"><?php echo $default_message; ?></p>
            
            <form method="POST" action="includes/process.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your Username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="**********" required>
                        <span class="toggle-password" onclick="togglePassword('password')">
                            <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg class="eye-slash-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </span>
                    </div>
                </div>

                <div class="form-options">
                    <label class="checkbox-container">
                        <input type="checkbox" name="remember">
                        <span>Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="forgot-password">Forgot password?</a>
                </div>

                <button name="login" type="submit" class="btn-submit">Log in</button>

                <p class="toggle-form">Don't have an account? <a href="register.php">Sign up</a></p>
            </form>
        </div>
    </div>
    
    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const toggleBtn = input.nextElementSibling;
            const eyeIcon = toggleBtn.querySelector('.eye-icon');
            const eyeSlashIcon = toggleBtn.querySelector('.eye-slash-icon');
            
            if (input.type === 'password') {
                input.type = 'text';
                eyeIcon.style.display = 'none';
                eyeSlashIcon.style.display = 'block';
            } else {
                input.type = 'password';
                eyeIcon.style.display = 'block';
                eyeSlashIcon.style.display = 'none';
            }
        }
    </script>
    
</body>
</html>