<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'staff')) {
    header("Location: index.php");
    exit();
}

// Add this to pages like admin_dashboard.php, staff_dashboard.php, inventory.php, etc.
$conn = new mysqli("localhost", "root", "", "bethel_pharmacy");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$user_id = $_SESSION['user_id'];
$query = $conn->prepare("SELECT * FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$user = $query->get_result()->fetch_assoc();

$conn->close();

date_default_timezone_set('Asia/Manila');
$current_date = date('l, F j, Y');
$current_time = date('h:i A');
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
    <link rel="stylesheet" href="css/help.css">
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
                <a href="settings.php" class="nav-button "><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button active"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php else: ?>
                <a href="staff_dashboard.php" class="nav-button"><img src="assets/dashboard.png" alt="">Dashboard</a>
                <a href="pos.php" class="nav-button"><img src="assets/inventory.png" alt="">POS</a>
                <a href="inventory.php" class="nav-button"><img src="assets/inventory.png" alt="">Inventory</a>
                <a href="shift_report.php" class="nav-button"><img src="assets/reports.png" alt="">Shift Report</a>
                <a href="notifications.php" class="nav-button"><img src="assets/notifs.png" alt="">Notifications</a>
                <a href="settings.php" class="nav-button "><img src="assets/settings.png" alt="">Settings</a>
                <a href="help.php" class="nav-button active"><img src="assets/help.png" alt="">Get Technical Help</a>
            <?php endif; ?>
        </div>
        <div class="sidebar-credits">Made by ACT-2C G5 © 2025-2026</div>
    </nav>

    <main class="notifications-container">
        <div class="help-header">
            <h2>Get Technical Help</h2>
        </div>

        <div class="help-content-wrapper">
            <!-- System Status Section -->
            <div class="help-section">
                <h3>System Status</h3>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="status-label">Network Connection</span>
                        <span class="status-indicator online">Online</span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">Receipt Printer</span>
                        <span class="status-indicator online">Online</span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">Database Server</span>
                        <span class="status-indicator online">Online</span>
                    </div>
                </div>
            </div>

            <!-- Support Ticket and FAQ Side by Side -->
            <div class="two-column-layout">
                <!-- Support Ticket Form -->
                <div class="help-section">
                    <h3>Submit Support Ticket</h3>
                    <p class="section-subtitle">Report a technical issue or request assistance</p>
                    <form class="support-form">
                        <div class="form-group">
                            <label for="issue_title">Issue Title</label>
                            <input type="text" id="issue_title" placeholder="Brief description of the issue...">
                        </div>
                        
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category">
                                <option value="">Select category</option>
                                <option value="pos">Point of Sale System</option>
                                <option value="inventory">Inventory Management</option>
                                <option value="reports">Reports & Analytics</option>
                                <option value="hardware">Hardware Issues</option>
                                <option value="network">Network Problems</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Issue Description</label>
                            <textarea id="description" placeholder="Describe the issue in detail..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="attachment">Attach Screenshots (Optional)</label>
                            <input type="file" id="attachment" accept="image/*">
                        </div>
                        
                        <button type="submit" class="submit-ticket-btn">Submit Ticket</button>
                    </form>
                </div>

                <!-- FAQ Section -->
                <div class="help-section">
                    <h3>Frequently Asked Questions</h3>
                    <div class="faq-list">
                        <div class="faq-item">
                            <div class="faq-question">How do I reset my password?</div>
                            <div class="faq-answer">Go to Settings → Security → Change Password to reset your password.</div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question">What should I do if the receipt printer is jammed?</div>
                            <div class="faq-answer">Turn off the printer, clear any paper jams, and restart the system.</div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question">How do I submit a technical support ticket?</div>
                            <div class="faq-answer">Use the form above to submit a detailed description of your issue.</div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question">What is the Quick Inventory Overview?</div>
                            <div class="faq-answer">It shows stock levels, expiring items, and products needing reorder.</div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question">What information is shown on the Shift Report tab?</div>
                            <div class="faq-answer">Sales performance, transactions processed, and shift duration.</div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question">How does the Clock In/Out system work?</div>
                            <div class="faq-answer">Click Clock In when starting your shift and Clock Out when ending.</div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question">What alerts does the system provide?</div>
                            <div class="faq-answer">Low stock, expired products, and system maintenance alerts.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Troubleshooting Tips -->
            <div class="help-section">
                <h3>Quick Troubleshooting Tips</h3>
                <div class="tips-grid">
                    <div class="tip-card">
                        <h4>Restart Device</h4>
                        <p>Try restarting the device. Most issues can be resolved with a simple restart.</p>
                    </div>
                    <div class="tip-card">
                        <h4>Check Connections</h4>
                        <p>Check cable connections. Ensure all cables are properly connected.</p>
                    </div>
                    <div class="tip-card">
                        <h4>Document Issue</h4>
                        <p>Document the issue. Take photos or screenshots if possible.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // FAQ toggle functionality
        document.querySelectorAll('.faq-question').forEach(question => {
            question.addEventListener('click', () => {
                const answer = question.nextElementSibling;
                const isActive = question.classList.contains('active');
                
                // Close all FAQ items
                document.querySelectorAll('.faq-question').forEach(q => {
                    q.classList.remove('active');
                });
                document.querySelectorAll('.faq-answer').forEach(ans => {
                    ans.classList.remove('active');
                });
                
                // If this FAQ wasn't active, open it
                if (!isActive) {
                    question.classList.add('active');
                    answer.classList.add('active');
                }
            });
        });

        // Support ticket form submission
        document.querySelector('.support-form').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Support ticket submitted successfully! Our team will contact you within 24 hours.');
            this.reset();
        });
    </script>
</body>
</html>