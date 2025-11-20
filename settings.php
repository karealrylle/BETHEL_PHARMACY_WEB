<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
$current_date = date('l, F j, Y');
$current_time = date('h:i A');

$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Fetch current user data
$query = $conn->prepare("SELECT * FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$user = $query->get_result()->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $username = trim($_POST['username']);
    
    // Validate inputs
    if (empty($first_name) || empty($last_name) || empty($email) || empty($username)) {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    } else {
        // Check if email or username is taken by another user
        $check_query = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?");
        $check_query->bind_param("ssi", $email, $username, $user_id);
        $check_query->execute();
        
        if ($check_query->get_result()->num_rows > 0) {
            $message = "Email or username is already taken by another user.";
            $message_type = "error";
        } else {
            // Handle profile picture upload
            $profile_picture = $user['profile_picture'];
            
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $filename = $_FILES['profile_picture']['name'];
                $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($filetype, $allowed) && $_FILES['profile_picture']['size'] <= 15728640) { // 15MB
                    $new_filename = 'user_' . $user_id . '_' . time() . '.' . $filetype;
                    $upload_path = 'uploads/profiles/' . $new_filename;
                    
                    // Create directory if not exists
                    if (!file_exists('uploads/profiles')) {
                        mkdir('uploads/profiles', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                        // Delete old profile picture if exists
                        if ($profile_picture && file_exists($profile_picture)) {
                            unlink($profile_picture);
                        }
                        $profile_picture = $upload_path;
                    }
                }
            }
            
            // Handle remove picture
            if (isset($_POST['remove_picture']) && $_POST['remove_picture'] === '1') {
                if ($profile_picture && file_exists($profile_picture)) {
                    unlink($profile_picture);
                }
                $profile_picture = null;
            }
            
            // Update database
            $update_query = $conn->prepare("
                UPDATE users 
                SET username = ?, email = ?, first_name = ?, last_name = ?, phone = ?, profile_picture = ?
                WHERE id = ?
            ");
            $update_query->bind_param("ssssssi", $username, $email, $first_name, $last_name, $phone, $profile_picture, $user_id);
            
            if ($update_query->execute()) {
                // Update session
                $_SESSION['username'] = $username;
                $message = "Profile updated successfully!";
                $message_type = "success";
                
                // Refresh user data
                $query->execute();
                $user = $query->get_result()->fetch_assoc();
            } else {
                $message = "Error updating profile. Please try again.";
                $message_type = "error";
            }
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Settings</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/settings.css">
</head>
<body>
    <header class="header">
        <img src="assets/bethel_logo.png" alt="Bethel Pharmacy" class="logo">
        <div class="datetime">
            <div><?php echo $current_date; ?></div>
            <div><?php echo $current_time; ?></div>
        </div>
    </header>

    <nav class="sidebar">
        <div class="profile-container">
            <div class="profile-image">
                <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                    <img src="<?php echo $user['profile_picture']; ?>" alt="Profile">
                <?php else: ?>
                    <img src="assets/user.png" alt="User Profile">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <div class="profile-username"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                <div class="profile-role"><?php echo ucfirst($_SESSION['role']); ?></div>
            </div>
            <div class="profile-menu" onclick="this.nextElementSibling.classList.toggle('show')">&vellip;</div>
            <div class="profile-dropdown">
                <button class="dropdown-button">View Profile</button>
                <a href="index.php" style="text-decoration: none; display: contents;">
                    <button class="dropdown-button">Log out</button>
                </a>
            </div>
        </div>
        <div class="nav-buttons">
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="admin_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="medicine_management.php" class="nav-button"><img src="assets/management.png" alt="">Medicine Management</a>
                <a href="reports.php" class="nav-button"><img src="assets/reports.png" alt="">Reports</a>
                <a href="staff_management.php" class="nav-button"><img src="assets/staff.png" alt="">Staff Management</a>
                <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button active"><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php else: ?>
                <a href="staff_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
                <a href="pos.php" class="nav-button"><img src="assets/inventory.png" alt="">POS</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="shift_report.php" class="nav-button"><img src="assets/reports.png" alt="">Shift Report</a>
                <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button active"><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php endif; ?>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>

    <main class="notifications-container">
        <div class="notifications-header">
            <h2>Account Settings</h2>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="settings-card">
            <form method="POST" enctype="multipart/form-data" class="settings-form" id="settingsForm">
                <!-- Profile Picture Section -->
                <div class="profile-picture-section">
                    <div class="profile-picture-wrapper">
                        <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                            <img src="<?php echo $user['profile_picture']; ?>" alt="Profile" id="preview">
                        <?php else: ?>
                            <span class="placeholder" id="preview">👤</span>
                        <?php endif; ?>
                    </div>
                    <div class="profile-picture-label">Profile Picture</div>
                    <div class="profile-picture-hint">Insert your photo<br>(JPEG, PNG, max 15MB)</div>
                    
                    <div class="profile-picture-buttons">
                        <input type="file" id="profile_picture_input" name="profile_picture" accept="image/jpeg,image/png">
                        <button type="button" class="upload-btn" onclick="document.getElementById('profile_picture_input').click()">
                            Upload New Photo
                        </button>
                        <button type="button" class="remove-btn" onclick="removePicture()">Remove</button>
                    </div>
                    <input type="hidden" name="remove_picture" id="remove_picture" value="0">
                </div>

                <!-- Form Fields Section -->
                <div class="form-fields-section">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" 
                                   value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" 
                                   value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            <div class="form-hint">We'll send important updates to this email</div>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                   pattern="[0-9]{10,11}" placeholder="09XXXXXXXXX">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="role">Role</label>
                            <input type="text" id="role" name="role" 
                                   value="<?php echo ucfirst($user['role']); ?>" disabled>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="button-container">
                        <a href="change_password.php" class="password-btn">
                             Change Password
                        </a>
                        <button type="submit" name="update_profile" class="save-btn">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Preview uploaded image
        document.getElementById('profile_picture_input').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('preview');
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="width:100%;height:100%;object-fit:cover;">';
                }
                reader.readAsDataURL(file);
                document.getElementById('remove_picture').value = '0';
            }
        });

        // Remove picture function
        function removePicture() {
            document.getElementById('preview').innerHTML = '<span class="placeholder">👤</span>';
            document.getElementById('profile_picture_input').value = '';
            document.getElementById('remove_picture').value = '1';
        }

        // Auto-hide messages after 5 seconds
        setTimeout(function() {
            const message = document.querySelector('.message');
            if (message) {
                message.style.transition = 'opacity 0.5s';
                message.style.opacity = '0';
                setTimeout(() => message.remove(), 500);
            }
        }, 5000);
    </script>
</body>
</html>