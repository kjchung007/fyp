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

// Get user data
$edit_id = intval($_GET['id'] ?? 0);
if (!$edit_id) {
    $_SESSION['error'] = "Invalid user ID.";
    header("Location: manage_users.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $edit_id);
$stmt->execute();
$edit_user = $stmt->get_result()->fetch_assoc();

if (!$edit_user) {
    $_SESSION['error'] = "User not found.";
    header("Location: manage_users.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize_input($_POST['name']);
    $email = sanitize_input($_POST['email']);
    $phone = sanitize_input($_POST['phone'] ?? '');
    $role = sanitize_input($_POST['role']);
    $matric_no = sanitize_input($_POST['matric_no'] ?? '');
    $status = sanitize_input($_POST['status']);

    // Check email uniqueness (exclude current user)
    $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $check->bind_param("si", $email, $edit_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $error = "Another user with this email already exists.";
    } else {
        $update = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, role = ?, matric_no = ?, status = ? WHERE user_id = ?");
        $update->bind_param("ssssssi", $name, $email, $phone, $role, $matric_no, $status, $edit_id);
        if ($update->execute()) {
            $_SESSION['success'] = "User updated successfully.";
            header("Location: manage_users.php");
            exit();
        } else {
            $error = "Failed to update user. Please try again.";
        }
    }
    // Refresh user data after failed update
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
}

// Get user booking stats
$booking_stats = $conn->prepare("SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
    FROM bookings WHERE user_id = ?");
$booking_stats->bind_param("i", $edit_id);
$booking_stats->execute();
$bstats = $booking_stats->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Admin</title>
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
                        <h2>Edit User</h2>
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
                    <h2>Edit User: <?php echo htmlspecialchars($edit_user['name']); ?></h2>
                    <p>Update user details and account settings.</p>
                </div>
                <a href="manage_users.php" class="btn btn-outline" style="gap: 6px;">
                    <i data-lucide="arrow-left" style="width:16px;height:16px"></i> Back to Users
                </a>
            </div>

            <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- User Stats -->
            <div class="card-container" style="margin-bottom: 24px;">
                <div class="stat-card-modern">
                    <div class="stat-icon blue"><i data-lucide="calendar" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $bstats['total']; ?></div>
                        <div class="stat-label">Total Bookings</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon green"><i data-lucide="check-circle" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $bstats['approved']; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon orange"><i data-lucide="clock" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $bstats['pending']; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon red"><i data-lucide="x-circle" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $bstats['rejected']; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>
            </div>

            <!-- Edit Form -->
            <div class="card-row" style="display: block; padding: 28px;">
                <div class="section-heading" style="margin-bottom: 20px;">Account Information</div>
                <form method="POST" action="">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Full Name <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($edit_user['name']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email Address <span style="color:var(--danger)">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($edit_user['email']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. 012-3456789" value="<?php echo htmlspecialchars($edit_user['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Matric Number</label>
                            <input type="text" name="matric_no" class="form-control" placeholder="e.g. 75000" value="<?php echo htmlspecialchars($edit_user['matric_no'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Role <span style="color:var(--danger)">*</span></label>
                            <select name="role" class="form-control" required>
                                <option value="student" <?php echo $edit_user['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="lecturer" <?php echo $edit_user['role'] === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                                <option value="admin" <?php echo $edit_user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status <span style="color:var(--danger)">*</span></label>
                            <select name="status" class="form-control" required>
                                <option value="active" <?php echo $edit_user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $edit_user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive / Locked</option>
                            </select>
                        </div>
                    </div>

                    <div class="card-row" style="display: block; padding: 16px; margin-top: 20px; background: var(--bg-light);">
                        <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap; font-size: 13px; color: var(--text-secondary);">
                            <span><i data-lucide="calendar-plus" style="width:14px;height:14px;vertical-align:-2px"></i> Joined: <?php echo date('M j, Y', strtotime($edit_user['created_at'])); ?></span>
                            <?php if ($edit_user['updated_at']): ?>
                            <span><i data-lucide="edit" style="width:14px;height:14px;vertical-align:-2px"></i> Last edit: <?php echo date('M j, Y g:i a', strtotime($edit_user['updated_at'])); ?></span>
                            <?php endif; ?>
                            <span><i data-lucide="shield" style="width:14px;height:14px;vertical-align:-2px"></i> Current role: <span class="role-badge role-<?php echo $edit_user['role']; ?>"><?php echo ucfirst($edit_user['role']); ?></span></span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end;">
                        <a href="manage_users.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn" style="gap: 6px;">
                            <i data-lucide="save" style="width:16px;height:16px"></i> Save Changes
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
    </script>
</body>
</html>
