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

$room_id = intval($_GET['id'] ?? 0);

if ($room_id === 0) {
    redirect('manage_rooms.php');
}

// Get room details
$query = "SELECT * FROM rooms WHERE room_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $room_id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

if (!$room) {
    redirect('manage_rooms.php');
}

// Get facilities
$fac_query = "SELECT f.*, rf.quantity, rf.condition_status 
              FROM facilities f
              JOIN room_facilities rf ON f.facility_id = rf.facility_id
              WHERE rf.room_id = ?
              ORDER BY f.facility_type, f.facility_name";
$fac_stmt = $conn->prepare($fac_query);
$fac_stmt->bind_param("i", $room_id);
$fac_stmt->execute();
$facilities = $fac_stmt->get_result();

// Get recent bookings for this room
$booking_query = "SELECT b.*, u.name 
                  FROM bookings b
                  JOIN users u ON b.user_id = u.user_id
                  WHERE b.room_id = ? 
                  AND b.status = 'approved'
                  AND b.booking_date >= CURDATE()
                  ORDER BY b.booking_date, b.start_time
                  LIMIT 5";
$booking_stmt = $conn->prepare($booking_query);
$booking_stmt->bind_param("i", $room_id);
$booking_stmt->execute();
$upcoming_bookings = $booking_stmt->get_result();

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $_SESSION['user_id']);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($room['room_name']); ?> - Admin Room Details</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h3>FCSIT</h3>
                <p>
                    Room Booking Administrative System
                    <br><i data-lucide="user" style="width:13px;height:13px"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                </p>
            </div>
            <ul class="list-unstyled components">
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> <span>Dashboard</span></a></li>
                <li><a href="manage_bookings.php"><i data-lucide="calendar-check"></i> <span>Manage Bookings</span>
                    <?php if ($sidebar_stats['pending_bookings'] > 0): ?>
                    <span class="sidebar-badge"><?php echo $sidebar_stats['pending_bookings']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li class="active"><a href="manage_rooms.php"><i data-lucide="door-open"></i> <span>Manage Rooms</span></a></li>
                <li><a href="manage_users.php"><i data-lucide="users"></i> <span>Manage Users</span></a></li>
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
            <!-- Header -->
            <div class="top-header">
                <div class="header-left">
                    <div class="toggle-sidebar" id="toggleSidebar">
                        <i data-lucide="menu"></i>
                    </div>
                    <div class="page-title">
                        <h2>Room Details</h2>
                        <p><?php echo htmlspecialchars($room['room_name']); ?></p>
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
                                $notif_list_stmt->bind_param("i", $_SESSION['user_id']);
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
            <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                <a href="manage_rooms.php" class="btn btn-outline">
                    <i data-lucide="arrow-left"></i> Back to Manage Rooms</a>
            </div>
            
            <div class="room-details">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                    <h2><?php echo htmlspecialchars($room['room_name']); ?></h2>
                    <span class="status-badge status-<?php echo $room['status']; ?>"><?php echo ucfirst($room['status']); ?></span>
                </div>

                <?php if ($room['image_url']): ?>
                <div style="margin: 20px 0; text-align: center;">
                    <img src="../<?php echo htmlspecialchars($room['image_url']); ?>" 
                         alt="<?php echo htmlspecialchars($room['room_name']); ?>"
                         style="max-width: 100%; max-height: 400px; border-radius: var(--radius); box-shadow: var(--shadow-md); object-fit: cover;">
                </div>
                <?php endif; ?>
                
                <div class="stats-grid" style="margin: 20px 0; grid-template-columns: repeat(4, 1fr);">
                    <div class="stat-card">
                        <div class="stat-card-inner">
                            <div>
                                <div class="stat-label">Room Type</div>
                                <div class="stat-value" style="font-size:1.2em;"><?php echo ucwords(str_replace('_', ' ', $room['room_type'])); ?></div>
                            </div>
                            <div class="stat-icon purple"><i data-lucide="door-open"></i></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-inner">
                            <div>
                                <div class="stat-label">Capacity</div>
                                <div class="stat-value"><?php echo $room['capacity']; ?></div>
                                <div class="stat-sub">people</div>
                            </div>
                            <div class="stat-icon blue"><i data-lucide="users"></i></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-inner">
                            <div>
                                <div class="stat-label">Location</div>
                                <div class="stat-value" style="font-size:1.2em;"><?php echo htmlspecialchars($room['building']); ?></div>
                                <div class="stat-sub">Floor <?php echo $room['floor']; ?></div>
                            </div>
                            <div class="stat-icon green"><i data-lucide="map-pin"></i></div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-inner">
                            <div>
                                <div class="stat-label">Status</div>
                                <div class="stat-value" style="font-size:1.2em;color:<?php echo $room['status'] === 'available' ? 'var(--success)' : 'var(--danger)'; ?>">
                                    <?php echo ucfirst($room['status']); ?>
                                </div>
                            </div>
                            <div class="stat-icon <?php echo $room['status'] === 'available' ? 'green' : 'red'; ?>">
                                <i data-lucide="<?php echo $room['status'] === 'available' ? 'check-circle' : 'x-circle'; ?>"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($room['description']): ?>
                <div class="room-info-panel" style="margin-bottom:20px;">
                    <h4><i data-lucide="file-text"></i> Description</h4>
                    <p><?php echo nl2br(htmlspecialchars($room['description'])); ?></p>
                </div>
                <?php endif; ?>
                
                <div class="section-label"><i data-lucide="settings"></i> Available Facilities</div>
                <?php if ($facilities->num_rows > 0): ?>
                <div class="facility-list">
                    <?php while ($fac = $facilities->fetch_assoc()): ?>
                    <div class="facility-item">
                        <strong><?php echo htmlspecialchars($fac['facility_name']); ?></strong>
                        <p style="margin: 5px 0; font-size: 0.85em; color: var(--text-secondary);">
                            Qty: <?php echo $fac['quantity']; ?> · 
                            Condition: <span style="color: <?php 
                                echo $fac['condition_status'] === 'good' ? 'var(--success)' : 
                                     ($fac['condition_status'] === 'fair' ? 'var(--warning)' : 'var(--danger)'); 
                            ?>;font-weight:600;">
                                <?php echo ucfirst($fac['condition_status']); ?>
                            </span>
                        </p>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <p style="color: var(--text-light); font-size:0.9em;">No facilities listed for this room.</p>
                <?php endif; ?>
                
                <div class="section-label" style="margin-top:24px;"><i data-lucide="calendar"></i> Upcoming Bookings</div>
                <?php if ($upcoming_bookings->num_rows > 0): ?>
                <div class="card-list">
                    <?php while ($booking = $upcoming_bookings->fetch_assoc()): ?>
                    <div class="card-row">
                        <div class="card-row-left">
                            <div class="card-row-icon blue">
                                <i data-lucide="calendar-check"></i>
                            </div>
                            <div class="card-row-info">
                                <h3><?php echo date('D, M j, Y', strtotime($booking['booking_date'])); ?></h3>
                                <div class="card-row-meta">
                                    <span><i data-lucide="clock"></i> <?php echo date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time'])); ?></span>
                                    <span><i data-lucide="user"></i> <?php echo htmlspecialchars($booking['name']); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="card-row-right">
                            <a href="manage_bookings.php?booking_id=<?php echo $booking['booking_id']; ?>" class="btn-icon" title="View Booking">
                                <i data-lucide="eye"></i>
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <p style="color: var(--text-light); font-size:0.9em;">No upcoming bookings for this room.</p>
                <?php endif; ?>
                
                <div style="margin-top: 28px; display: flex; gap: 12px; justify-content: center;">
                    <a href="edit_room.php?id=<?php echo $room_id; ?>" class="btn">
                        <i data-lucide="pencil"></i> Edit Room
                    </a>
                    <a href="manage_rooms.php" class="btn btn-outline">
                        <i data-lucide="arrow-left"></i> Back to List
                    </a>
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
            fetch('../actions/mark_notification_read.php', {
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
            fetch('../actions/mark_all_notifications_read.php', {method: 'POST'})
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