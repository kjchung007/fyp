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

// Get filters
$action_filter = $_GET['action'] ?? '';
$date_filter = $_GET['date'] ?? '';
$limit = intval($_GET['limit'] ?? 100);

// Build query
$query = "SELECT sl.*, u.name as user_name, u.email
          FROM system_logs sl
          LEFT JOIN users u ON sl.user_id = u.user_id
          WHERE 1=1";

if ($action_filter) {
    $query .= " AND sl.action_type = '" . sanitize_input($action_filter) . "'";
}

if ($date_filter) {
    $query .= " AND DATE(sl.created_at) = '" . sanitize_input($date_filter) . "'";
}

$query .= " ORDER BY sl.created_at DESC LIMIT " . $limit;

$logs = $conn->query($query);

// Get unique action types for filter
$actions_query = "SELECT DISTINCT action_type FROM system_logs ORDER BY action_type";
$actions = $conn->query($actions_query);

// Get statistics
$stats = [
    'total_today' => $conn->query("SELECT COUNT(*) as count FROM system_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'],
    'login_success' => $conn->query("SELECT COUNT(*) as count FROM system_logs WHERE action_type = 'LOGIN_SUCCESS' AND DATE(created_at) = CURDATE()")->fetch_assoc()['count'],
    'login_failed' => $conn->query("SELECT COUNT(*) as count FROM system_logs WHERE action_type = 'LOGIN_FAILED' AND DATE(created_at) = CURDATE()")->fetch_assoc()['count'],
    'bookings_today' => $conn->query("SELECT COUNT(*) as count FROM system_logs WHERE action_type = 'BOOKING_CREATED' AND DATE(created_at) = CURDATE()")->fetch_assoc()['count']
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
    <title>System Logs - Admin</title>
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
                <li><a href="manage_users.php"><i data-lucide="users"></i> <span>Manage Users</span></a></li>
                <li><a href="maintenance_reports.php"><i data-lucide="wrench"></i> <span>Maintenance</span>
                    <?php if ($sidebar_stats['pending_reports'] > 0): ?>
                    <span class="sidebar-badge" style="background:var(--warning)"><?php echo $stats['pending_reports']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li class="active"><a href="system_logs.php"><i data-lucide="scroll-text"></i> <span>System Logs</span></a></li>
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
                        <h2>System Logs</h2>
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
            <div class="admin-header">
                <h2>System Activity Logs</h2>
                <p>Audit trail of all user and system actions.</p>
            </div>
            
            <!-- Statistics -->
            <div class="card-container">
                <div class="stat-card-modern">
                    <div class="stat-icon purple"><i data-lucide="activity" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['total_today']; ?></div>
                        <div class="stat-label">Today's Activity</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon green"><i data-lucide="log-in" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['login_success']; ?></div>
                        <div class="stat-label">Successful Logins</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon red"><i data-lucide="shield-off" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['login_failed']; ?></div>
                        <div class="stat-label">Failed Logins</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon blue"><i data-lucide="calendar-plus" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['bookings_today']; ?></div>
                        <div class="stat-label">Bookings Created</div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-sidebar">
                <form method="GET" action="system_logs.php">
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; align-items: end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Action Type</label>
                            <select name="action" class="form-control">
                                <option value="">All Actions</option>
                                <?php while ($action = $actions->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($action['action_type']); ?>"
                                        <?php echo $action_filter === $action['action_type'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action['action_type']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Show</label>
                            <select name="limit" class="form-control">
                                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100 records</option>
                                <option value="500" <?php echo $limit === 500 ? 'selected' : ''; ?>>500 records</option>
                                <option value="1000" <?php echo $limit === 1000 ? 'selected' : ''; ?>>1000 records</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn">Filter</button>
                            <a href="system_logs.php" class="btn btn-outline">Clear</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Logs as Card List -->
            <div class="section-heading">Activity Log (Last <?php echo $limit; ?> records)</div>
            <div class="card-list">
                <?php if ($logs->num_rows > 0): ?>
                <?php while ($log = $logs->fetch_assoc()): ?>
                <div class="card-row">
                    <div class="card-row-left">
                        <div class="card-row-icon <?php 
                            echo strpos($log['action_type'], 'SUCCESS') !== false || strpos($log['action_type'], 'APPROVED') !== false ? 'green' : 
                                 (strpos($log['action_type'], 'FAILED') !== false || strpos($log['action_type'], 'BLOCKED') !== false ? 'red' : 
                                  'blue'); 
                        ?>">
                            <i data-lucide="<?php 
                                echo strpos($log['action_type'], 'LOGIN') !== false ? 'log-in' : 
                                     (strpos($log['action_type'], 'BOOKING') !== false ? 'calendar' : 
                                      (strpos($log['action_type'], 'ROOM') !== false ? 'door-open' : 'shield')); 
                            ?>" style="width:20px;height:20px"></i>
                        </div>
                        <div class="card-row-info">
                            <h3>
                                <span class="<?php 
                                    echo strpos($log['action_type'], 'SUCCESS') !== false ? 'action-success' : 
                                         (strpos($log['action_type'], 'FAILED') !== false || strpos($log['action_type'], 'BLOCKED') !== false ? 'action-failed' : 
                                          'action-warning'); 
                                ?>"><?php echo htmlspecialchars($log['action_type']); ?></span>
                            </h3>
                            <div class="card-row-meta">
                                <span><i data-lucide="user" style="width:12px;height:12px"></i> 
                                    <?php echo $log['user_name'] ? htmlspecialchars($log['user_name']) : '<em>System</em>'; ?>
                                </span>
                                <span><i data-lucide="clock" style="width:12px;height:12px"></i> <?php echo date('M j, g:i:s A', strtotime($log['created_at'])); ?></span>
                                <?php if ($log['ip_address']): ?>
                                <span><i data-lucide="globe" style="width:12px;height:12px"></i> <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                <?php endif; ?>
                                <?php if ($log['table_affected']): ?>
                                <span><i data-lucide="database" style="width:12px;height:12px"></i> <?php echo htmlspecialchars($log['table_affected']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($log['description']): ?>
                            <div class="card-row-purpose"><?php echo htmlspecialchars($log['description']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-row-right">
                        <?php if ($log['record_id']): ?>
                        <span class="status-badge" style="background:var(--muted-bg);color:var(--muted);">ID: <?php echo $log['record_id']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="empty-state">No log entries found matching the filters.</div>
                <?php endif; ?>
            </div>
            
            <div class="log-note">
                <strong>Note:</strong> System logs are automatically recorded for all user actions including logins, 
                bookings, room changes, and administrative actions. They are essential for security auditing and troubleshooting.
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
