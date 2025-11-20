<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'staff')) {
    header("Location: index.php");
    exit();
}

// Database connection
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$user_id = $_SESSION['user_id'];
$query = $conn->prepare("SELECT * FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$user = $query->get_result()->fetch_assoc();

// Handle form submission for updating staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_id'])) {
    if ($_SESSION['role'] === 'admin') {
        $staff_id = $_POST['staff_id'];
        $username = $_POST['username'];
        $email = $_POST['email'];
        $role = $_POST['role'];
        
        $update_query = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
        $update_query->bind_param("sssi", $username, $email, $role, $staff_id);
        
        if ($update_query->execute()) {
            $_SESSION['success_message'] = "Staff information updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating staff information: " . $conn->error;
        }
        
        // Redirect to refresh the page and show updated data
        header("Location: staff_management.php");
        exit();
    }
}

// Fetch all staff members - using only existing columns
$staff_query = $conn->prepare("SELECT id, username, role, email, status, created_at, last_login, profile_picture FROM users ORDER BY role DESC, username ASC");$staff_query->execute();
$staff_result = $staff_query->get_result();
$staff_members = [];
while ($row = $staff_result->fetch_assoc()) {
    // Generate mock shift times based on username for demo purposes
    $row['shift_time'] = generateShiftTime($row['username']);
    $row['phone'] = generateMockPhone($row['username']);
    $staff_members[] = $row;
}

$conn->close();

// Helper function to generate mock shift times
function generateShiftTime($username) {
    $shifts = [
        '8:00 am - 5:00 pm',
        '9:00 am - 6:00 pm', 
        '7:00 am - 4:00 pm',
        '8:00 am - 5:00 pm',
        '9:00 am - 6:00 pm',
        '7:00 am - 4:00 pm'
    ];
    return $shifts[crc32($username) % count($shifts)];
}

// Helper function to generate mock phone numbers
function generateMockPhone($username) {
    $prefixes = ['0912', '0917', '0927', '0939', '0947', '0950', '0961', '0977', '0989', '0999'];
    $prefix = $prefixes[crc32($username) % count($prefixes)];
    $suffix = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    return $prefix . $suffix;
}

date_default_timezone_set('Asia/Manila');
$current_date = date('l, F j, Y');
$current_time = date('h:i A');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethel Pharmacy - Staff Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/staff_management.css">
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
                <?php if (isset($user['profile_picture']) && $user['profile_picture'] && file_exists($user['profile_picture'])): ?>
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
                <a href="admin_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="medicine_management.php" class="nav-button"><img src="assets/management.png" alt="">Medicine Management</a>
                <a href="reports.php" class="nav-button"><img src="assets/reports.png" alt="">Reports</a>
                <a href="staff_management.php" class="nav-button active"><img src="assets/staff.png" alt="">Staff Management</a>
                <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button "><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button "><img src="assets/help.png" alt="">Get Technical Help</a>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>

    <main class="staff-management-container">
        <div class="staff-header">
            <h2>Staff Management</h2>
            <div class="staff-count">Total Staff Members (<?php echo count($staff_members); ?>)</div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success_message']; ?>
                <?php unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <?php echo $_SESSION['error_message']; ?>
                <?php unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <div class="staff-filters">
            <div class="filter-buttons">
                <button class="filter-btn active">ALL</button>
                <button class="filter-btn">STAFF</button>
                <button class="filter-btn">ADMIN</button>
            </div>
        </div>

        <div class="staff-grid-container">
            <div class="staff-grid">
                <?php foreach ($staff_members as $staff): ?>
                <div class="staff-card" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($staff)); ?>)">
                    <div class="staff-profile-image">
                        <?php if (isset($staff['profile_picture']) && $staff['profile_picture'] && file_exists($staff['profile_picture'])): ?>
                            <img src="<?php echo $staff['profile_picture']; ?>" alt="<?php echo htmlspecialchars($staff['username']); ?>">
                        <?php else: ?>
                            <img src="assets/user.png" alt="<?php echo htmlspecialchars($staff['username']); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="staff-content">
                        <div class="staff-header-row">
                            <div class="staff-name"><?php echo htmlspecialchars($staff['username']); ?></div>
                            <div class="staff-role-badge <?php echo strtolower($staff['role']); ?>"><?php echo ucfirst($staff['role']); ?></div>
                        </div>
                        <div class="staff-details">
                            <div class="staff-email"><?php echo htmlspecialchars($staff['email']); ?></div>
                            <?php if (!empty($staff['phone'])): ?>
                                <div class="staff-phone"><?php echo htmlspecialchars($staff['phone']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($staff['shift_time'])): ?>
                                <div class="staff-shift">Shift: <?php echo htmlspecialchars($staff['shift_time']); ?></div>
                            <?php endif; ?>
                            <div class="staff-status <?php echo strtolower($staff['status']); ?>">Status: <?php echo ucfirst($staff['status']); ?></div>
                            <div class="staff-joined">Joined: <?php echo date('M j, Y', strtotime($staff['created_at'])); ?></div>
                            <?php if (!empty($staff['last_login'])): ?>
                                <div class="staff-last-login">Last Login: <?php echo date('M j, Y g:i A', strtotime($staff['last_login'])); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- Edit Staff Modal -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
    <div id="editStaffModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Staff Information</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form id="editStaffForm" method="POST" class="modal-form">
                <input type="hidden" id="edit_staff_id" name="staff_id">
                
                <div class="form-group">
                    <label for="edit_username">Username</label>
                    <input type="text" id="edit_username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_role">Role</label>
                    <select id="edit_role" name="role" required>
                        <option value="admin">Administrator</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" required>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="cancel-btn" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="save-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Modal functions
        function openEditModal(staff) {
            <?php if ($_SESSION['role'] === 'admin'): ?>
                document.getElementById('edit_staff_id').value = staff.id;
                document.getElementById('edit_username').value = staff.username;
                document.getElementById('edit_role').value = staff.role;
                document.getElementById('edit_email').value = staff.email;
                
                document.getElementById('editStaffModal').style.display = 'block';
            <?php else: ?>
                alert('Only administrators can edit staff information.');
            <?php endif; ?>
        }
        
        function closeEditModal() {
            document.getElementById('editStaffModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editStaffModal');
            if (event.target === modal) {
                closeEditModal();
            }
        }
        
        // Filter functionality
        document.querySelectorAll('.filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Remove active class from all buttons
                document.querySelectorAll('.filter-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                
                // Add active class to clicked button
                this.classList.add('active');
                
                const filter = this.textContent;
                const staffCards = document.querySelectorAll('.staff-card');
                
                staffCards.forEach(card => {
                    if (filter === 'ALL') {
                        card.style.display = 'block';
                    } else {
                        const role = card.querySelector('.staff-role-badge').textContent.toUpperCase();
                        if (role === filter) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    }
                });
            });
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>