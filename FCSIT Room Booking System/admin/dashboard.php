<?php
require_once '../config.php';

// Check if user is admin
if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

// Get dashboard statistics
$stats = [];

// Total counts
$stats['total_users'] = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'];
$stats['total_rooms'] = $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status != 'unavailable'")->fetch_assoc()['count'];
$stats['total_bookings'] = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$stats['total_facilities'] = $conn->query("SELECT COUNT(*) as count FROM facilities")->fetch_assoc()['count'];

// Pending items
$stats['pending_bookings'] = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'")->fetch_assoc()['count'];
$stats['pending_reports'] = $conn->query("SELECT COUNT(*) as count FROM maintenance_reports WHERE status = 'pending'")->fetch_assoc()['count'];

// Today's bookings
$stats['today_bookings'] = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE booking_date = CURDATE() AND status = 'approved'")->fetch_assoc()['count'];

// Rooms in maintenance
$stats['maintenance_rooms'] = $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'maintenance'")->fetch_assoc()['count'];

// Recent bookings
$recent_bookings_query = "SELECT b.*, u.name as user_name, r.room_name 
                          FROM bookings b
                          JOIN users u ON b.user_id = u.user_id
                          JOIN rooms r ON b.room_id = r.room_id
                          ORDER BY b.created_at DESC
                          LIMIT 5";
$recent_bookings = $conn->query($recent_bookings_query);

// Pending booking requests
$pending_query = "SELECT b.*, u.name as user_name, u.email, r.room_name 
                  FROM bookings b
                  JOIN users u ON b.user_id = u.user_id
                  JOIN rooms r ON b.room_id = r.room_id
                  WHERE b.status = 'pending'
                  ORDER BY b.created_at ASC
                  LIMIT 10";
$pending_bookings = $conn->query($pending_query);

// Recent maintenance reports
$recent_reports_query = "SELECT mr.*, r.room_name, u.name as reporter_name
                         FROM maintenance_reports mr
                         JOIN rooms r ON mr.room_id = r.room_id
                         JOIN users u ON mr.reported_by = u.user_id
                         WHERE mr.status != 'closed'
                         ORDER BY mr.urgency DESC, mr.reported_at DESC
                         LIMIT 5";
$recent_reports = $conn->query($recent_reports_query);

// Room utilization (this week)
$utilization_query = "SELECT 
                      COUNT(*) as booking_count,
                      COUNT(DISTINCT room_id) as rooms_used,
                      (SELECT COUNT(*) FROM rooms WHERE status = 'available') as total_available
                      FROM bookings 
                      WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      AND status = 'approved'";
$utilization = $conn->query($utilization_query)->fetch_assoc();
$utilization_rate = $utilization['total_available'] > 0 ? 
                    round(($utilization['rooms_used'] / $utilization['total_available']) * 100) : 0;

// User breakdown
$user_breakdown = "SELECT 
                   role,
                   COUNT(*) as count
                   FROM users
                   WHERE status = 'active'
                   GROUP BY role";
$user_stats = $conn->query($user_breakdown);

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
    <title>Admin Dashboard - FCSIT Room Booking</title>
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
                <li class="active"><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> <span>Dashboard</span></a></li>
                <li><a href="manage_bookings.php"><i data-lucide="calendar-check"></i> <span>Manage Bookings</span>
                    <?php if ($stats['pending_bookings'] > 0): ?>
                    <span class="sidebar-badge"><?php echo $stats['pending_bookings']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="manage_rooms.php"><i data-lucide="door-open"></i> <span>Manage Rooms</span></a></li>
                <li><a href="manage_users.php"><i data-lucide="users"></i> <span>Manage Users</span></a></li>
                <li><a href="maintenance_reports.php"><i data-lucide="wrench"></i> <span>Maintenance</span>
                    <?php if ($stats['pending_reports'] > 0): ?>
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
                        <h2>Dashboard</h2>
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
            <div class="dash-header">
                <h1>Admin Dashboard</h1>
                <p>Welcome back, <?php echo explode(' ', $_SESSION['name'])[0]; ?>! Here's your system overview.</p>
            </div>
            
            <?php
            if (isset($_SESSION['success'])) {
                echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
                unset($_SESSION['success']);
            }
            ?>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Pending Bookings</div>
                            <div class="stat-value"><?php echo $stats['pending_bookings']; ?></div>
                            <div class="stat-sub">Awaiting approval</div>
                        </div>
                        <div class="stat-icon purple"><i data-lucide="clock" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Today's Bookings</div>
                            <div class="stat-value"><?php echo $stats['today_bookings']; ?></div>
                            <div class="stat-sub">Approved for today</div>
                        </div>
                        <div class="stat-icon pink"><i data-lucide="calendar-days" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Total Users</div>
                            <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                            <div class="stat-sub">Active accounts</div>
                        </div>
                        <div class="stat-icon blue"><i data-lucide="users" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Available Rooms</div>
                            <div class="stat-value"><?php echo $stats['total_rooms']; ?></div>
                            <div class="stat-sub">Out of <?php echo $stats['total_rooms'] + $stats['maintenance_rooms']; ?> rooms</div>
                        </div>
                        <div class="stat-icon green"><i data-lucide="door-open" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="section-label">Quick Actions</div>
            <div class="quick-actions-grid">
                <a href="manage_bookings.php?filter=pending" class="qa-btn">
                    <div class="qa-icon purple"><i data-lucide="clipboard-list" style="width:20px;height:20px;"></i></div>
                    Review Pending
                </a>
                <a href="add_room.php" class="qa-btn">
                    <div class="qa-icon green"><i data-lucide="plus" style="width:20px;height:20px;"></i></div>
                    Add New Room
                </a>
                <a href="add_admin.php" class="qa-btn">
                    <div class="qa-icon blue"><i data-lucide="user-plus" style="width:20px;height:20px;"></i></div>
                    Add New Admin
                </a>
                <a href="maintenance_reports.php?filter=pending" class="qa-btn">
                    <div class="qa-icon red"><i data-lucide="wrench" style="width:20px;height:20px;"></i></div>
                    Review Reports
                </a>
                <a href="analytics.php" class="qa-btn">
                    <div class="qa-icon orange"><i data-lucide="bar-chart-3" style="width:20px;height:20px;"></i></div>
                    View Analytics
                </a>
            </div>
            
            <!-- Main Grid: Left + Right -->
            <div class="main-grid">
                <!-- Left Column -->
                <div>
                    <!-- Pending Booking Requests -->
                    <div class="dash-card" style="margin-bottom: 20px;">
                        <div class="dash-card-header">
                            <h3>Pending Booking Requests</h3>
                            <a href="manage_bookings.php?filter=pending" class="view-all-link">
                                View All <i data-lucide="arrow-right" style="width:14px;height:14px;"></i>
                            </a>
                        </div>
                        <div class="dash-card-body" style="padding: 0;">
                            <?php if ($pending_bookings->num_rows > 0): ?>
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Room</th>
                                        <th>Date & Time</th>
                                        <th>Submitted</th>
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($booking = $pending_bookings->fetch_assoc()): ?>
                                    <tr>
                                        <td class="user-cell">
                                            <strong><?php echo htmlspecialchars($booking['user_name']); ?></strong>
                                            <small><?php echo htmlspecialchars($booking['email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($booking['room_name']); ?></td>
                                        <td class="time-cell">
                                            <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?><br>
                                            <small><?php echo date('g:i A', strtotime($booking['start_time'])) . ' - ' . 
                                                         date('g:i A', strtotime($booking['end_time'])); ?></small>
                                        </td>
                                        <td style="color: var(--text-light); font-size: 0.85em;"><?php echo date('M j, g:i A', strtotime($booking['created_at'])); ?></td>
                                        <td style="text-align:right;">
                                            <a href="manage_bookings.php?id=<?php echo $booking['booking_id']; ?>" class="btn-review">
                                                <i data-lucide="eye" style="width:13px;height:13px;"></i> Review
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div class="empty-state" style="border:none;">
                                <i data-lucide="inbox" style="width:32px;height:32px;opacity:0.3;margin-bottom:8px;"></i>
                                <p>No pending booking requests</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <h3>Recent Activity</h3>
                        </div>
                        <div class="dash-card-body">
                            <?php while ($booking = $recent_bookings->fetch_assoc()): ?>
                            <div class="activity-row">
                                <div class="activity-left">
                                    <div class="activity-icon">
                                        <i data-lucide="activity" style="width:16px;height:16px;"></i>
                                    </div>
                                    <div class="activity-text">
                                        <strong><?php echo htmlspecialchars($booking['user_name']); ?></strong>
                                        <span><?php echo htmlspecialchars($booking['room_name']); ?> &middot; <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?></span>
                                    </div>
                                </div>
                                <span class="badge badge-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="sidebar-stack">
                    <!-- Room Utilization -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <h3>
                                <span class="card-title-icon">
                                    <i data-lucide="pie-chart" style="width:16px;height:16px;color:var(--primary);"></i>
                                    Room Utilization
                                </span>
                            </h3>
                            <span style="font-size:0.75em;color:var(--text-light);">This week</span>
                        </div>
                        <div class="dash-card-body">
                            <div class="util-center">
                                <div class="util-value"><?php echo $utilization_rate; ?>%</div>
                                <div class="util-sub"><?php echo $utilization['rooms_used']; ?> of <?php echo $utilization['total_available']; ?> rooms used</div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $utilization_rate; ?>%;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Distribution -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <h3>
                                <span class="card-title-icon">
                                    <i data-lucide="users" style="width:16px;height:16px;color:var(--primary);"></i>
                                    User Distribution
                                </span>
                            </h3>
                        </div>
                        <div class="dash-card-body">
                            <?php while ($user_stat = $user_stats->fetch_assoc()): ?>
                            <div class="dist-row">
                                <span class="dist-role"><?php echo ucfirst($user_stat['role']); ?>s</span>
                                <span class="dist-count"><?php echo $user_stat['count']; ?></span>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    
                    <!-- Urgent Maintenance -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <h3>
                                <span class="card-title-icon">
                                    <i data-lucide="alert-triangle" style="width:16px;height:16px;color:var(--danger);"></i>
                                    Urgent Maintenance
                                </span>
                            </h3>
                        </div>
                        <div class="dash-card-body">
                            <?php if ($recent_reports->num_rows > 0): ?>
                            <?php while ($report = $recent_reports->fetch_assoc()): ?>
                            <div class="maint-item <?php echo $report['urgency']; ?>">
                                <span class="badge badge-<?php echo $report['urgency']; ?>">
                                    <?php echo ucfirst($report['urgency']); ?>
                                </span>
                                <div class="maint-room"><?php echo htmlspecialchars($report['room_name']); ?></div>
                                <div class="maint-time"><?php echo date('M j, g:i A', strtotime($report['reported_at'])); ?></div>
                            </div>
                            <?php endwhile; ?>
                            <a href="maintenance_reports.php" class="btn-outline-full">
                                View All Reports
                            </a>
                            <?php else: ?>
                            <div class="empty-state" style="padding:20px;border:none;">
                                <p>No urgent reports</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
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
