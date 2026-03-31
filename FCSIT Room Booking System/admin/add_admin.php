<?php
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$sidebar_stats = [];
$sidebar_stats['pending_bookings'] = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'")->fetch_assoc()['count'];
$sidebar_stats['pending_reports'] = $conn->query("SELECT COUNT(*) as count FROM maintenance_reports WHERE status = 'pending'")->fetch_assoc()['count'];

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize_input($_POST['name']);
    $email = sanitize_input($_POST['email']);
    $phone = sanitize_input($_POST['phone'] ?? '');
    $role = sanitize_input($_POST['role']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Check if email already exists
    $check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $error = "A user with this email already exists.";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, role, password, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("sssss", $name, $email, $phone, $role, $password);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Admin account created successfully.";
            header("Location: manage_users.php");
            exit();
        } else {
            $error = "Failed to create account. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Admin - Admin</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h3>FCSIT</h3>
                <p>
                    Room Booking Administrative System
                    <br><i data-lucide="user" style="width:13px;height:13px"></i>  <?php echo htmlspecialchars($_SESSION['name']); ?>
                </p>
            </div>
            <ul class="list-unstyled components">
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> <span>Dashboard</span></a></li>
                <li><a href="manage_bookings.php"><i data-lucide="calendar-check"></i> <span>Manage Bookings</span>
                    <?php if ($sidebar_stats['pending_bookings'] > 0): ?>
                    <span class="sidebar-badge"><?php echo $sidebar_stats['pending_bookings']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="manage_rooms.php"><i data-lucide="door-open"></i> <span>Manage Rooms</span></a></li>
                <li class="active"><a href="manage_users.php"><i data-lucide="users"></i> <span>Manage Users</span></a></li>
                <li><a href="maintenance_reports.php"><i data-lucide="wrench"></i> <span>Maintenance</span>
                    <?php if ($sidebar_stats['pending_reports'] > 0): ?>
                    <span class="sidebar-badge" style="background:var(--warning)"><?php echo $sidebar_stats['pending_reports']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="system_logs.php"><i data-lucide="scroll-text"></i> <span>System Logs</span></a></li>
                <li><a href="calendar.php"><i data-lucide="calendar-days"></i> <span>Calendar View</span></a></li>
                <li><a href="analytics.php"><i data-lucide="bar-chart-3"></i> <span>Analytics</span></a></li>
                <li><a href="../dashboard.php"><i data-lucide="arrow-left"></i> <span>User View</span></a></li>
            </ul>
        </nav>
        
        <div id="content">
            <div class="top-header">
                <div class="header-left">
                    <div class="toggle-sidebar" id="toggleSidebar">
                        <i data-lucide="menu"></i>
                    </div>
                    <div class="page-title">
                        <h2>Add Admin</h2>
                    </div>
                </div>
                <div class="header-right">
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="header-icon-btn" id="notificationTrigger">
                            <i data-lucide="bell"></i>
                            <?php if ($notif_count > 0): ?>
                            <span class="notification-badge"><?php echo $notif_count > 9 ? '9+' : $notif_count; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="notification-menu">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                            </div>
                            <div id="notificationList"></div>
                        </div>
                    </div>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="profile-trigger" id="profileTrigger">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                            </div>
                            <div class="profile-info">
                                <span class="profile-name"><?php echo htmlspecialchars(explode(' ', $_SESSION['name'])[0]); ?></span>
                                <span class="profile-role"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></span>
                            </div>
                            <i data-lucide="chevron-down" class="dropdown-arrow"></i>
                        </div>
                        <div class="dropdown-menu">
                            <div class="dropdown-header">
                                <div class="dropdown-name"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
                                <div class="dropdown-email"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
                            </div>
                            <a href="profile.php" class="dropdown-item"><i data-lucide="user"></i><span>My Profile</span></a>
                            <a href="settings.php" class="dropdown-item"><i data-lucide="settings"></i><span>Settings</span></a>
                            <div class="dropdown-divider"></div>
                            <a href="../actions/logout.php" class="dropdown-item danger"><i data-lucide="log-out"></i><span>Logout</span></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div class="admin-header" style="margin-bottom: 0;">
                    <h2>Add Admin Account</h2>
                    <p>Create a new administrator or lecturer account.</p>
                </div>
                <a href="manage_users.php" class="btn btn-outline" style="gap: 6px;">
                    <i data-lucide="arrow-left" style="width:16px;height:16px"></i> Back to Users
                </a>
            </div>

            <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="card-row" style="display: block; padding: 28px;">
                <form method="POST" action="">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Full Name <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Dr. Ahmad bin Ali" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email Address <span style="color:var(--danger)">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. ahmad@unimas.my" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. 012-3456789" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Role <span style="color:var(--danger)">*</span></label>
                            <select name="role" class="form-control" required>
                                <option value="">Select Role</option>
                                <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                <option value="lecturer" <?php echo (($_POST['role'] ?? '') === 'lecturer') ? 'selected' : ''; ?>>Lecturer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Password <span style="color:var(--danger)">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password <span style="color:var(--danger)">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required minlength="6">
                        </div>
                    </div>

                    <div class="info-box info" style="margin-top: 20px;">
                        <i data-lucide="info" style="width:16px;height:16px;flex-shrink:0"></i>
                        <span>Admin accounts have full system access. Lecturer accounts can manage their own bookings and view analytics.</span>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end;">
                        <a href="manage_users.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn" style="gap: 6px;">
                            <i data-lucide="user-plus" style="width:16px;height:16px"></i> Create Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        
        // Sidebar Toggle
        const toggleBtn = document.getElementById('toggleSidebar');
        const wrapper = document.querySelector('.wrapper');
        
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            wrapper.classList.add('sidebar-collapsed');
        }
        
        toggleBtn.addEventListener('click', function() {
            wrapper.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', wrapper.classList.contains('sidebar-collapsed'));
            setTimeout(() => lucide.createIcons(), 100);
        });
        
        // Profile Dropdown
        const profileDropdown = document.getElementById('profileDropdown');
        const profileTrigger = document.getElementById('profileTrigger');
        
        profileTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('active');
            notificationDropdown.classList.remove('active');
        });
        
        // Notification Dropdown
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationTrigger = document.getElementById('notificationTrigger');
        
        notificationTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('active');
            profileDropdown.classList.remove('active');
        });
        
        document.addEventListener('click', function(e) {
            if (!profileDropdown.contains(e.target)) profileDropdown.classList.remove('active');
            if (!notificationDropdown.contains(e.target)) notificationDropdown.classList.remove('active');
        });

        // Password match validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const pw = document.querySelector('input[name="password"]').value;
            const cpw = document.querySelector('input[name="confirm_password"]').value;
            if (pw !== cpw) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>
