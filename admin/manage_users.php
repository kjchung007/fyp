<?php
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$sidebar_stats = [];

// Pending items for sidebar
$sidebar_stats['pending_bookings'] = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'")->fetch_assoc()['count'];
$sidebar_stats['pending_reports'] = $conn->query("SELECT COUNT(*) as count FROM maintenance_reports WHERE status = 'pending'")->fetch_assoc()['count'];

// Get filter
$role_filter = $_GET['role'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$query = "SELECT u.*, 
          COUNT(DISTINCT b.booking_id) as total_bookings,
          COUNT(DISTINCT CASE WHEN b.status = 'pending' THEN b.booking_id END) as pending_bookings
          FROM users u
          LEFT JOIN bookings b ON u.user_id = b.user_id
          WHERE u.status = 'active'";

if ($role_filter) {
    $query .= " AND u.role = '" . sanitize_input($role_filter) . "'";
}

if ($search) {
    $search_term = sanitize_input($search);
    $query .= " AND (u.name LIKE '%{$search_term}%' OR u.email LIKE '%{$search_term}%' OR u.matric_no LIKE '%{$search_term}%')";
}

$query .= " GROUP BY u.user_id ORDER BY u.created_at DESC";

$users = $conn->query($query);

// Get statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'],
    'students' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND status = 'active'")->fetch_assoc()['count'],
    'lecturers' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'lecturer' AND status = 'active'")->fetch_assoc()['count'],
    'admins' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active'")->fetch_assoc()['count']
];

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin</title>
    <link rel="stylesheet" href="../style.css">
    <script>
        function resetPassword(userId, userName) {
            if (confirm('Reset password for ' + userName + '?\n\nNew password will be: student123')) {
                window.location.href = '../actions/admin_user_action.php?action=reset_password&id=' + userId;
            }
        }
        
        function toggleUserStatus(userId, userName, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            if (confirm('Change ' + userName + ' status to ' + newStatus + '?')) {
                window.location.href = '../actions/admin_user_action.php?action=toggle_status&id=' + userId;
            }
        }
        
        function deleteUser(userId, userName) {
            if (confirm('Are you sure you want to DELETE ' + userName + '?\n\nThis will remove:\n- All their bookings\n- All their reports\n- All their data\n\nThis action cannot be undone!')) {
                window.location.href = '../actions/admin_user_action.php?action=delete&id=' + userId;
            }
        }
    </script>
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
                    <span class="sidebar-badge" style="background:var(--warning)"><?php echo $stats['pending_reports']; ?></span>
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
                        <h2>Manage Users</h2>
                    </div>
                </div>
                <div class="header-right">
                    <!-- Notifications -->
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
                                <?php if ($notif_count > 0): ?>
                                <a href="#" class="mark-all-read" onclick="markAllNotificationsRead(event)">Mark all as read</a>
                                <?php endif; ?>
                            </div>
                            <div id="notificationList">
                                <?php
                                $notif_list_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
                                $notif_list_stmt = $conn->prepare($notif_list_query);
                                $notif_list_stmt->bind_param("i", $user_id);
                                $notif_list_stmt->execute();
                                $notif_list_result = $notif_list_stmt->get_result();
                                
                                if ($notif_list_result->num_rows > 0):
                                    while ($notif = $notif_list_result->fetch_assoc()):
                                ?>
                                <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['notification_id']; ?>" onclick="markNotificationRead(<?php echo $notif['notification_id']; ?>, this)">
                                    <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notification-time"><?php echo date('M j, g:i a', strtotime($notif['created_at'])); ?></div>
                                </div>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <div class="empty-notifications">
                                    <i data-lucide="bell-off"></i>
                                    <p>No notifications yet</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile -->
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
                            <a href="profile.php" class="dropdown-item">
                                <i data-lucide="user"></i>
                                <span>My Profile</span>
                            </a>
                            <a href="settings.php" class="dropdown-item">
                                <i data-lucide="settings"></i>
                                <span>Settings</span>
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="../actions/logout.php" class="dropdown-item danger">
                                <i data-lucide="log-out"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div class="admin-header" style="margin-bottom: 0;">
                    <h2>Manage Users</h2>
                    <p>View and manage all system users.</p>
                </div>

                <a href="add_admin.php" class="btn" style="gap: 6px;">
                    <i data-lucide="plus" style="width:16px;height:16px"></i> Add Admin
                </a>
            </div>
            
            <?php
            if (isset($_SESSION['success'])) {
                echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
                unset($_SESSION['success']);
            }
            if (isset($_SESSION['error'])) {
                echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
                unset($_SESSION['error']);
            }
            ?>
            
            <!-- Statistics -->
            <div class="card-container">
                <div class="stat-card-modern">
                    <div class="stat-icon purple"><i data-lucide="users" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon blue"><i data-lucide="graduation-cap" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['students']; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon green"><i data-lucide="book-open" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['lecturers']; ?></div>
                        <div class="stat-label">Lecturers</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon red"><i data-lucide="shield-check" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['admins']; ?></div>
                        <div class="stat-label">Administrators</div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-sidebar">
                <form method="GET" action="manage_users.php">
                    <div style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 12px; align-items: end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Role</label>
                            <select name="role" class="form-control">
                                <option value="">All Roles</option>
                                <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="lecturer" <?php echo $role_filter === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                                <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Name, email, or matric number..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn">Apply</button>
                            <a href="manage_users.php" class="btn btn-outline">Clear</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Users as Card List -->
            <div class="section-heading">User Accounts (<?php echo $users->num_rows; ?> found)</div>
            <div class="card-list">
                <?php while ($user = $users->fetch_assoc()): ?>
                <div class="card-row">
                    <div class="card-row-left">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['name'], 0, 1) . (strpos($user['name'], ' ') !== false ? substr($user['name'], strpos($user['name'], ' ') + 1, 1) : '')); ?>
                        </div>
                        <div class="card-row-info">
                            <h3>
                                <?php echo htmlspecialchars($user['name']); ?>
                                <span class="role-badge role-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                                <?php if ($user['status'] !== 'active'): ?>
                                <span class="status-badge status-inactive">Locked</span>
                                <?php endif; ?>
                            </h3>
                            <div class="card-row-meta">
                                <span><?php echo htmlspecialchars($user['email']); ?></span>
                                <?php if ($user['matric_no']): ?>
                                <span><?php echo htmlspecialchars($user['matric_no']); ?></span>
                                <?php endif; ?>
                                <span><?php echo $user['total_bookings']; ?> bookings<?php if ($user['pending_bookings'] > 0) echo ' · <span style="color:var(--warning)">' . $user['pending_bookings'] . ' pending</span>'; ?></span>
                                <span>Joined <?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="card-row-right">
                        <a href="edit_user.php?id=<?php echo $user['user_id']; ?>" class="btn-icon" title="Edit"><i data-lucide="pencil" style="width:15px;height:15px"></i></a>
                        <button onclick="resetPassword(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>')" class="btn-icon" title="Reset Password"><i data-lucide="key-round" style="width:15px;height:15px"></i></button>
                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                        <button onclick="toggleUserStatus(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>', '<?php echo $user['status']; ?>')" class="btn-icon warning" title="Toggle Status"><i data-lucide="<?php echo $user['status'] === 'active' ? 'lock' : 'unlock'; ?>" style="width:15px;height:15px"></i></button>
                        <button onclick="deleteUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>')" class="btn-icon danger" title="Delete"><i data-lucide="trash-2" style="width:15px;height:15px"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
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
        
        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!profileDropdown.contains(e.target)) profileDropdown.classList.remove('active');
            if (!notificationDropdown.contains(e.target)) notificationDropdown.classList.remove('active');
        });
        
        // Mark notification as read
        function markNotificationRead(id, element) {
            fetch('actions/mark_notification_read.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    element.classList.remove('unread');
                    const badge = document.querySelector('.notification-badge');
                    if (badge) {
                        let count = parseInt(badge.textContent);
                        count > 1 ? badge.textContent = count - 1 : badge.remove();
                    }
                }
            });
        }
        
        // Mark all as read
        function markAllNotificationsRead(event) {
            if (event) event.preventDefault();
            fetch('actions/mark_all_notifications_read.php', {method: 'POST'})
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item').forEach(item => item.classList.remove('unread'));
                    const badge = document.querySelector('.notification-badge');
                    if (badge) badge.remove();
                }
            });
        }
    </script>
</body>
</html>
