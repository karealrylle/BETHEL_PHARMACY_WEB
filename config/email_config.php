<?php
function sendPasswordResetEmail($to_email, $username, $reset_link) {
    $subject = "Password Reset Request - Bethel Pharmacy";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .button { background: #01A768; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2 style='color: #01A768;'>Password Reset Request</h2>
            <p>Hello <strong>$username</strong>,</p>
            <p>You requested to reset your password. Click the button below:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='$reset_link' class='button'>Reset Password</a>
            </p>
            <p style='color: #666;'>This link will expire in 1 hour.</p>
            <p style='color: #666;'>If you didn't request this, please ignore this email.</p>
            <hr>
            <p style='color: #999; font-size: 12px;'>Best regards,<br>Bethel Pharmacy Team</p>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Bethel Pharmacy <noreply@bethelpharmacy.com>" . "\r\n";
    
    return mail($to_email, $subject, $message, $headers);
}
?>